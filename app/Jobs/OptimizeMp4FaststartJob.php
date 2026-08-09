<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Queue\Middleware\ConcurrencyPool;
use App\Services\FfmpegProcessRunner;
use App\Services\InsufficientDiskSpaceException;
use App\Services\LocalDiskSpaceGuard;
use App\Services\MediaBinaryDetector;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Services\ProcessingLiveness;
use App\Services\PipelineStateService;
use App\Services\ProcessingStateInspector;
use App\Services\VideoProbeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OptimizeMp4FaststartJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public int $uniqueFor = 28800;

    public function __construct(public int $sourceId, public ?string $attemptId = null)
    {
        // Zero disables Laravel's SIGALRM for long-running, healthy encodes.
        // The runner records PID/heartbeats/output growth instead of treating
        // elapsed wall time as evidence of a failed 1–3 GB media job.
        $this->timeout = max(0, (int) config('cdn.ffmpeg_job_timeout_seconds', 0));
    }

    public function uniqueId(): string
    {
        return 'optimization:faststart:'.$this->sourceId.':'.($this->attemptId ?: 'legacy');
    }

    /**
     * Bound how long a lock-blocked dispatch keeps retrying, independent of
     * $tries (which stays at 1 — a single real execution attempt). Without
     * this, a job that can never acquire its lock would requeue forever.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        $locks = [
            // releaseAfter (not dontRelease): if a worker crashes mid-run and
            // leaves this lock held, a scheduled/manual retry dispatch must be
            // requeued to try again later — not silently dropped. dontRelease()
            // previously caused exactly that: every automated and manual retry
            // attempt no-op'd until the multi-hour expireAfter lock happened to
            // clear on its own, which is what made large-file jobs appear to
            // need several manual reprocess attempts before one "stuck".
            (new WithoutOverlapping('optimization:source:'.$this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200)))
                ->releaseAfter(300),
        ];

        if ((bool) config('cdn.serialize_optimization_jobs', true)) {
            // Which tier this job competes for is decided from the request
            // (compress_enabled), not the eventual outcome — probing hasn't
            // run yet at middleware time, so "will this actually transcode"
            // isn't known until handle() runs. compress_enabled=true is
            // still the right approximation: it's the only case that can
            // become a full CPU-bound encode.
            $compressRequested = (bool) MediaSource::query()->whereKey($this->sourceId)->value('compress_enabled');
            $lockSeconds = max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200));
            $locks[] = $compressRequested
                ? new ConcurrencyPool('transcode', max(1, (int) config('cdn.transcode_concurrency', 1)), $lockSeconds)
                : new ConcurrencyPool('remux', max(1, (int) config('cdn.remux_concurrency', 3)), $lockSeconds);
        }

        return $locks;
    }

    public function handle(): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready') {
            return;
        }
        if ($this->attemptId !== null && $source->processing_attempt_id !== $this->attemptId) {
            Log::info('Ignoring superseded optimization attempt', [
                'source_id' => $this->sourceId,
                'job_attempt_id' => $this->attemptId,
                'current_attempt_id' => $source->processing_attempt_id,
            ]);

            return;
        }

        ProcessingLiveness::touch($source->id);
        $this->handleAttempt($source);
    }

    private function handleAttempt(MediaSource $source): void
    {
        $source = app(MediaSourceService::class)->ensureLocalWorkFileForProcessing($source) ?: $source;
        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        if (! $source->storage_path || ! Storage::disk($disk)->exists($source->storage_path)) {
            app(PipelineStateService::class)->markOptimizationFailed(
                $source,
                'input_unavailable',
                'Original media file was not found for faststart optimization.',
            );

            return;
        }

        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg) {
            app(PipelineStateService::class)->markOptimizationFailed(
                $source,
                'ffmpeg_unavailable',
                'FFmpeg binary was not found. Set FFMPEG_BIN or install ffmpeg in Docker/server.',
            );

            return;
        }

        $source->update([
            'optimize_status' => 'processing',
            'optimize_error' => null,
            'processing_stage' => 'probing',
            'processing_stage_progress' => 0,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => null,
            'progress_percent' => 5,
            'last_progress_at' => now(),
        ]);
        $originalPath = $source->storage_path;
        $absoluteInput = Storage::disk($disk)->path($originalPath);
        // Capture this before FFmpeg starts. The Telegram/import cleanup
        // lifecycle may remove the work input after FFmpeg has opened it.
        $inputSize = $this->safeLocalFileSize($absoluteInput, (int) ($source->file_size_bytes ?? 0));
        $optimizedPath = $this->buildOptimizedPath($source, $originalPath);
        $absoluteOutput = Storage::disk($disk)->path($optimizedPath);
        Storage::disk($disk)->makeDirectory(dirname($optimizedPath));

        if ($inputSize > 0) {
            try {
                app(LocalDiskSpaceGuard::class)->ensureAvailable(
                    $absoluteOutput,
                    app(LocalDiskSpaceGuard::class)->estimateTranscodeOutputBytes($inputSize),
                    "faststart/compress output for source #{$source->id}"
                );
            } catch (InsufficientDiskSpaceException $spaceError) {
                $this->failOptimization($source, $spaceError->getMessage());

                return;
            }
        }

        $processingStarted = microtime(true);

        $metadata = (array) ($source->source_metadata ?? []);
        $probe = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : [];
        if ($probe === []) {
            $probe = app(VideoProbeService::class)->probe($absoluteInput);
            $metadata['probe'] = $probe;
            $source->update(['source_metadata' => $metadata]);
        }
        if ($probe === [] || ! ($probe['has_video'] ?? false)) {
            $this->failOptimization($source, 'The input could not be probed as a complete video file.');

            return;
        }

        $shouldCompress = $this->shouldCompress($source);
        $duration = (float) ($probe['processing_duration'] ?? $probe['duration'] ?? $probe['duration_seconds'] ?? 0);
        $maxHeight = $this->requestedMaxHeight($source, $probe);
        $needsDownscale = $maxHeight > 0 && (int) ($probe['height'] ?? 0) > $maxHeight;
        Log::info('NBX resolution resolved for optimization', [
            'source_id' => $source->id,
            'requested_resolution' => data_get($source->source_metadata, 'nbx.requested.max_resolution'),
            'source_profile_resolution' => data_get($source->source_metadata, 'nbx.requested.source_profile_resolution'),
            'nbx_default_resolution' => config('nbx.default_resolution'),
            'effective_resolution' => $maxHeight ?: null,
            'input_resolution' => $this->resolutionLabel($probe),
            'will_downscale' => $needsDownscale,
        ]);
        $videoCompatible = in_array(strtolower((string) ($probe['video_codec'] ?? '')), ['h264', 'avc1'], true)
            && in_array(strtolower((string) ($probe['pixel_format'] ?? 'yuv420p')), ['yuv420p', 'yuvj420p'], true);
        $hasAudio = (bool) ($probe['has_audio'] ?? ! empty($probe['audio_codec']));
        $audioCompatible = ! $hasAudio || strtolower((string) ($probe['audio_codec'] ?? '')) === 'aac';
        $alreadyEfficient = $shouldCompress
            && $videoCompatible
            && $audioCompatible
            && ! $needsDownscale
            && $this->isAlreadyEfficient($probe);
        $stage = $shouldCompress && ! $alreadyEfficient ? 'compressing' : 'faststarting';
        $source->update([
            'processing_stage' => $stage,
            'processing_stage_progress' => 0,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 10,
            'last_progress_at' => now(),
        ]);
        $this->markNbxStatusIfManaged($source, $stage);
        app(PipelineStateService::class)->markOptimizationActive($source->fresh() ?? $source, $stage);

        if ((! $shouldCompress || $alreadyEfficient) && ! $needsDownscale && $videoCompatible && $audioCompatible) {
            $processingMode = $alreadyEfficient ? 'remux_already_efficient' : 'remux';
            [$exitCode, $rawError] = $this->runFaststartCopy($ffmpeg, $absoluteInput, $absoluteOutput, $source, $duration);
        } elseif (! $shouldCompress && ! $needsDownscale && $videoCompatible) {
            $processingMode = 'audio_transcode';
            [$exitCode, $rawError] = $this->runAudioTranscode($ffmpeg, $absoluteInput, $absoluteOutput, $source, $duration);
        } else {
            $processingMode = 'video_transcode';
            [$exitCode, $rawError] = $this->runCompressionTranscode(
                $ffmpeg,
                $absoluteInput,
                $absoluteOutput,
                $source,
                $needsDownscale ? $maxHeight : 0,
                $duration,
                $probe,
            );
        }

        if ($exitCode === FfmpegProcessRunner::SUPERSEDED_EXIT_CODE) {
            // A newer attempt has already taken over this source (see
            // FfmpegProcessRunner::heartbeat()); this attempt's work is
            // moot, and it must not write any status for a row it no
            // longer owns.
            if (is_file($absoluteOutput)) {
                @unlink($absoluteOutput);
            }

            return;
        }

        if ($exitCode !== 0 || $this->safeLocalFileSize($absoluteOutput) <= 0) {
            $optimizeError = $this->summarizeFfmpegError($rawError);
            Log::warning('Faststart optimization failed', [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'exit_code' => $exitCode,
                'error' => $rawError,
            ]);

            if (is_file($absoluteOutput)) {
                @unlink($absoluteOutput);
            }

            if (! $this->currentAttemptOwnsSource($source)) {
                return;
            }
            app(PipelineStateService::class)->markOptimizationFailed(
                $source,
                $stage,
                $optimizeError !== '' ? $optimizeError : 'FFmpeg faststart optimization failed.',
                ['diagnostics' => $rawError],
            );

            return;
        }

        $source->update([
            'processing_stage' => 'validating',
            'processing_stage_progress' => 0,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 80,
            'last_progress_at' => now(),
        ]);
        ProcessingLiveness::touch($source->id);
        $outputProbe = app(VideoProbeService::class)->probe($absoluteOutput);
        $durationValidation = $this->durationValidation($probe, $outputProbe);
        $verificationError = $this->outputVerificationError($probe, $outputProbe, $absoluteOutput, $durationValidation);
        if ($verificationError !== null) {
            @unlink($absoluteOutput);
            if (! $this->currentAttemptOwnsSource($source)) {
                return;
            }
            app(PipelineStateService::class)->markOptimizationFailed($source, 'validation', $verificationError, [
                'diagnostics' => $verificationError,
                'duration_validation' => $durationValidation,
                'input_probe' => $probe,
                'output_probe' => $outputProbe,
            ]);

            return;
        }

        $optimizedSize = $this->safeLocalFileSize($absoluteOutput);
        if (
            $processingMode === 'video_transcode'
            && $optimizedSize >= $inputSize
            && $videoCompatible
            && $audioCompatible
            && ! $needsDownscale
            && is_file($absoluteInput)
        ) {
            $fallbackOutput = $absoluteOutput.'.fallback.mp4';
            [$fallbackExit, $fallbackError] = $this->runFaststartCopy($ffmpeg, $absoluteInput, $fallbackOutput, $source, $duration);
            if ($fallbackExit === 0 && $this->safeLocalFileSize($fallbackOutput) > 0) {
                $fallbackProbe = app(VideoProbeService::class)->probe($fallbackOutput);
                if ($this->outputVerificationError($probe, $fallbackProbe, $fallbackOutput, $this->durationValidation($probe, $fallbackProbe)) === null) {
                    @unlink($absoluteOutput);
                    @rename($fallbackOutput, $absoluteOutput);
                    $processingMode = 'remux_fallback_smaller';
                    $outputProbe = $fallbackProbe;
                    $optimizedSize = $this->safeLocalFileSize($absoluteOutput);
                }
            } else {
                $rawError .= "\nCompression fallback failed: ".$fallbackError;
            }
            if (is_file($fallbackOutput)) {
                @unlink($fallbackOutput);
            }
        }

        $nbxService = app(NbxEngineService::class);
        $nbxMetadata = (array) ($source->source_metadata ?? []);
        $wasNbxManaged = ($nbxMetadata['provider'] ?? null) === 'nbx_engine' || isset($nbxMetadata['nbx']);
        $requested = (array) ($nbxMetadata['nbx']['requested'] ?? []);
        // Never destroy the only original at the encode boundary. For final
        // object storage targets it is removed only after the replacement has
        // been uploaded, byte-verified and has a public URL; local targets keep
        // it until an explicit cleanup policy can make the same proof.
        $deleteLocalOriginal = false;
        $deletedOriginal = $this->maybeDeleteOriginalAfterOptimization(
            $source,
            $disk,
            $originalPath,
            $optimizedPath,
            $deleteLocalOriginal,
        );
        $originalMissingAfterProcessing = ! Storage::disk($disk)->exists($originalPath);
        $bytesSaved = max(0, $inputSize - $optimizedSize);
        $percentageSaved = $inputSize > 0 ? round(($bytesSaved / $inputSize) * 100, 2) : 0;
        $compressionIneffective = $shouldCompress
            && $processingMode === 'video_transcode'
            && $percentageSaved < max(0.0, (float) config('cdn.compress_ineffective_savings_percent', 5));
        if ($compressionIneffective) {
            Log::warning('NBX compression was technically successful but ineffective', [
                'source_id' => $source->id,
                'input_bytes' => $inputSize,
                'output_bytes' => $optimizedSize,
                'bytes_saved' => $bytesSaved,
                'percentage_saved' => $percentageSaved,
                'input_resolution' => $this->resolutionLabel($probe),
                'output_resolution' => $this->resolutionLabel($outputProbe),
            ]);
        }
        $nbxMetadata['probe_output'] = $outputProbe;
        $processingResult = [
            'processing_mode' => $processingMode,
            'input_bytes' => $inputSize,
            'output_bytes' => $optimizedSize,
            'bytes_saved' => $bytesSaved,
            'percentage_saved' => $percentageSaved,
            'compression_effective' => ! $compressionIneffective,
            'compression_outcome' => $compressionIneffective ? 'ineffective' : 'effective',
            'processing_seconds' => round(microtime(true) - $processingStarted, 2),
            'input_container' => $probe['container'] ?? null,
            'output_container' => $outputProbe['container'] ?? 'mp4',
            'input_duration' => $this->durationSnapshot($probe),
            'output_duration' => $this->durationSnapshot($outputProbe),
            'duration_validation' => $durationValidation,
            'output_video_codec' => $outputProbe['video_codec'] ?? null,
            'output_audio_codec' => $outputProbe['audio_codec'] ?? null,
            'max_resolution' => $requested['max_resolution'] ?? null,
            'effective_resolution' => $maxHeight ?: null,
            'input_resolution' => $this->resolutionLabel($probe),
            'output_resolution' => $this->resolutionLabel($outputProbe),
            'verified' => true,
            'verified_at' => now()->toIso8601String(),
            'original_missing_after_processing' => $originalMissingAfterProcessing,
        ];
        if ($wasNbxManaged) {
            $nbxMetadata['nbx'] = array_merge((array) ($nbxMetadata['nbx'] ?? []), [
                'processing_result' => $processingResult,
            ]);
        } else {
            $nbxMetadata['processing_result'] = $processingResult;
        }
        $updates = [
            'optimize_status' => 'ready',
            'optimized_path' => $optimizedPath,
            'optimize_error' => null,
            'is_faststart' => true,
            'optimized_at' => now(),
            'playback_type' => 'mp4',
            'source_metadata' => $nbxMetadata,
            'processing_stage' => 'publishing',
            'processing_stage_progress' => 0,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => $rawError !== '' ? $rawError : null,
            'progress_percent' => 85,
            'last_progress_at' => now(),
        ];

        if ($deletedOriginal || $originalMissingAfterProcessing) {
            // hash_file() on a large output can run long enough to outlast
            // the liveness TTL on its own; refresh right before it.
            ProcessingLiveness::touch($source->id);
            $updates['storage_path'] = $optimizedPath;
            $updates['mime_type'] = 'video/mp4';
            $updates['file_size_bytes'] = $optimizedSize > 0 ? $optimizedSize : null;
            $updates['checksum'] = hash_file('sha256', $absoluteOutput) ?: null;
            $updates['bytes_downloaded'] = $optimizedSize > 0 ? $optimizedSize : null;
            $updates['bytes_total'] = $optimizedSize > 0 ? $optimizedSize : null;
        }

        // Always preserve original_storage_path on first successful optimization
        // so that future retries know where the true source file was.
        if (! $source->original_storage_path) {
            $updates['original_storage_path'] = $deletedOriginal ? $originalPath : $originalPath;
        }

        if (! $this->updateIfCurrentAttempt($source, $updates)) {
            return;
        }
        $source = $source->fresh() ?? $source;
        $isNbxManaged = ($source->source_metadata['provider'] ?? null) === 'nbx_engine'
            || isset($source->source_metadata['nbx']);
        if ($isNbxManaged) {
            // The Contabo upload can run long on a large file; refresh
            // before handing off so the reaper doesn't race an upload that
            // has no ffmpeg heartbeat of its own.
            ProcessingLiveness::touch($source->id);
            $source = $nbxService->publishAvailableArtifacts($source, ['faststart']);
            if ($source->status !== 'ready') {
                return;
            }
            $source = $nbxService->refreshOutputMetadata($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.faststart.completed', [
                'compressed' => $shouldCompress,
                'processing_mode' => $processingMode,
                'optimized_path' => $optimizedPath,
            ]);
        }

        $source->update([
            'processing_stage' => 'ready',
            'processing_stage_progress' => 100,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 100,
            'last_progress_at' => now(),
        ]);
    }

    private function shouldCompress(MediaSource $source): bool
    {
        return (bool) ($source->compress_enabled ?? config('cdn.compress_before_playback', false));
    }

    /**
     * Copy-only faststart: remux moov atom to start. No re-encode.
     * Use -map 0 -dn for robustness; -loglevel error to reduce noise.
     *
     * @return array{0:int,1:string}
     */
    private function runFaststartCopy(
        string $ffmpeg,
        string $absoluteInput,
        string $absoluteOutput,
        MediaSource $source,
        float $duration,
    ): array {
        $command = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-progress',
            'pipe:2',
            '-stats_period',
            '5',
            '-i',
            $absoluteInput,
            '-map',
            '0:V:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
            '-c:v',
            'copy',
            '-c:a',
            'copy',
            '-movflags',
            '+faststart',
            $absoluteOutput,
        ];

        return app(FfmpegProcessRunner::class)->run($command, $source, $duration, 'faststarting', $absoluteOutput);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runAudioTranscode(
        string $ffmpeg,
        string $absoluteInput,
        string $absoluteOutput,
        MediaSource $source,
        float $duration,
    ): array {
        $command = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-progress',
            'pipe:2',
            '-stats_period',
            '5',
            '-i',
            $absoluteInput,
            '-map',
            '0:V:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
            '-c:v',
            'copy',
            '-c:a',
            'aac',
            '-b:a',
            (string) $this->requestedValue($source, 'audio_bitrate', config('cdn.compress_audio_bitrate', '128k')),
            '-movflags',
            '+faststart',
            $absoluteOutput,
        ];

        return app(FfmpegProcessRunner::class)->run($command, $source, $duration, 'faststarting', $absoluteOutput);
    }

    private function runCompressionTranscode(
        string $ffmpeg,
        string $absoluteInput,
        string $absoluteOutput,
        MediaSource $source,
        int $requestedMaxHeight = 0,
        float $duration = 0,
        array $probe = [],
    ): array {
        $videoCodec = (string) config('cdn.compress_video_codec', 'libx264');
        $audioCodec = (string) config('cdn.compress_audio_codec', 'aac');
        $processingPreset = (string) $this->requestedValue($source, 'processing_preset', 'automatic');
        $profile = $this->compressionProfile($probe, $requestedMaxHeight, $processingPreset);
        $defaultCrf = $processingPreset === 'smaller_720p' ? min(35, $profile['crf'] + 2) : $profile['crf'];
        $crf = max(16, min(35, (int) $this->requestedValue($source, 'crf', $defaultCrf)));
        $audioBitrate = (string) $this->requestedValue($source, 'audio_bitrate', $profile['audio_bitrate']);
        $preset = (string) $this->requestedValue($source, 'encoder_preset', config('cdn.compress_preset', 'medium'));
        $maxHeight = $requestedMaxHeight > 0
            ? $requestedMaxHeight
            : max(0, (int) config('cdn.compress_max_height', 0));

        $parts = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-progress',
            'pipe:2',
            '-stats_period',
            '5',
            '-i',
            $absoluteInput,
            '-map',
            '0:V:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
            '-c:v',
            $videoCodec,
            '-preset',
            $preset,
            '-crf',
            (string) $crf,
            '-maxrate',
            $profile['maxrate'],
            '-bufsize',
            $profile['bufsize'],
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            $audioCodec,
            '-b:a',
            $audioBitrate,
            '-movflags',
            '+faststart',
        ];

        $threads = max(0, min(64, (int) config('cdn.ffmpeg_threads', 0)));
        if ($threads > 0) {
            $parts[] = '-threads';
            $parts[] = (string) $threads;
        }

        // libx264 with yuv420p requires even dimensions. The previous height-
        // only condition left odd source dimensions untouched, producing
        // "Error while opening encoder for output stream 0:0".
        $parts[] = '-vf';
        $parts[] = $maxHeight > 0
            ? "scale={$maxHeight}:{$maxHeight}:force_original_aspect_ratio=decrease:force_divisible_by=2"
            : 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        $parts[] = '-max_muxing_queue_size';
        $parts[] = '4096';

        $parts[] = $absoluteOutput;

        return app(FfmpegProcessRunner::class)->run($parts, $source, $duration, 'compressing', $absoluteOutput);
    }

    private function requestedValue(MediaSource $source, string $key, mixed $fallback): mixed
    {
        $metadata = (array) ($source->source_metadata ?? []);

        $requested = $metadata['nbx']['requested'] ?? [];
        if (! is_array($requested) || ! array_key_exists($key, $requested)) {
            return $fallback;
        }

        $value = $requested[$key];

        return $value === null || $value === '' ? $fallback : $value;
    }

    /**
     * Write only if this job's attempt still owns the row. Prevents a
     * superseded attempt (declared dead by a stale-recovery pass while it
     * was actually still running) from clobbering a fresher attempt's
     * state once it finally reaches a terminal write. Keeps $source in
     * sync with the DB when the write does land.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateIfCurrentAttempt(MediaSource $source, array $attributes): bool
    {
        if ($this->attemptId === null) {
            $source->update($attributes);

            return true;
        }

        $affected = MediaSource::query()
            ->whereKey($source->id)
            ->where('processing_attempt_id', $this->attemptId)
            ->update($attributes);

        if ($affected > 0) {
            $source->forceFill($attributes);

            return true;
        }

        Log::info('Skipped terminal write for superseded optimization attempt', [
            'source_id' => $source->id,
            'attempt_id' => $this->attemptId,
        ]);

        return false;
    }

    private function currentAttemptOwnsSource(MediaSource $source): bool
    {
        return $this->attemptId === null || MediaSource::query()
            ->whereKey($source->id)
            ->where('processing_attempt_id', $this->attemptId)
            ->exists();
    }

    private function markNbxStatusIfManaged(MediaSource $source, string $status, ?string $message = null): void
    {
        $metadata = (array) ($source->source_metadata ?? []);
        if (($metadata['provider'] ?? null) === 'nbx_engine' || isset($metadata['nbx'])) {
            app(NbxEngineService::class)->markNbxStatus($source, $status, $message);
        }
    }

    private function buildOptimizedPath(MediaSource $source, string $storagePath): string
    {
        $baseName = (string) pathinfo($storagePath, PATHINFO_FILENAME);
        $normalized = preg_replace('/_play$/', '', $baseName) ?: $baseName;
        $directory = trim((string) dirname($storagePath), '.');
        $candidate = ltrim($directory.'/'.$normalized.'_play.mp4', '/');

        if ($candidate === $storagePath) {
            $candidate = ltrim($directory.'/'.$normalized.'_play_'.now()->format('YmdHis').'.mp4', '/');
        }

        return $candidate;
    }

    private function maybeDeleteOriginalAfterOptimization(
        MediaSource $source,
        string $disk,
        string $originalPath,
        string $optimizedPath,
        bool $deleteRequested
    ): bool {
        $context = [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'original_path' => $originalPath,
            'optimized_path' => $optimizedPath,
            'delete_requested' => $deleteRequested,
            'original_storage_path' => $source->original_storage_path,
        ];

        // Deletion is driven by the source's explicit retention policy.
        if (! $deleteRequested) {
            Log::info('OptimizeMp4FaststartJob: skip delete – source retention policy keeps the work input until publication', $context);

            return false;
        }

        // Guard 3: never delete the same path we just wrote.
        if ($originalPath === $optimizedPath) {
            Log::warning('OptimizeMp4FaststartJob: skip delete – original and optimized are the same path', $context);

            return false;
        }

        // Guard 4: never delete a file that looks like an already-compressed version (_play suffix).
        // This protects against the retry-loop where storage_path was already updated to _play.mp4.
        $originalBasename = (string) pathinfo($originalPath, PATHINFO_FILENAME);
        if (str_ends_with($originalBasename, '_play') || preg_match('/_play_\d{14}$/', $originalBasename)) {
            Log::warning('OptimizeMp4FaststartJob: SAFETY GUARD – refusing to delete what appears to be an already-compressed file (contains _play suffix); this is likely a retry loop', $context);

            return false;
        }

        // Guard 5: never delete if original_storage_path is set and points to a different file
        // (meaning this file is already a downstream processed version, not the true original).
        $trueOriginal = $source->original_storage_path;
        if ($trueOriginal && $trueOriginal !== $originalPath) {
            Log::warning('OptimizeMp4FaststartJob: SAFETY GUARD – storage_path differs from original_storage_path; refusing to delete to prevent retry-loop data loss', array_merge($context, ['true_original' => $trueOriginal]));

            return false;
        }

        // Guard 6: verify replacement actually exists and has size before deleting original.
        if (! Storage::disk($disk)->exists($optimizedPath)) {
            Log::warning('OptimizeMp4FaststartJob: skip delete – optimized file does not exist on disk', $context);

            return false;
        }
        if (! Storage::disk($disk)->exists($originalPath)) {
            Log::warning('OptimizeMp4FaststartJob: skip delete – original file already gone', $context);

            return false;
        }

        $optimizedSize = Storage::disk($disk)->size($optimizedPath);
        if ($optimizedSize <= 0) {
            Log::warning('OptimizeMp4FaststartJob: skip delete – optimized file is empty or zero-size', array_merge($context, ['optimized_size' => $optimizedSize]));

            return false;
        }

        Log::info('OptimizeMp4FaststartJob: deleting original after verified compression', array_merge($context, [
            'optimized_size_bytes' => $optimizedSize,
            'original_size_bytes' => Storage::disk($disk)->size($originalPath),
        ]));

        $deleted = Storage::disk($disk)->delete($originalPath);

        Log::info('OptimizeMp4FaststartJob: original deletion result', array_merge($context, ['deleted' => $deleted]));

        return $deleted;
    }

    private function requestedMaxHeight(MediaSource $source, array $probe = []): int
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $requested = (array) ($metadata['nbx']['requested'] ?? []);
        $explicit = $this->normalizeResolution($requested['max_resolution'] ?? null);
        $profile = $this->normalizeResolution($requested['source_profile_resolution'] ?? null);
        $fallback = $this->normalizeResolution(config('nbx.default_resolution'))
            ?? max(0, (int) config('cdn.compress_max_height', 0));

        return $explicit ?? $profile ?? $fallback;
    }

    private function isAlreadyEfficient(array $probe): bool
    {
        $bitrate = (int) ($probe['bitrate'] ?? 0);
        $height = (int) ($probe['height'] ?? 0);
        if ($bitrate <= 0 || $height <= 0) {
            return false;
        }

        $ceiling = $height <= 480
            ? (int) config('cdn.compress_skip_bitrate_480p', 900000)
            : ($height <= 720
                ? (int) config('cdn.compress_skip_bitrate_720p', 1500000)
                : (int) config('cdn.compress_skip_bitrate_1080p', 2200000));

        return $bitrate <= max(100000, $ceiling);
    }

    /**
     * @return array{crf:int,maxrate:string,bufsize:string,audio_bitrate:string}
     */
    private function compressionProfile(array $probe, int $requestedMaxHeight, string $processingPreset): array
    {
        $inputHeight = max(0, (int) ($probe['height'] ?? 0));
        $height = $requestedMaxHeight > 0 && ($inputHeight === 0 || $inputHeight > $requestedMaxHeight)
            ? $requestedMaxHeight
            : $inputHeight;
        $audioChannels = max(1, (int) ($probe['audio_channels'] ?? 2));

        if ($height > 0 && $height <= 480) {
            $profile = ['crf' => 24, 'maxrate' => (string) config('cdn.compress_maxrate_480p', '1200k'), 'bufsize' => (string) config('cdn.compress_bufsize_480p', '2400k')];
        } elseif ($height > 0 && $height <= 720) {
            $profile = ['crf' => 23, 'maxrate' => (string) config('cdn.compress_maxrate_720p', '2500k'), 'bufsize' => (string) config('cdn.compress_bufsize_720p', '5000k')];
        } else {
            $profile = ['crf' => 22, 'maxrate' => (string) config('cdn.compress_maxrate_1080p', '4500k'), 'bufsize' => (string) config('cdn.compress_bufsize_1080p', '9000k')];
        }

        // A deployment can explicitly lock CRF through the environment. With
        // no override, use a resolution-aware default instead of silently
        // applying one CRF to every source.
        $configuredCrf = config('cdn.compress_crf');
        if (is_numeric($configuredCrf)) {
            $profile['crf'] = max(16, min(35, (int) $configuredCrf));
        }
        if ($processingPreset === 'smaller_720p') {
            $profile['maxrate'] = (string) config('cdn.compress_smaller_maxrate_720p', '1800k');
            $profile['bufsize'] = (string) config('cdn.compress_smaller_bufsize_720p', '3600k');
        }
        $profile['audio_bitrate'] = $audioChannels <= 1
            ? (string) config('cdn.compress_audio_bitrate_mono', '96k')
            : ($audioChannels > 2
                ? (string) config('cdn.compress_audio_bitrate_surround', '160k')
                : (string) config('cdn.compress_audio_bitrate', '128k'));

        return $profile;
    }

    /**
     * Compare matching timelines first. This deliberately avoids comparing an
     * MP4 output with an MKV format.duration when that container metadata
     * disagrees with the actual primary video stream.
     *
     * @return array<string, mixed>
     */
    private function durationValidation(array $input, array $output): array
    {
        $candidates = [
            'primary_video_stream' => [$input['video_duration'] ?? null, $output['video_duration'] ?? null],
            'longest_primary_av_stream' => [$input['primary_av_duration'] ?? null, $output['primary_av_duration'] ?? null],
            'container_duration' => [
                $input['format_duration'] ?? $input['duration'] ?? null,
                $output['format_duration'] ?? $output['duration'] ?? null,
            ],
        ];

        foreach ($candidates as $basis => [$rawInput, $rawOutput]) {
            $inputDuration = is_numeric($rawInput) ? (float) $rawInput : 0.0;
            $outputDuration = is_numeric($rawOutput) ? (float) $rawOutput : 0.0;
            if ($inputDuration <= 0 || $outputDuration <= 0) {
                continue;
            }

            $difference = abs($inputDuration - $outputDuration);
            $tolerance = max(2.0, $inputDuration * 0.02);

            return [
                'comparable' => true,
                'basis' => $basis,
                'input_duration' => round($inputDuration, 3),
                'output_duration' => round($outputDuration, 3),
                'difference' => round($difference, 3),
                'tolerance' => round($tolerance, 3),
                'valid' => $difference <= $tolerance,
                'input_container_disagrees_with_video' => $this->durationsDisagree($input['format_duration'] ?? $input['duration'] ?? null, $input['video_duration'] ?? null),
                'output_container_disagrees_with_video' => $this->durationsDisagree($output['format_duration'] ?? $output['duration'] ?? null, $output['video_duration'] ?? null),
            ];
        }

        return [
            'comparable' => false,
            'basis' => 'unavailable',
            'valid' => true,
            'reason' => 'No matching positive input/output duration timelines were available from ffprobe.',
        ];
    }

    /** @return array<string, float|null> */
    private function durationSnapshot(array $probe): array
    {
        return [
            'container' => $this->nullableFloat($probe['format_duration'] ?? $probe['duration'] ?? null),
            'video' => $this->nullableFloat($probe['video_duration'] ?? null),
            'audio' => $this->nullableFloat($probe['audio_duration'] ?? null),
            'primary_av' => $this->nullableFloat($probe['primary_av_duration'] ?? null),
        ];
    }

    private function durationsDisagree(mixed $first, mixed $second): bool
    {
        $first = $this->nullableFloat($first);
        $second = $this->nullableFloat($second);
        if ($first === null || $second === null) {
            return false;
        }

        // This is a diagnostic signal, not the pass/fail tolerance. A 20+
        // second Matroska container offset is important evidence even when it
        // falls inside the deliberately conservative 2% validation window.
        return abs($first - $second) > max(2.0, $second * 0.001);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 3) : null;
    }

    private function formatDuration(mixed $value): string
    {
        $float = $this->nullableFloat($value);

        return $float === null ? 'n/a' : number_format($float, 3, '.', '');
    }

    private function resolutionLabel(array $probe): ?string
    {
        $width = (int) ($probe['width'] ?? 0);
        $height = (int) ($probe['height'] ?? 0);

        return $width > 0 && $height > 0 ? "{$width}x{$height}" : null;
    }

    private function normalizeResolution(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = rtrim(strtolower(trim($value)), 'p');
        }
        if (! is_numeric($value)) {
            return null;
        }

        $resolution = (int) $value;

        return in_array($resolution, [240, 360, 480, 720, 1080], true) ? $resolution : null;
    }

    private function outputVerificationError(array $input, array $output, string $absoluteOutput, array $durationValidation = []): ?string
    {
        if ($output === [] || $this->safeLocalFileSize($absoluteOutput) <= 0) {
            return 'Optimized output could not be probed or is empty.';
        }
        if (strtolower((string) pathinfo($absoluteOutput, PATHINFO_EXTENSION)) !== 'mp4') {
            return 'Optimized output filename is not an MP4.';
        }

        if (! in_array(strtolower((string) ($output['video_codec'] ?? '')), ['h264', 'avc1'], true)) {
            return 'Optimized output is not H.264 video.';
        }
        if (($output['has_audio'] ?? ! empty($output['audio_codec']))
            && strtolower((string) ($output['audio_codec'] ?? '')) !== 'aac') {
            return 'Optimized output audio is not AAC.';
        }
        if (! in_array(strtolower((string) ($output['pixel_format'] ?? 'yuv420p')), ['yuv420p', 'yuvj420p'], true)) {
            return 'Optimized output pixel format is not iPhone-compatible yuv420p.';
        }

        $container = strtolower((string) ($output['container'] ?? ''));
        if (! str_contains($container, 'mp4') && ! str_contains($container, 'mov')) {
            return 'Optimized output container is not MP4.';
        }
        if (! $this->hasFastStartAtomOrder($absoluteOutput)) {
            return 'Optimized MP4 is not fast-start compatible (moov atom is missing or follows media data).';
        }

        if (($durationValidation['comparable'] ?? false) && ! ($durationValidation['valid'] ?? true)) {
            return sprintf(
                'Optimization validation failed: duration mismatch using %s (input %ss, output %ss, difference %ss; tolerance %ss). Input container %ss, input video %ss, input audio %ss; output container %ss, output video %ss, output audio %ss.',
                $durationValidation['basis'] ?? 'unknown timeline',
                $this->formatDuration($durationValidation['input_duration'] ?? null),
                $this->formatDuration($durationValidation['output_duration'] ?? null),
                $this->formatDuration($durationValidation['difference'] ?? null),
                $this->formatDuration($durationValidation['tolerance'] ?? null),
                $this->formatDuration($input['format_duration'] ?? $input['duration'] ?? null),
                $this->formatDuration($input['video_duration'] ?? null),
                $this->formatDuration($input['audio_duration'] ?? null),
                $this->formatDuration($output['format_duration'] ?? $output['duration'] ?? null),
                $this->formatDuration($output['video_duration'] ?? null),
                $this->formatDuration($output['audio_duration'] ?? null),
            );
        }

        return null;
    }

    private function safeLocalFileSize(string $absolutePath, int $fallback = 0): int
    {
        if (! is_file($absolutePath)) {
            return max(0, $fallback);
        }

        $size = @filesize($absolutePath);

        return $size === false ? max(0, $fallback) : max(0, (int) $size);
    }

    public function failed(?Throwable $exception): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->optimize_status === 'ready') {
            return;
        }
        if ($this->attemptId !== null && $source->processing_attempt_id !== $this->attemptId) {
            return;
        }

        $health = app(ProcessingStateInspector::class)->inspect($source);
        if ($health['active']) {
            // Queue middleware/HTTP may have given up while its child FFmpeg
            // is still alive. Keep the job truthful and let the running process
            // own the next state transition.
            $source->update([
                'processing_diagnostics' => 'Queue reported a stopped job, but '.($health['message'] ?? 'the media process is still active'),
                'last_progress_at' => now(),
            ]);

            return;
        }

        $message = $exception
            ? 'Optimization worker stopped: '.$exception->getMessage()
            : 'Optimization worker stopped before the video was completed.';
        $this->failOptimization($source, $message);
    }

    private function failOptimization(MediaSource $source, string $message): void
    {
        $bounded = mb_substr($message, -12000);
        app(PipelineStateService::class)->markOptimizationFailed(
            $source,
            (string) ($source->processing_stage ?: 'optimization'),
            $bounded,
            ['diagnostics' => $bounded],
        );
    }

    private function hasFastStartAtomOrder(string $absolutePath): bool
    {
        $handle = @fopen($absolutePath, 'rb');
        if (! is_resource($handle)) {
            return false;
        }

        try {
            $header = fread($handle, 16 * 1024 * 1024);
        } finally {
            fclose($handle);
        }
        if (! is_string($header) || $header === '') {
            return false;
        }

        $moov = strpos($header, 'moov');
        $mdat = strpos($header, 'mdat');

        return $moov !== false && ($mdat === false || $moov < $mdat);
    }

    /**
     * Reduce FFmpeg stderr to a short message for DB/UI (avoid storing full banner).
     * Keeps lines containing moov, Error, Invalid, or the last non-empty line.
     */
    private function summarizeFfmpegError(string $stderr): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stderr)));
        $relevant = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $lower = strtolower($line);
            if (str_contains($lower, 'moov') || str_contains($lower, 'error') || str_contains($lower, 'invalid')
                || str_contains($lower, 'no such file') || str_contains($lower, 'invalid data')) {
                $relevant[] = $line;
            }
        }
        if ($relevant !== []) {
            return implode(' ', array_slice($relevant, -3));
        }
        $last = end($lines);

        return $last !== false ? $last : 'FFmpeg faststart optimization failed.';
    }
}

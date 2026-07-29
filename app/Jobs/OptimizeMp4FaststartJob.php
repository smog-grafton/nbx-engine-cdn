<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\FfmpegProcessRunner;
use App\Services\MediaBinaryDetector;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
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

    public int $timeout = 25200;

    public int $uniqueFor = 28800;

    public function __construct(public int $sourceId, public ?string $attemptId = null)
    {
        $this->timeout = max(600, (int) config('cdn.ffmpeg_timeout_seconds', 21600) + 3600);
    }

    public function uniqueId(): string
    {
        return 'optimization:faststart:'.$this->sourceId.':'.($this->attemptId ?: 'legacy');
    }

    public function middleware(): array
    {
        $locks = [
            (new WithoutOverlapping('optimization:source:'.$this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200)))
                ->dontRelease(),
        ];

        if ((bool) config('cdn.serialize_optimization_jobs', true)) {
            $locks[] = (new WithoutOverlapping('optimization:global'))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200)))
                ->releaseAfter(30);
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

        $source = app(MediaSourceService::class)->ensureLocalWorkFileForProcessing($source) ?: $source;
        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        if (! $source->storage_path || ! Storage::disk($disk)->exists($source->storage_path)) {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => 'Original media file was not found for faststart optimization.',
                'is_faststart' => false,
            ]);
            $this->markNbxStatusIfManaged(
                $source->fresh() ?? $source,
                'failed',
                'Original media file was not found for faststart optimization.'
            );

            return;
        }

        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg) {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => 'FFmpeg binary was not found. Set FFMPEG_BIN or install ffmpeg in Docker/server.',
                'is_faststart' => false,
            ]);
            $this->markNbxStatusIfManaged(
                $source->fresh() ?? $source,
                'failed',
                'FFmpeg binary was not found. Set FFMPEG_BIN or install ffmpeg in Docker/server.'
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
        $duration = (float) ($probe['duration'] ?? $probe['duration_seconds'] ?? 0);
        $maxHeight = $this->requestedMaxHeight($source);
        $needsDownscale = $maxHeight > 0 && (int) ($probe['height'] ?? 0) > $maxHeight;
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
            );
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

            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => $optimizeError !== '' ? $optimizeError : 'FFmpeg faststart optimization failed.',
                'is_faststart' => false,
                'optimized_path' => $originalPath,
                'playback_type' => 'mp4',
                'processing_stage' => 'failed',
                'processing_stage_progress' => null,
                'processing_heartbeat_at' => now(),
                'processing_diagnostics' => $rawError !== '' ? $rawError : null,
            ]);
            $this->markNbxStatusIfManaged(
                $source->fresh() ?? $source,
                'failed',
                $optimizeError !== '' ? $optimizeError : 'FFmpeg faststart optimization failed.'
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
        $outputProbe = app(VideoProbeService::class)->probe($absoluteOutput);
        $verificationError = $this->outputVerificationError($probe, $outputProbe, $absoluteOutput);
        if ($verificationError !== null) {
            @unlink($absoluteOutput);
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => $verificationError,
                'is_faststart' => false,
                'processing_stage' => 'failed',
                'processing_stage_progress' => null,
                'processing_heartbeat_at' => now(),
                'processing_diagnostics' => $verificationError,
            ]);
            $this->markNbxStatusIfManaged($source->fresh() ?? $source, 'failed', $verificationError);

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
                if ($this->outputVerificationError($probe, $fallbackProbe, $fallbackOutput) === null) {
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
        $storageTarget = (string) ($nbxMetadata['nbx']['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $deleteLocalOriginal = $storageTarget !== 'contabo' && ! $nbxService->shouldRetainOriginal($source);
        $deletedOriginal = $this->maybeDeleteOriginalAfterOptimization(
            $source,
            $disk,
            $originalPath,
            $optimizedPath,
            $deleteLocalOriginal,
        );
        $originalMissingAfterProcessing = ! Storage::disk($disk)->exists($originalPath);
        $bytesSaved = max(0, $inputSize - $optimizedSize);
        $nbxMetadata['probe_output'] = $outputProbe;
        $processingResult = [
            'processing_mode' => $processingMode,
            'input_bytes' => $inputSize,
            'output_bytes' => $optimizedSize,
            'bytes_saved' => $bytesSaved,
            'percentage_saved' => $inputSize > 0 ? round(($bytesSaved / $inputSize) * 100, 2) : 0,
            'processing_seconds' => round(microtime(true) - $processingStarted, 2),
            'input_container' => $probe['container'] ?? null,
            'output_container' => $outputProbe['container'] ?? 'mp4',
            'output_video_codec' => $outputProbe['video_codec'] ?? null,
            'output_audio_codec' => $outputProbe['audio_codec'] ?? null,
            'max_resolution' => $requested['max_resolution'] ?? null,
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

        $source->update($updates);
        $source = $source->fresh() ?? $source;
        $isNbxManaged = ($source->source_metadata['provider'] ?? null) === 'nbx_engine'
            || isset($source->source_metadata['nbx']);
        if ($isNbxManaged) {
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

        return app(FfmpegProcessRunner::class)->run($command, $source, $duration, 'faststarting');
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

        return app(FfmpegProcessRunner::class)->run($command, $source, $duration, 'faststarting');
    }

    private function runCompressionTranscode(
        string $ffmpeg,
        string $absoluteInput,
        string $absoluteOutput,
        MediaSource $source,
        int $requestedMaxHeight = 0,
        float $duration = 0,
    ): array {
        $videoCodec = (string) config('cdn.compress_video_codec', 'libx264');
        $audioCodec = (string) config('cdn.compress_audio_codec', 'aac');
        $audioBitrate = (string) $this->requestedValue($source, 'audio_bitrate', config('cdn.compress_audio_bitrate', '128k'));
        $processingPreset = (string) $this->requestedValue($source, 'processing_preset', 'automatic');
        $defaultCrf = $processingPreset === 'smaller_720p' ? 25 : 23;
        $crf = max(16, min(35, (int) $this->requestedValue($source, 'crf', config('cdn.compress_crf', $defaultCrf))));
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

        return app(FfmpegProcessRunner::class)->run($parts, $source, $duration, 'compressing');
    }

    private function requestedValue(MediaSource $source, string $key, mixed $fallback): mixed
    {
        $metadata = (array) ($source->source_metadata ?? []);

        return $metadata['nbx']['requested'][$key] ?? $fallback;
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

    private function requestedMaxHeight(MediaSource $source): int
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $requested = (array) ($metadata['nbx']['requested'] ?? []);

        return max(0, (int) ($requested['max_resolution'] ?? 0));
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

    private function outputVerificationError(array $input, array $output, string $absoluteOutput): ?string
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

        $inputDuration = (float) ($input['duration'] ?? $input['duration_seconds'] ?? 0);
        $outputDuration = (float) ($output['duration'] ?? $output['duration_seconds'] ?? 0);
        if ($inputDuration > 0 && $outputDuration > 0) {
            $tolerance = max(2.0, $inputDuration * 0.02);
            if (abs($inputDuration - $outputDuration) > $tolerance) {
                return 'Optimized output duration differs too much from the input.';
            }
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

        $message = $exception
            ? 'Optimization worker stopped: '.$exception->getMessage()
            : 'Optimization worker stopped before the video was completed.';
        $this->failOptimization($source, $message);
    }

    private function failOptimization(MediaSource $source, string $message): void
    {
        $bounded = mb_substr($message, -12000);
        $source->update([
            'optimize_status' => 'failed',
            'optimize_error' => $bounded,
            'is_faststart' => false,
            'processing_stage' => 'failed',
            'processing_stage_progress' => null,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => $bounded,
            'last_progress_at' => now(),
        ]);
        $this->markNbxStatusIfManaged($source->fresh() ?? $source, 'failed', $bounded);
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

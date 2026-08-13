<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Queue\Middleware\ConcurrencyPool;
use App\Services\FfmpegProcessRunner;
use App\Services\MediaBinaryDetector;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Services\ProcessingLiveness;
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

class GenerateHlsVariantsJob implements ShouldBeUnique, ShouldQueue
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
        $this->timeout = max(0, (int) config('cdn.ffmpeg_job_timeout_seconds', 0));
        $this->onQueue((string) config('cdn.optimization_queue', 'optimization'));
    }

    public function uniqueId(): string
    {
        return 'optimization:hls:'.$this->sourceId.':'.($this->attemptId ?: 'legacy');
    }

    /**
     * Bound how long a lock-blocked dispatch keeps retrying, independent of
     * $tries. See OptimizeMp4FaststartJob::retryUntil() for why this matters.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        $releaseAfter = max(5, (int) config('cdn.optimization_overlap_release_seconds', 30));
        $locks = [
            // releaseAfter (not dontRelease) — see OptimizeMp4FaststartJob.
            (new WithoutOverlapping('optimization:source:'.$this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200)))
                ->releaseAfter($releaseAfter),
        ];

        if ((bool) config('cdn.serialize_optimization_jobs', true)) {
            // HLS variant encoding is always a libx264 encode, regardless of
            // compress_enabled — always the CPU-bound tier.
            $lockSeconds = max(300, (int) config('cdn.optimization_overlap_lock_seconds', 25200));
            $locks[] = new ConcurrencyPool('transcode', max(1, (int) config('cdn.transcode_concurrency', 1)), $lockSeconds);
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
            Log::info('Ignoring superseded HLS attempt', [
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
        $nbx = app(NbxEngineService::class);

        if (! (bool) config('cdn.enable_hls', true)) {
            $source->update([
                'optimize_status' => 'ready',
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
            ]);
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.completed', [
                'hls_enabled' => false,
                'reason' => 'HLS generation is disabled.',
            ]);

            return;
        }

        $source = app(MediaSourceService::class)->ensureLocalWorkFileForProcessing($source) ?: $source;
        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        $inputPath = $source->optimized_path ?: $source->storage_path;
        if (! $inputPath || ! Storage::disk($disk)->exists($inputPath)) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'optimize_error' => 'HLS generation skipped because input file is missing.',
            ]);
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.partially_completed', [
                'reason' => 'HLS generation skipped because input file is missing.',
            ]);

            return;
        }

        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'optimize_error' => 'HLS generation failed: FFmpeg binary not found. Set FFMPEG_BIN or install ffmpeg in Docker/server.',
            ]);
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.partially_completed', [
                'reason' => 'HLS generation failed: FFmpeg binary not found.',
            ]);

            return;
        }

        $inputAbsolute = Storage::disk($disk)->path($inputPath);
        $metadata = (array) ($source->source_metadata ?? []);
        $probe = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : app(VideoProbeService::class)->probe($inputAbsolute);
        if ($probe !== []) {
            $metadata['probe'] = $probe;
            $source->update([
                'duration_seconds' => isset($probe['duration_seconds']) ? (int) $probe['duration_seconds'] : $source->duration_seconds,
                'source_metadata' => $metadata,
            ]);
        }

        $sourceHeight = isset($probe['height']) ? (int) $probe['height'] : null;
        $hasAudio = ! empty($probe['audio_codec']) || $this->probeSourceHasAudio(app(MediaBinaryDetector::class)->ffprobe(), $inputAbsolute);
        $profiles = $this->requestedProfiles($source);
        $skipped = [];

        $profiles = array_values(array_filter($profiles, function (array $profile) use ($sourceHeight, &$skipped): bool {
            $label = (string) $profile['label'];
            $height = (int) $profile['height'];

            if ($height === 1080 && ! (bool) config('nbx.allow_1080p', false)) {
                $skipped[$label] = '1080p is disabled by NBX_ALLOW_1080P.';

                return false;
            }

            if (is_int($sourceHeight) && $sourceHeight > 0 && $height > $sourceHeight) {
                $skipped[$label] = "Source height {$sourceHeight}p is below requested {$height}p; no upscaling.";

                return false;
            }

            return true;
        }));

        if ($profiles === []) {
            $this->storeSkipped($source, $skipped);
            $source->update([
                'optimize_status' => 'ready',
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => [],
                'optimize_error' => $skipped !== [] ? 'All requested HLS profiles were skipped.' : null,
            ]);
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            $this->dispatchSkippedWebhooks($source, $skipped);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.partially_completed', [
                'reason' => $skipped !== [] ? 'All requested HLS profiles were skipped.' : 'No HLS profiles were requested.',
            ]);

            return;
        }

        $hlsBasePath = sprintf('media/%s/%d/hls', $source->media_asset_id, $source->id);
        $hlsBaseAbsolute = Storage::disk($disk)->path($hlsBasePath);

        $inputSize = (int) (@filesize($inputAbsolute) ?: 0);
        if ($inputSize > 0) {
            // Each requested HLS rendition is roughly comparable in size to
            // the source; scale the safety estimate by how many were asked for.
            $estimated = app(\App\Services\LocalDiskSpaceGuard::class)->estimateTranscodeOutputBytes($inputSize) * max(1, count($profiles));
            try {
                app(\App\Services\LocalDiskSpaceGuard::class)->ensureAvailable(
                    $hlsBaseAbsolute,
                    $estimated,
                    "HLS output (".count($profiles)." profile(s)) for source #{$source->id}"
                );
            } catch (\App\Services\InsufficientDiskSpaceException $spaceError) {
                if ($this->currentAttemptOwnsSource($source)) {
                    $this->markHlsFailure($source, $spaceError->getMessage());
                }

                return;
            }
        }

        Storage::disk($disk)->deleteDirectory($hlsBasePath);
        Storage::disk($disk)->makeDirectory($hlsBasePath);

        $generated = [];
        $delayBetweenVariants = max(0, (int) config('cdn.hls_variant_delay_seconds', 2));
        foreach (array_values($profiles) as $index => $profile) {
            if ($index > 0 && $delayBetweenVariants > 0) {
                sleep($delayBetweenVariants);
            }

            $label = (string) $profile['label'];
            $height = (int) $profile['height'];
            app(NbxEngineService::class)->markNbxStatus($source->fresh() ?? $source, 'encoding_'.$label);
            $source->update([
                'processing_stage' => 'hls_'.$label,
                'processing_stage_progress' => 0,
                'processing_heartbeat_at' => now(),
                'progress_percent' => 85,
                'last_progress_at' => now(),
            ]);

            $variantPath = $hlsBasePath.'/'.$label;
            $variantAbsolute = $hlsBaseAbsolute.'/'.$label;
            Storage::disk($disk)->makeDirectory($variantPath);

            $playlistAbsolute = $variantAbsolute.'/index.m3u8';
            $segmentPattern = $variantAbsolute.'/seg_%05d.ts';

            [$exitCode, $error] = $this->runHlsTranscode(
                $ffmpeg,
                $inputAbsolute,
                $playlistAbsolute,
                $segmentPattern,
                $profile,
                $hasAudio,
                $source,
                (float) ($probe['processing_duration'] ?? $probe['duration'] ?? $probe['duration_seconds'] ?? 0),
            );

            if ($exitCode === FfmpegProcessRunner::SUPERSEDED_EXIT_CODE) {
                // A newer attempt now owns this source; stop entirely
                // rather than continuing to the next profile or writing
                // any status for a row this attempt no longer owns.
                return;
            }

            if ($exitCode !== 0 || ! is_file($playlistAbsolute)) {
                $skipped[$label] = $this->summarizeError($error);
                Log::warning('HLS variant generation failed', [
                    'source_id' => $source->id,
                    'profile' => $label,
                    'exit_code' => $exitCode,
                    'source_height' => $sourceHeight,
                    'error' => $error,
                ]);

                continue;
            }

            $generated[] = [
                'id' => $label,
                'label' => strtoupper($label),
                'height' => $height,
                'width' => null,
                'bandwidth' => (int) $profile['bitrate'],
                'path' => $variantPath.'/index.m3u8',
            ];
        }

        if ($generated === []) {
            $this->storeSkipped($source, $skipped);
            if (! $this->updateIfCurrentAttempt($source, [
                'optimize_status' => 'ready',
                'hls_worker_status' => 'failed',
                'hls_worker_last_error' => 'HLS generation failed for all requested profiles.',
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => [],
                'optimize_error' => 'HLS generation failed for all requested profiles.',
            ])) {
                return;
            }
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            $this->dispatchSkippedWebhooks($source, $skipped);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.partially_completed', [
                'reason' => 'HLS generation failed for all requested profiles.',
            ]);

            return;
        }

        usort($generated, fn (array $a, array $b): int => (int) $b['height'] <=> (int) $a['height']);

        $masterPath = $hlsBasePath.'/master.m3u8';
        $masterAbsolute = Storage::disk($disk)->path($masterPath);
        $masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach ($generated as $variant) {
            $masterLines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d',
                max(1, (int) $variant['bandwidth']),
                (int) round((16 / 9) * (int) $variant['height']),
                (int) $variant['height']
            );
            $masterLines[] = basename(dirname((string) $variant['path'])).'/index.m3u8';
        }

        @file_put_contents($masterAbsolute, implode("\n", $masterLines)."\n");
        if (! is_file($masterAbsolute)) {
            if (! $this->updateIfCurrentAttempt($source, [
                'optimize_status' => 'ready',
                'hls_worker_status' => 'failed',
                'hls_worker_last_error' => 'HLS master playlist generation failed.',
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => [],
                'optimize_error' => 'HLS master playlist generation failed.',
            ])) {
                return;
            }
            $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.partially_completed', [
                'reason' => 'HLS master playlist generation failed.',
            ]);

            return;
        }

        $this->storeSkipped($source, $skipped);
        if (! $this->updateIfCurrentAttempt($source, [
            // Faststart remains a valid progressive output even when one HLS
            // rendition fails. Do not make it disappear by reusing the broad
            // optimize_status field for a derivative-only failure.
            'optimize_status' => 'ready',
            'playback_type' => 'hls',
            'hls_master_path' => $masterPath,
            'qualities_json' => $generated,
            'optimize_error' => $skipped === [] ? null : 'Some HLS profiles were skipped or failed.',
            'hls_worker_status' => $skipped === [] ? 'completed' : 'partial',
            'hls_worker_last_error' => $skipped === [] ? null : 'Some HLS profiles were skipped or failed.',
            'processing_stage' => $skipped === [] ? 'ready' : 'partially_completed',
            'processing_stage_progress' => 100,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 100,
            'last_progress_at' => now(),
        ])) {
            return;
        }

        $source = $nbx->finalizeStorageIfNeeded($source->fresh() ?? $source);
        $this->dispatchSkippedWebhooks($source, $skipped);
        $this->dispatchGeneratedWebhooks($source, $generated);
        app(\App\Services\NbxWebhookDispatcher::class)->dispatch(
            $source,
            $skipped === [] ? 'job.completed' : 'job.partially_completed',
            ['generated_profiles' => array_column($generated, 'id')]
        );
    }

    /**
     * @return array<int, array{label:string,height:int,bitrate:int,maxrate:string,bufsize:string,audio_bitrate:string,crf:int}>
     */
    private function requestedProfiles(MediaSource $source): array
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $requested = is_array($nbx['requested']['hls'] ?? null) ? $nbx['requested']['hls'] : [];

        if ($requested === []) {
            foreach ((array) config('cdn.hls_profiles', ['480']) as $profile) {
                $key = strtolower(trim((string) $profile));
                $requested[str_ends_with($key, 'p') ? $key : ($key.'p')] = true;
            }
        }

        $map = [
            '480p' => ['label' => '480p', 'height' => 480, 'bitrate' => 900000, 'maxrate' => '900k', 'bufsize' => '1800k', 'audio_bitrate' => '96k', 'crf' => 27],
            '720p' => ['label' => '720p', 'height' => 720, 'bitrate' => 1500000, 'maxrate' => '1500k', 'bufsize' => '3000k', 'audio_bitrate' => '96k', 'crf' => 28],
            '1080p' => ['label' => '1080p', 'height' => 1080, 'bitrate' => 2800000, 'maxrate' => '2800k', 'bufsize' => '5600k', 'audio_bitrate' => '128k', 'crf' => 29],
        ];

        $profiles = [];
        foreach ($map as $key => $profile) {
            $enabled = (bool) ($requested[$key] ?? $requested[str_replace('p', '', $key)] ?? false);
            if ($enabled) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * @param  array{label:string,height:int,bitrate:int,maxrate:string,bufsize:string,audio_bitrate:string,crf:int}  $profile
     * @return array{0:int,1:string}
     */
    private function runHlsTranscode(
        string $ffmpeg,
        string $inputAbsolute,
        string $playlistAbsolute,
        string $segmentPattern,
        array $profile,
        bool $hasAudio,
        MediaSource $source,
        float $duration,
    ): array {
        $height = (int) $profile['height'];
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
            $inputAbsolute,
            '-map',
            '0:V:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
            '-vf',
            "scale=-2:{$height}:force_original_aspect_ratio=decrease:force_divisible_by=2",
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            (string) $profile['crf'],
            '-maxrate',
            (string) $profile['maxrate'],
            '-bufsize',
            (string) $profile['bufsize'],
        ];

        if ($hasAudio) {
            $parts = array_merge($parts, [
                '-c:a',
                'aac',
                '-b:a',
                (string) $profile['audio_bitrate'],
            ]);
        } else {
            $parts[] = '-an';
        }

        $parts = array_merge($parts, [
            '-f',
            'hls',
            '-hls_time',
            '10',
            '-hls_playlist_type',
            'vod',
            '-hls_flags',
            'independent_segments',
            '-hls_segment_filename',
            $segmentPattern,
            $playlistAbsolute,
        ]);

        return app(FfmpegProcessRunner::class)->run(
            $parts,
            $source,
            $duration,
            'hls_'.(string) $profile['label'],
            $playlistAbsolute,
        );
    }

    private function probeSourceHasAudio(?string $ffprobe, string $inputAbsolute): bool
    {
        if (! $ffprobe || ! is_file($ffprobe)) {
            return true;
        }

        $cmd = implode(' ', [
            escapeshellarg($ffprobe),
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            escapeshellarg($inputAbsolute),
            '2>&1',
        ]);

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);

        return $exitCode !== 0 || trim(implode("\n", $output)) !== '';
    }

    /**
     * @param  array<string, string>  $skipped
     */
    private function storeSkipped(MediaSource $source, array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $fresh = $source->fresh() ?? $source;
        $metadata = (array) ($fresh->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $nbx['skipped_profiles'] = array_merge((array) ($nbx['skipped_profiles'] ?? []), $skipped);
        $metadata['nbx'] = $nbx;
        $fresh->update(['source_metadata' => $metadata]);
    }

    private function summarizeError(string $error): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $error) ?: $error);

        return mb_substr($trimmed !== '' ? $trimmed : 'FFmpeg failed.', 0, 500);
    }

    /**
     * Write only if this job's attempt still owns the row. See
     * OptimizeMp4FaststartJob::updateIfCurrentAttempt() for the rationale.
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

        Log::info('Skipped terminal write for superseded HLS attempt', [
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

    public function failed(?Throwable $exception): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || ($this->attemptId !== null && $source->processing_attempt_id !== $this->attemptId)) {
            return;
        }

        $health = app(ProcessingStateInspector::class)->inspect($source);
        if ($health['active']) {
            $source->update([
                'processing_diagnostics' => 'Queue reported a stopped HLS job, but '.($health['message'] ?? 'FFmpeg is still active'),
                'last_progress_at' => now(),
            ]);

            return;
        }

        $message = mb_substr(
            $exception ? 'HLS worker stopped: '.$exception->getMessage() : 'HLS worker stopped before completion.',
            -12000,
        );
        $this->markHlsFailure($source, $message);
    }

    private function markHlsFailure(MediaSource $source, string $message): void
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $nbx['status'] = $source->is_faststart ? 'partially_completed' : 'original_ready';
        $nbx['hls'] = [
            'status' => 'failed',
            'error' => mb_substr($message, -12000),
            'failed_at' => now()->toIso8601String(),
        ];
        $metadata['provider'] = 'nbx_engine';
        $metadata['nbx'] = $nbx;
        $source->update([
            'optimize_status' => $source->is_faststart ? 'ready' : 'failed',
            'optimize_error' => mb_substr($message, -12000),
            'hls_worker_status' => 'failed',
            'hls_worker_last_error' => mb_substr($message, -12000),
            'processing_stage' => $source->is_faststart ? 'hls_failed' : 'optimization_failed',
            'processing_stage_progress' => null,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => mb_substr($message, -12000),
            'ffmpeg_pid' => null,
            'last_progress_at' => now(),
            'source_metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, string>  $skipped
     */
    private function dispatchSkippedWebhooks(MediaSource $source, array $skipped): void
    {
        foreach ($skipped as $label => $reason) {
            $quality = rtrim(strtolower((string) $label), 'p');
            if ($quality === '') {
                continue;
            }

            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.hls.'.$quality.'.skipped', [
                'quality' => (string) $label,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $generated
     */
    private function dispatchGeneratedWebhooks(MediaSource $source, array $generated): void
    {
        foreach ($generated as $profile) {
            $label = strtolower((string) ($profile['id'] ?? $profile['label'] ?? ''));
            $quality = rtrim($label, 'p');
            if ($quality === '') {
                continue;
            }

            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.hls.'.$quality.'.completed', [
                'quality' => $label,
                'path' => $profile['path'] ?? null,
            ]);
        }
    }
}

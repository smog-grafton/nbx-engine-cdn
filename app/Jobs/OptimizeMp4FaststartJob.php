<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\MediaBinaryDetector;
use App\Services\NbxEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OptimizeMp4FaststartJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 3600;

    public function __construct(public int $sourceId)
    {
    }

    public function uniqueId(): string
    {
        return 'optimization:faststart:' . $this->sourceId;
    }

    public function middleware(): array
    {
        $locks = [
            (new WithoutOverlapping('optimization:source:' . $this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->dontRelease(),
        ];

        if ((bool) config('cdn.serialize_optimization_jobs', true)) {
            $locks[] = (new WithoutOverlapping('optimization:global'))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->releaseAfter(30);
        }

        return $locks;
    }

    public function handle(): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready' || ! $source->storage_path) {
            return;
        }

        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        if (! Storage::disk($disk)->exists($source->storage_path)) {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => 'Original media file was not found for faststart optimization.',
                'is_faststart' => false,
            ]);
            app(NbxEngineService::class)->markNbxStatus(
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
            app(NbxEngineService::class)->markNbxStatus(
                $source->fresh() ?? $source,
                'failed',
                'FFmpeg binary was not found. Set FFMPEG_BIN or install ffmpeg in Docker/server.'
            );
            return;
        }

        $source->update([
            'optimize_status' => 'processing',
            'optimize_error' => null,
        ]);
        app(NbxEngineService::class)->markNbxStatus($source, $this->shouldCompress($source) ? 'compressing' : 'faststarting');

        $originalPath = $source->storage_path;
        $absoluteInput = Storage::disk($disk)->path($originalPath);
        $optimizedPath = $this->buildOptimizedPath($source, $originalPath);
        $absoluteOutput = Storage::disk($disk)->path($optimizedPath);
        Storage::disk($disk)->makeDirectory(dirname($optimizedPath));

        $shouldCompress = $this->shouldCompress($source);
        $extension = strtolower((string) pathinfo($originalPath, PATHINFO_EXTENSION));
        $mime = strtolower((string) ($source->mime_type ?? ''));
        $isMp4Input = in_array($extension, ['mp4', 'm4v'], true)
            || in_array($mime, ['video/mp4', 'video/x-m4v', 'video/m4v'], true);

        if ($shouldCompress) {
            [$exitCode, $rawError] = $this->runCompressionTranscode($ffmpeg, $absoluteInput, $absoluteOutput);
        } elseif ($isMp4Input) {
            [$exitCode, $rawError] = $this->runFaststartCopy($ffmpeg, $absoluteInput, $absoluteOutput);
        } else {
            // Compression was disabled for this source and the input is not MP4.
            $source->update([
                'optimize_status' => 'ready',
                'optimized_path' => $originalPath,
                'optimize_error' => null,
                'is_faststart' => false,
                'optimized_at' => now(),
                'playback_type' => 'mp4',
            ]);
            return;
        }

        if ($exitCode !== 0 || ! is_file($absoluteOutput) || filesize($absoluteOutput) <= 0) {
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
            ]);
            app(NbxEngineService::class)->markNbxStatus(
                $source->fresh() ?? $source,
                'failed',
                $optimizeError !== '' ? $optimizeError : 'FFmpeg faststart optimization failed.'
            );

            return;
        }

        $optimizedSize = (int) filesize($absoluteOutput);
        $deletedOriginal = $this->maybeDeleteOriginalAfterCompression($source, $disk, $originalPath, $optimizedPath, $shouldCompress);
        $updates = [
            'optimize_status' => 'ready',
            'optimized_path' => $optimizedPath,
            'optimize_error' => null,
            'is_faststart' => true,
            'optimized_at' => now(),
            'playback_type' => 'mp4',
        ];

        if ($deletedOriginal) {
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
        $source = app(NbxEngineService::class)->refreshOutputMetadata($source->fresh() ?? $source);
        app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.faststart.completed', [
            'compressed' => $shouldCompress,
            'optimized_path' => $optimizedPath,
        ]);
    }

    private function shouldCompress(MediaSource $source): bool
    {
        $enabledByConfig = (bool) config('cdn.compress_before_playback', true);
        $enabledBySource = $source->compress_enabled ?? true;

        return $enabledByConfig && (bool) $enabledBySource;
    }

    /**
     * Copy-only faststart: remux moov atom to start. No re-encode.
     * Use -map 0 -dn for robustness; -loglevel error to reduce noise.
     *
     * @return array{0:int,1:string}
     */
    private function runFaststartCopy(string $ffmpeg, string $absoluteInput, string $absoluteOutput): array
    {
        $cmd = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-loglevel',
            'error',
            '-i',
            escapeshellarg($absoluteInput),
            '-map',
            '0',
            '-dn',
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            escapeshellarg($absoluteOutput),
            '2>&1',
        ]);

        $outputLines = [];
        $exitCode = 0;
        @exec($cmd, $outputLines, $exitCode);

        return [$exitCode, trim(implode("\n", $outputLines))];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runCompressionTranscode(string $ffmpeg, string $absoluteInput, string $absoluteOutput): array
    {
        $videoCodec = (string) config('cdn.compress_video_codec', 'libx264');
        $audioCodec = (string) config('cdn.compress_audio_codec', 'aac');
        $audioBitrate = (string) config('cdn.compress_audio_bitrate', '128k');
        $crf = (int) config('cdn.compress_crf', 23);
        $preset = (string) config('cdn.compress_preset', 'medium');
        $maxHeight = max(0, (int) config('cdn.compress_max_height', 0));

        $parts = [
            escapeshellarg($ffmpeg),
            '-y',
            '-loglevel',
            'error',
            '-i',
            escapeshellarg($absoluteInput),
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-c:v',
            escapeshellarg($videoCodec),
            '-preset',
            escapeshellarg($preset),
            '-crf',
            (string) $crf,
            '-threads',
            '1',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            escapeshellarg($audioCodec),
            '-b:a',
            escapeshellarg($audioBitrate),
            '-movflags',
            '+faststart',
        ];

        if ($maxHeight > 0) {
            $parts[] = '-vf';
            $parts[] = escapeshellarg("scale=-2:'min({$maxHeight},ih)':force_original_aspect_ratio=decrease");
        }

        $parts[] = escapeshellarg($absoluteOutput);
        $parts[] = '2>&1';

        $outputLines = [];
        $exitCode = 0;
        @exec(implode(' ', $parts), $outputLines, $exitCode);

        return [$exitCode, trim(implode("\n", $outputLines))];
    }

    private function buildOptimizedPath(MediaSource $source, string $storagePath): string
    {
        $baseName = (string) pathinfo($storagePath, PATHINFO_FILENAME);
        $normalized = preg_replace('/_play$/', '', $baseName) ?: $baseName;
        $directory = trim((string) dirname($storagePath), '.');
        $candidate = ltrim($directory . '/' . $normalized . '_play.mp4', '/');

        if ($candidate === $storagePath) {
            $candidate = ltrim($directory . '/' . $normalized . '_play_' . now()->format('YmdHis') . '.mp4', '/');
        }

        return $candidate;
    }

    private function maybeDeleteOriginalAfterCompression(
        MediaSource $source,
        string $disk,
        string $originalPath,
        string $optimizedPath,
        bool $compressed
    ): bool {
        $context = [
            'source_id'         => $source->id,
            'asset_id'          => $source->media_asset_id,
            'original_path'     => $originalPath,
            'optimized_path'    => $optimizedPath,
            'compressed'        => $compressed,
            'delete_enabled'    => config('cdn.compress_delete_original'),
            'original_storage_path' => $source->original_storage_path,
        ];

        // Guard 1: feature must be compression, not just faststart copy.
        if (! $compressed) {
            Log::info('OptimizeMp4FaststartJob: skip delete – was faststart copy, not compression', $context);
            return false;
        }

        // Guard 2: explicit opt-in required; default is false to prevent accidental mass deletion.
        if (! (bool) config('cdn.compress_delete_original', false)) {
            Log::info('OptimizeMp4FaststartJob: skip delete – CDN_COMPRESS_DELETE_ORIGINAL is false', $context);
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
            'original_size_bytes'  => Storage::disk($disk)->size($originalPath),
        ]));

        $deleted = Storage::disk($disk)->delete($originalPath);

        Log::info('OptimizeMp4FaststartJob: original deletion result', array_merge($context, ['deleted' => $deleted]));

        return $deleted;
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

<?php

namespace App\Services;

use App\Models\MediaSource;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence-based view of an in-flight pipeline attempt.
 *
 * A stale UI percentage is deliberately not evidence that a job is dead.
 * This inspector is used by the stale reaper and the operator-facing storage
 * reconciliation action before either of them changes state.
 */
class ProcessingStateInspector
{
    /**
     * @return array{
     *   state:string,active:bool,message:string,ffmpeg_pid:?int,
     *   worker_id:?string,output_bytes:int,output_growing:bool,
     *   original_present:bool,heartbeat_at:?string
     * }
     */
    public function inspect(MediaSource $source): array
    {
        $source = $source->fresh() ?? $source;
        $disk = (string) ($source->storage_disk ?: config('cdn.disk', 'public'));
        $outputBytes = $this->currentOutputSize($source, $disk);
        $recordedOutputBytes = max(0, (int) ($source->current_output_size_bytes ?? 0));
        $outputGrowing = $outputBytes > $recordedOutputBytes;
        $pid = $source->ffmpeg_pid ? (int) $source->ffmpeg_pid : null;
        $sameWorker = $source->processing_worker_id === null
            || $source->processing_worker_id === $this->workerId();
        $pidAlive = $pid !== null && $sameWorker && $this->pidIsAlive($pid);
        $cacheAlive = ProcessingLiveness::isAlive($source->id);
        $originalPresent = $this->exists($disk, $source->storage_path)
            || $this->exists($disk, $source->original_storage_path);
        $stage = (string) ($source->processing_stage ?? '');
        $heartbeat = $source->processing_heartbeat_at;
        $heartbeatAt = $heartbeat?->toIso8601String();

        if ($pidAlive) {
            return $this->result(
                'processing',
                true,
                "FFmpeg PID {$pid} is active on {$this->workerId()}; current output is ".self::formatBytes($outputBytes).'.',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                $outputGrowing,
                $originalPresent,
                $heartbeatAt,
            );
        }

        if ($cacheAlive) {
            return $this->result(
                $stage === 'final_storage_upload' ? 'uploading' : 'processing',
                true,
                $stage === 'final_storage_upload'
                    ? 'Final storage upload has a fresh worker heartbeat.'
                    : 'The processing worker has a fresh heartbeat.',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                $outputGrowing,
                $originalPresent,
                $heartbeatAt,
            );
        }

        if ($outputGrowing) {
            return $this->result(
                'processing',
                true,
                'The temporary output is still growing ('.self::formatBytes($recordedOutputBytes).' → '.self::formatBytes($outputBytes).').',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                true,
                $originalPresent,
                $heartbeatAt,
            );
        }

        if (in_array($source->status, ['pending', 'downloading', 'processing', 'proxying', 'uploading'], true)) {
            return $this->result(
                'fetching',
                false,
                'No active FFmpeg process or fresh worker heartbeat was found for the source-acquisition stage.',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                false,
                $originalPresent,
                $heartbeatAt,
            );
        }

        if ($source->optimize_status === 'failed') {
            return $this->result(
                'optimization_failed',
                false,
                $originalPresent
                    ? 'Optimization genuinely failed; the original work file is still available for a targeted retry.'
                    : 'Optimization failed and no local original work file is currently available.',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                false,
                $originalPresent,
                $heartbeatAt,
            );
        }

        if (in_array($source->optimize_status, ['pending', 'processing'], true)) {
            return $this->result(
                'stalled',
                false,
                'No active FFmpeg process, output growth, or fresh worker heartbeat was found.',
                $pid,
                $source->processing_worker_id,
                $outputBytes,
                false,
                $originalPresent,
                $heartbeatAt,
            );
        }

        return $this->result(
            $source->status === 'ready' ? 'original_ready' : $source->status,
            false,
            $originalPresent ? 'The original source is present and ready.' : 'No active processing evidence was found.',
            $pid,
            $source->processing_worker_id,
            $outputBytes,
            false,
            $originalPresent,
            $heartbeatAt,
        );
    }

    public function workerId(): string
    {
        return gethostname() ?: php_uname('n');
    }

    private function pidIsAlive(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function currentOutputSize(MediaSource $source, string $disk): int
    {
        $candidates = array_filter([
            $source->optimized_path,
            $this->predictedOptimizedPath($source->storage_path),
        ]);
        $largest = 0;

        foreach ($candidates as $path) {
            try {
                if (is_string($path) && Storage::disk($disk)->exists($path)) {
                    $largest = max($largest, (int) Storage::disk($disk)->size($path));
                }
            } catch (\Throwable) {
                // Storage inspection is advisory. A failed inspection must not
                // convert a still-running job into a terminal failure.
            }
        }

        return $largest;
    }

    private function predictedOptimizedPath(?string $storagePath): ?string
    {
        if (! is_string($storagePath) || trim($storagePath) === '') {
            return null;
        }

        $base = (string) pathinfo($storagePath, PATHINFO_FILENAME);
        $directory = trim((string) dirname($storagePath), '.');

        return ltrim($directory.'/'.(preg_replace('/_play$/', '', $base) ?: $base).'_play.mp4', '/');
    }

    private function exists(string $disk, ?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{state:string,active:bool,message:string,ffmpeg_pid:?int,worker_id:?string,output_bytes:int,output_growing:bool,original_present:bool,heartbeat_at:?string}
     */
    private function result(
        string $state,
        bool $active,
        string $message,
        ?int $pid,
        ?string $worker,
        int $outputBytes,
        bool $outputGrowing,
        bool $originalPresent,
        ?string $heartbeatAt,
    ): array {
        return [
            'state' => $state,
            'active' => $active,
            'message' => $message,
            'ffmpeg_pid' => $pid,
            'worker_id' => $worker,
            'output_bytes' => $outputBytes,
            'output_growing' => $outputGrowing,
            'original_present' => $originalPresent,
            'heartbeat_at' => $heartbeatAt,
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 1).' '.$units[$unit];
    }
}

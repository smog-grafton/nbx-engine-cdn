<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Pre-flight local disk-space check. Peak local usage for a single large job
 * can be several multiples of the source file size (download + faststart
 * output + multiple HLS renditions), and the codebase previously had no
 * check anywhere — a slow disk-fill surfaced only as an unexplained write
 * failure partway through a download or FFmpeg run. Fail early instead,
 * with a clear, actionable error.
 */
class LocalDiskSpaceGuard
{
    /**
     * @throws InsufficientDiskSpaceException
     */
    public function ensureAvailable(string $absolutePath, int $requiredBytes, string $context): void
    {
        if ($requiredBytes <= 0) {
            return;
        }

        $dir = is_dir($absolutePath) ? $absolutePath : dirname($absolutePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $free = @disk_free_space($dir);
        if ($free === false) {
            // Can't determine free space (unsupported filesystem, permissions);
            // don't block the job over a diagnostic that isn't available.
            return;
        }

        $reserveBytes = (int) config('cdn.disk_space_reserve_bytes', 1073741824); // 1GB floor
        if ($free < ($requiredBytes + $reserveBytes)) {
            $message = sprintf(
                'Insufficient local disk space for %s: need %s (+%s reserve), only %s free at %s.',
                $context,
                $this->formatBytes($requiredBytes),
                $this->formatBytes($reserveBytes),
                $this->formatBytes((int) $free),
                $dir,
            );

            Log::error('local_disk_space.insufficient', [
                'context' => $context,
                'required_bytes' => $requiredBytes,
                'reserve_bytes' => $reserveBytes,
                'free_bytes' => (int) $free,
                'path' => $dir,
            ]);

            throw new InsufficientDiskSpaceException($message);
        }
    }

    public function estimateTranscodeOutputBytes(int $inputBytes): int
    {
        $multiplier = (float) config('storage_targets.transcode_safety_multiplier', 2.0);

        return (int) ceil($inputBytes * max(1.0, $multiplier));
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return round($value, 2).' '.$units[$unitIndex];
    }
}

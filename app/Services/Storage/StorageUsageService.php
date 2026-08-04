<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves "how full is this bucket right now" using the best available
 * source, in order:
 *
 *   1. Contabo API usage/quota (not wired by default — no per-target
 *      object_storage_id mapping exists yet; extend here if one is added).
 *   2. Periodic locally-calculated bucket usage (expensive object listing;
 *      only run from `storage-targets:recalculate-usage`, cached).
 *   3. Configured dashboard-known usage (config/storage_targets.php
 *      known_used_bytes, operator-maintained).
 *   4. Incremental local accounting on top of whichever of the above is
 *      freshest — every successful upload/delete nudges the estimate
 *      between periodic recalculations.
 *
 * The result always carries a timestamp and an explicit "estimated"/"stale"
 * flag rather than presenting a number as authoritative when it isn't.
 */
class StorageUsageService
{
    private const CACHE_PREFIX = 'storage_target_usage:';

    private const DELTA_CACHE_PREFIX = 'storage_target_usage_delta:';

    public function __construct(private readonly StorageTargetRegistry $registry)
    {
    }

    /**
     * @return array{used_bytes: int, source: string, checked_at: ?string, stale: bool}
     */
    public function usageFor(StorageTarget $target): array
    {
        $cached = Cache::get(self::CACHE_PREFIX.$target->key);

        if (is_array($cached) && isset($cached['used_bytes'], $cached['checked_at'])) {
            $base = $cached;
        } else {
            $base = [
                'used_bytes' => $target->knownUsedBytes,
                'source' => 'configured',
                'checked_at' => $target->knownUsedAt,
            ];
        }

        $delta = (int) Cache::get(self::DELTA_CACHE_PREFIX.$target->key, 0);
        $usedBytes = max(0, (int) $base['used_bytes'] + $delta);

        $staleAfterMinutes = (int) config('storage_targets.usage_stale_after_minutes', 30);
        $checkedAt = $base['checked_at'] ?? null;
        $stale = $checkedAt === null
            || now()->diffInMinutes(\Illuminate\Support\Carbon::parse($checkedAt)) > $staleAfterMinutes;

        return [
            'used_bytes' => $usedBytes,
            'source' => $base['source'] ?? 'configured',
            'checked_at' => $checkedAt,
            'stale' => $stale,
        ];
    }

    public function recordUpload(string $targetKey, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $key = self::DELTA_CACHE_PREFIX.$targetKey;
        Cache::put($key, ((int) Cache::get($key, 0)) + $bytes, now()->addDays(30));
    }

    public function recordDeletion(string $targetKey, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $key = self::DELTA_CACHE_PREFIX.$targetKey;
        Cache::put($key, ((int) Cache::get($key, 0)) - $bytes, now()->addDays(30));
    }

    /**
     * Best-effort local recalculation by listing bucket objects and summing
     * their sizes. Expensive for large buckets — intended for a scheduled
     * command, not per-request use.
     */
    public function recalculateLocally(StorageTarget $target, int $maxObjects = 50000): int
    {
        $total = 0;
        $count = 0;

        try {
            $disk = Storage::disk($target->disk);
            foreach ($disk->allFiles($target->pathPrefix) as $file) {
                $size = $disk->size($file);
                $total += is_int($size) ? $size : 0;
                $count++;
                if ($count >= $maxObjects) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('storage_targets.usage_recalculation_failed', [
                'target' => $target->key,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->store($target->key, $total, 'local_scan');

        return $total;
    }

    private function store(string $targetKey, int $usedBytes, string $source): void
    {
        Cache::forever(self::CACHE_PREFIX.$targetKey, [
            'used_bytes' => $usedBytes,
            'source' => $source,
            'checked_at' => now()->toIso8601String(),
        ]);

        Cache::forget(self::DELTA_CACHE_PREFIX.$targetKey);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-TTL "a worker is actively processing this source right now" marker,
 * refreshed on every heartbeat/stage transition by the owning job.
 *
 * Deliberately NOT a mutex — the per-source WithoutOverlapping queue
 * middleware already provides real mutual exclusion for execution. This
 * class only answers the question media:recover-stale-optimizations needs
 * before reaping a stale heartbeat: "is anyone still genuinely working on
 * this row?" That needs a TTL measured in heartbeat intervals (a couple of
 * minutes), not the job's own multi-hour timeout. An earlier version of
 * this liveness check used a Cache::lock() held for the job's full
 * timeout, which meant a worker SIGKILLed mid-run (e.g. supervisord
 * exceeding stopwaitsecs) left a stale "still alive" claim for up to
 * several hours — during which the reaper correctly, but unhelpfully,
 * refused to touch a row nobody was actually working on anymore.
 */
class ProcessingLiveness
{
    private const TTL_SECONDS = 120;

    public static function touch(int $mediaSourceId): void
    {
        Cache::put(self::key($mediaSourceId), true, now()->addSeconds(self::TTL_SECONDS));
    }

    public static function isAlive(int $mediaSourceId): bool
    {
        return Cache::has(self::key($mediaSourceId));
    }

    private static function key(int $mediaSourceId): string
    {
        return 'nbx:optimize-alive:'.$mediaSourceId;
    }
}

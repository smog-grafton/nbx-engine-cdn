<?php

namespace App\Queue\Middleware;

use Illuminate\Support\Facades\Cache;

/**
 * Bounded "run at most N of this tier at once" queue middleware.
 *
 * Laravel's own WithoutOverlapping is a single-holder mutex — it can't
 * express "up to 3 concurrent," only "up to 1." This tries a fixed pool of
 * named slot locks and runs the job under whichever one it acquires first;
 * if none are free right now, the job releases itself back to the queue
 * rather than blocking a worker process on a wait.
 *
 * Used to give cheap remux/fast-start work (I/O bound) a different
 * concurrency budget than CPU-heavy compression/HLS encodes, replacing a
 * single global "only one optimize job at a time" lock that treated both
 * the same way regardless of actual cost.
 */
class ConcurrencyPool
{
    public function __construct(
        private readonly string $poolName,
        private readonly int $maxSlots,
        private readonly int $lockSeconds,
        private readonly int $releaseAfter = 30,
    ) {}

    public function handle(mixed $job, callable $next): void
    {
        $slots = max(1, $this->maxSlots);

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock("nbx:pool:{$this->poolName}:slot:{$slot}", $this->lockSeconds);
            if ($lock->get()) {
                try {
                    $next($job);
                } finally {
                    $lock->release();
                }

                return;
            }
        }

        // Every slot in this tier is busy right now — try again shortly
        // rather than occupying a worker process waiting.
        $job->release($this->releaseAfter);
    }
}

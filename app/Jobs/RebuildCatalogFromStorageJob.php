<?php

namespace App\Jobs;

use App\Services\CatalogRebuildService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class RebuildCatalogFromStorageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public const CACHE_KEY = 'nbx:catalog-rebuild:last-summary';

    public function __construct()
    {
        $this->onQueue((string) config('nbx.storage_inventory_queue', 'media-maintenance'));
    }

    public function handle(CatalogRebuildService $service): void
    {
        Cache::put(self::CACHE_KEY, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        // Reuse the CLI command as the single source of truth for "what a
        // full rebuild run does" (scan every NBX-owned target, then
        // rebuild), instead of duplicating that sequencing here.
        Artisan::call('nbx:storage-inventory-all-targets', ['--sync' => true]);
        $summary = $service->rebuild(false);

        Cache::put(self::CACHE_KEY, array_merge($summary, [
            'status' => 'completed',
            'started_at' => Cache::get(self::CACHE_KEY)['started_at'] ?? null,
            'completed_at' => now()->toIso8601String(),
        ]), now()->addDays(7));
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put(self::CACHE_KEY, [
            'status' => 'failed',
            'failure_reason' => $exception->getMessage(),
            'completed_at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }
}

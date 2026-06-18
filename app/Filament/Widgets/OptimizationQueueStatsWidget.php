<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaSource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class OptimizationQueueStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $queueConnection = config('queue.default');
        $queueConfig = config("queue.connections.{$queueConnection}");
        $optimizationQueue = (string) config('cdn.optimization_queue', 'optimization');

        $optimizationPending = null;
        if (($queueConfig['driver'] ?? null) === 'database') {
            $connection = $queueConfig['connection'] ?? null;
            $table = $queueConfig['table'] ?? 'jobs';
            try {
                $optimizationPending = DB::connection($connection)->table($table)
                    ->where('queue', $optimizationQueue)
                    ->count();
            } catch (\Throwable $e) {
                // table may not exist; fall back to null
                $optimizationPending = null;
            }
        }

        $sourcesPending = MediaSource::where('status', 'ready')
            ->whereIn('optimize_status', ['pending', 'processing'])
            ->count();

        $sourcesFailed = MediaSource::where('status', 'ready')
            ->where('optimize_status', 'failed')
            ->count();

        $sourcesReady = MediaSource::where('status', 'ready')
            ->where('optimize_status', 'ready')
            ->count();

        $workerEnabled = (bool) config('cdn.laravel_worker_enabled', false);

        return [
            Stat::make('Optimization queue (jobs)', $optimizationPending ?? 'N/A')
                ->description(
                    (bool) config('cdn.enable_hls', true)
                        ? 'Jobs waiting (each source = 2 jobs: faststart + HLS). Scheduler runs every 2 min, 1 job at a time.'
                        : 'Jobs waiting (HLS disabled, 1 job per source – faststart only). Scheduler runs every 2 min, 1 job at a time.'
                )
                ->color(($optimizationPending ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make('Sources pending optimization', $sourcesPending)
                ->description('Media sources with optimize_status pending or processing.')
                ->color($sourcesPending > 0 ? 'warning' : 'success'),
            Stat::make('Sources with failed optimization', $sourcesFailed)
                ->description('Click to view media assets with failed sources. Use "Run re-optimise" on a source or wait for the retry command (every 5 min).')
                ->color($sourcesFailed > 0 ? 'danger' : 'success')
                ->url(
                    $sourcesFailed > 0
                        ? MediaAssetResource::getUrl('index') . '?tableFilters[has_failed_optimization][value]=1'
                        : null,
                ),
            Stat::make('Ready & optimized sources', $sourcesReady)
                ->description('Media sources with status=ready and optimize_status=ready.')
                ->color($sourcesReady > 0 ? 'success' : 'gray'),
            Stat::make('Optimization worker mode', $workerEnabled ? 'Laravel worker' : 'Local queue only')
                ->description($workerEnabled
                    ? 'CDN_LARAVEL_WORKER_ENABLED=true – optimization jobs are sent to the external worker.'
                    : 'CDN_LARAVEL_WORKER_ENABLED=false – optimization runs on this server.')
                ->color($workerEnabled ? 'success' : 'warning'),
        ];
    }

    protected function getPollingInterval(): ?string
    {
        return (string) config('cdn.admin_queue_stats_polling_interval', '60s');
    }
}

<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaSource;
use App\Services\OptimizationQueueInspector;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OptimizationQueueStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $queueSummary = app(OptimizationQueueInspector::class)->summary();
        $optimizationPending = $queueSummary['total'];

        $sourcesPending = MediaSource::where('status', 'ready')
            ->whereIn('optimize_status', ['pending', 'processing'])
            ->count();
        $sourcesQueued = MediaSource::where('status', 'ready')
            ->where('optimize_status', 'pending')
            ->where('processing_stage', 'queued')
            ->count();
        $sourcesPreparing = MediaSource::where('status', 'ready')
            ->where('optimize_status', 'processing')
            ->where('processing_stage', 'preparing_input')
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
                    $queueSummary['can_inspect']
                        ? sprintf(
                            'Ready %d, delayed %d, reserved %d. Docker workers poll every second; scheduler fallback runs every 2 min.',
                            (int) $queueSummary['ready'],
                            (int) $queueSummary['delayed'],
                            (int) $queueSummary['reserved'],
                        )
                        : 'Current queue driver cannot be inspected through the jobs table.'
                )
                ->color(($optimizationPending ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make('Sources pending optimization', $sourcesPending)
                ->description("Queued {$sourcesQueued}; preparing input {$sourcesPreparing}.")
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

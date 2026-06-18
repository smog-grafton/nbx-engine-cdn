<?php

namespace App\Filament\Widgets;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;

class OptimizationQueueControlWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.optimization-queue-control-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public function clearOptimizationQueueAction(): Action
    {
        return Action::make('clearOptimizationQueue')
            ->label('Clear pending optimization jobs')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Clear pending optimization jobs?')
            ->modalDescription('This removes queued optimization jobs from the configured optimization queue only. It does not delete media files or media sources.')
            ->action(function (): void {
                Artisan::call('media:clear-optimization-queue');

                Notification::make()
                    ->success()
                    ->title('Optimization queue cleared')
                    ->body(trim(Artisan::output()) ?: 'Pending optimization jobs were cleared.')
                    ->send();
            });
    }

    public function runPendingOptimizationsAction(): Action
    {
        $defaultLimit = max(1, (int) config('cdn.optimization_dashboard_batch_limit', 10));

        return Action::make('runPendingOptimizations')
            ->label('Run pending optimizations')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Queue pending optimizations')
            ->modalDescription('Queues a small batch of ready sources with pending or failed optimization. The scheduler/worker still processes them one at a time based on your existing queue settings.')
            ->form([
                TextInput::make('limit')
                    ->label('Batch size')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default($defaultLimit)
                    ->helperText('Keep this low on shared hosting. Default comes from CDN_OPTIMIZATION_DASHBOARD_BATCH_LIMIT.'),
            ])
            ->action(function (array $data): void {
                $limit = max(1, min(50, (int) ($data['limit'] ?? config('cdn.optimization_dashboard_batch_limit', 10))));
                $service = app(MediaSourceService::class);

                $sources = MediaSource::with('asset')
                    ->where('status', 'ready')
                    ->whereIn('optimize_status', ['pending', 'failed'])
                    ->oldest('updated_at')
                    ->limit($limit)
                    ->get();

                $queued = 0;
                foreach ($sources as $source) {
                    if ($service->queuePlaybackProcessing($source)) {
                        $queued++;
                    }
                }

                Notification::make()
                    ->success()
                    ->title('Pending optimizations queued')
                    ->body($queued > 0
                        ? "{$queued} source(s) queued. The existing optimization queue will process them safely."
                        : 'No eligible sources were queued.')
                    ->send();
            });
    }
}

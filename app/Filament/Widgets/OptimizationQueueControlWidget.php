<?php

namespace App\Filament\Widgets;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    private function notifyCommandResult(string $title, string $output, bool $success = true): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($output !== '' ? str($output)->limit(1200)->toString() : 'Command finished with no output.');

        ($success ? $notification->success() : $notification->warning())->send();
    }

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

    public function processOptimizationQueueAction(): Action
    {
        return Action::make('processOptimizationQueue')
            ->label('Process optimization jobs now')
            ->icon('heroicon-o-bolt')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Run optimization worker now')
            ->modalDescription('Runs queue:work for the optimization queue in this request and stops after the selected number of jobs or when the queue is empty.')
            ->form([
                TextInput::make('max_jobs')
                    ->label('Max jobs to process')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(3)
                    ->helperText('Use a small number for long video jobs. For many videos, queue them and let the worker/scheduler drain them.'),
            ])
            ->action(function (array $data): void {
                Artisan::call('media:process-optimization-queue', [
                    '--max-jobs' => max(1, min(20, (int) ($data['max_jobs'] ?? 3))),
                ]);

                $this->notifyCommandResult('Optimization worker run finished', trim(Artisan::output()));
            });
    }

    public function retryFailedOptimizationsAction(): Action
    {
        return Action::make('retryFailedOptimizations')
            ->label('Retry failed optimizations')
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Retry failed optimization sources')
            ->form([
                TextInput::make('limit')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(5),
                TextInput::make('stale_minutes')
                    ->label('Stale minutes')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->default(30),
            ])
            ->action(function (array $data): void {
                Artisan::call('media:retry-failed-optimizations', [
                    '--limit' => max(1, min(100, (int) ($data['limit'] ?? 5))),
                    '--stale-minutes' => max(1, min(1440, (int) ($data['stale_minutes'] ?? 30))),
                ]);

                $this->notifyCommandResult('Failed optimizations retried', trim(Artisan::output()));
            });
    }

    public function reconcileImportsAction(): Action
    {
        return Action::make('reconcileImports')
            ->label('Reconcile stale imports')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('gray')
            ->requiresConfirmation()
            ->form([
                TextInput::make('minutes')
                    ->label('Older than minutes')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->default(30),
            ])
            ->action(function (array $data): void {
                Artisan::call('cdn:reconcile', [
                    '--minutes' => max(1, min(1440, (int) ($data['minutes'] ?? 30))),
                ]);

                $this->notifyCommandResult('Stale imports reconciled', trim(Artisan::output()));
            });
    }

    public function refreshAssetStatusesAction(): Action
    {
        return Action::make('refreshAssetStatuses')
            ->label('Refresh asset statuses')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('gray')
            ->requiresConfirmation()
            ->form([
                Toggle::make('importing_only')
                    ->label('Only stuck importing assets')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                Artisan::call('media:refresh-asset-statuses', [
                    '--importing-only' => (bool) ($data['importing_only'] ?? true),
                ]);

                $this->notifyCommandResult('Asset statuses refreshed', trim(Artisan::output()));
            });
    }

    public function refetchMissingMp4sAction(): Action
    {
        return Action::make('refetchMissingMp4s')
            ->label('Refetch missing MP4s')
            ->icon('heroicon-o-cloud-arrow-down')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Refetch missing MP4s from source URLs')
            ->modalDescription('Safe mode defaults to dry-run. Disable dry-run only when you are ready to queue refetch jobs or mark unreachable sources failed.')
            ->form([
                TextInput::make('limit')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(200)
                    ->default(20),
                Toggle::make('dry_run')
                    ->label('Dry run only')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                Artisan::call('media:refetch-missing-mp4s', [
                    '--limit' => max(0, min(200, (int) ($data['limit'] ?? 20))),
                    '--dry-run' => (bool) ($data['dry_run'] ?? true),
                ]);

                $this->notifyCommandResult('Missing MP4 refetch command finished', trim(Artisan::output()));
            });
    }

    public function syncTelegramImportsAction(): Action
    {
        return Action::make('syncTelegramImports')
            ->label('Sync Telegram imports')
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->form([
                TextInput::make('limit')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(20),
            ])
            ->action(function (array $data): void {
                Artisan::call('telegram-imports:sync', [
                    '--limit' => max(1, min(100, (int) ($data['limit'] ?? 20))),
                ]);

                $this->notifyCommandResult('Telegram imports synced', trim(Artisan::output()));
            });
    }
}

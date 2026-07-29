<?php

namespace App\Filament\Resources\StorageCleanupPlanResource\Pages;

use App\Filament\Resources\StorageCleanupPlanResource;
use App\Jobs\ExecuteStorageCleanupPlanJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStorageCleanupPlan extends ViewRecord
{
    protected static string $resource = StorageCleanupPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('executeApproved')
                ->label('Execute approved deletions')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Delete only approved, byte-verified, unreferenced duplicates. NBX will recheck every object and verify removal. Kept and unresolved objects are untouched.')
                ->visible(fn (): bool => $this->record->status === 'confirmed'
                    && $this->record->grace_expires_at?->isPast()
                    && $this->record->items()->where('status', 'approved')->exists())
                ->action(function (): void {
                    $this->record->update(['status' => 'queued']);
                    ExecuteStorageCleanupPlanJob::dispatch($this->record->id, auth()->id());
                    $this->record->refresh();
                    Notification::make()
                        ->success()
                        ->title('Approved cleanup queued')
                        ->body('The maintenance worker will recheck each object, delete only approved items, and verify removal.')
                        ->send();
                }),
            Actions\Action::make('cancel')
                ->label('Cancel cleanup review')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(function (): void {
                    $this->record->update(['status' => 'cancelled']);
                    Notification::make()
                        ->success()
                        ->title('Cleanup review cancelled — nothing was deleted')
                        ->send();
                    $this->record->refresh();
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\StorageCleanupPlanResource\RelationManagers;

use App\Jobs\VerifyStorageDuplicateGroupJob;
use App\Models\StorageCleanupPlanItem;
use App\Models\StorageInventoryObject;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Objects requiring review';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('object'))
            ->columns([
                Tables\Columns\TextColumn::make('object.filename')
                    ->label('Object')
                    ->description(fn ($record): string => (string) $record->object?->object_key)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('object.media_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', $state ?: 'unknown')),
                Tables\Columns\TextColumn::make('object.size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state): string => number_format(((int) $state) / 1048576, 2).' MB'),
                Tables\Columns\TextColumn::make('object.classification')
                    ->label('Lifecycle')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', $state ?: 'unresolved')),
                Tables\Columns\TextColumn::make('object.duplicate_evidence')
                    ->label('Duplicate evidence')
                    ->placeholder('None')
                    ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', $state ?: '')),
                Tables\Columns\TextColumn::make('object.content_sha256')
                    ->label('SHA-256')
                    ->limit(12)
                    ->copyable()
                    ->placeholder('Not verified'),
                Tables\Columns\TextColumn::make('object.media_source_id')->label('NBX source')->placeholder('—'),
                Tables\Columns\TextColumn::make('object.portal_source_id')->label('Portal source')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->actions([
                Tables\Actions\Action::make('verifyChecksum')
                    ->label('Verify SHA-256')
                    ->icon('heroicon-o-finger-print')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => filled($record->object?->duplicate_group_hash)
                        && $record->object?->duplicate_evidence !== 'sha256')
                    ->action(function ($record): void {
                        VerifyStorageDuplicateGroupJob::dispatch((string) $record->object->duplicate_group_hash);
                        Notification::make()
                            ->success()
                            ->title('Checksum verification queued')
                            ->body('Every object in this signature group will be streamed and SHA-256 verified. Nothing will be deleted.')
                            ->send();
                    }),
                Tables\Actions\Action::make('approveExactDuplicate')
                    ->label('Approve duplicate')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Approve this byte-identical, unreferenced object for deletion after the cleanup plan is confirmed. This action does not delete it.')
                    ->visible(fn ($record): bool => $record->status === 'pending'
                        && $this->getOwnerRecord()->status === 'draft'
                        && $this->getOwnerRecord()->grace_expires_at?->isPast()
                        && $record->object?->duplicate_evidence === 'sha256'
                        && $record->object?->classification === 'unresolved'
                        && ! $record->object?->media_source_id
                        && ! $record->object?->portal_source_id)
                    ->action(function ($record): void {
                        $object = $record->object?->fresh();
                        $plan = $this->getOwnerRecord()->fresh();
                        if (! $object || ! $plan || ! $plan->grace_expires_at?->isPast()) {
                            throw new \RuntimeException('The cleanup grace period has not completed.');
                        }
                        $matches = StorageInventoryObject::query()
                            ->where('content_sha256', $object->content_sha256)
                            ->where('size_bytes', $object->size_bytes)
                            ->where('duplicate_evidence', 'sha256')
                            ->whereNull('missing_since')
                            ->pluck('id');
                        $approved = StorageCleanupPlanItem::query()
                            ->where('storage_cleanup_plan_id', $plan->id)
                            ->whereIn('storage_inventory_object_id', $matches)
                            ->where('status', 'approved')
                            ->count();
                        if ($matches->count() < 2 || $approved >= $matches->count() - 1) {
                            throw new \RuntimeException('Approval refused: at least one verified byte-identical object must remain.');
                        }
                        $record->update([
                            'status' => 'approved',
                            'proposed_action' => 'delete_exact_duplicate',
                            'review_note' => 'SHA-256 match verified; no NBX or Portal source reference is present.',
                        ]);
                        $object->update([
                            'classification' => 'orphan_confirmed',
                            'confidence' => 'high',
                            'classification_reason' => 'Operator approved an unreferenced SHA-256 duplicate after the cleanup grace period.',
                        ]);

                        $this->confirmOwnerIfReviewed();
                        Notification::make()
                            ->success()
                            ->title('Duplicate approved — nothing deleted yet')
                            ->body('The plan can execute only after all remaining objects are marked Keep or approved.')
                            ->send();
                    }),
                Tables\Actions\Action::make('keep')
                    ->label('Keep')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status === 'pending' && $this->getOwnerRecord()->status === 'draft')
                    ->action(function ($record): void {
                        $record->update([
                            'status' => 'kept',
                            'review_note' => 'Operator chose to retain this object.',
                        ]);
                        $this->confirmOwnerIfReviewed();
                    }),
                Tables\Actions\Action::make('markForFurtherReview')
                    ->label('Needs evidence')
                    ->color('warning')
                    ->visible(fn ($record): bool => $record->status === 'pending' && $this->getOwnerRecord()->status === 'draft')
                    ->action(fn ($record) => $record->update([
                        'status' => 'needs_evidence',
                        'review_note' => 'Association or checksum evidence is required before any deletion approval.',
                    ])),
            ])
            ->bulkActions([])
            ->defaultPaginationPageOption(50);
    }

    private function confirmOwnerIfReviewed(): void
    {
        $plan = $this->getOwnerRecord()->fresh();
        $openItems = $plan->items()
            ->whereIn('status', ['pending', 'needs_evidence'])
            ->exists();
        if (! $openItems && $plan->items()->where('status', 'approved')->exists()) {
            $plan->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);
        }
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StorageCleanupPlanResource\Pages;
use App\Filament\Resources\StorageCleanupPlanResource\RelationManagers\ItemsRelationManager;
use App\Models\StorageCleanupPlan;
use App\Models\User;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StorageCleanupPlanResource extends Resource
{
    protected static ?string $model = StorageCleanupPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Storage';

    protected static ?string $navigationLabel = 'Cleanup reviews';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageStorage('storage.view');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cleanup review')
                ->schema([
                    Infolists\Components\TextEntry::make('logical_asset_key')->label('Storage package'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('risk_level')->badge(),
                    Infolists\Components\TextEntry::make('object_count')->numeric(),
                    Infolists\Components\TextEntry::make('total_bytes')
                        ->label('Total size')
                        ->formatStateUsing(fn (int $state): string => number_format($state / 1073741824, 3).' GB'),
                    Infolists\Components\TextEntry::make('grace_expires_at')->label('Review window ends')->dateTime(),
                    Infolists\Components\TextEntry::make('reason')->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Plan')->sortable(),
                Tables\Columns\TextColumn::make('logical_asset_key')->label('Package')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('risk_level')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'critical' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('object_count')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('total_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1073741824, 3).' GB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grace_expires_at')->label('Review after')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStorageCleanupPlans::route('/'),
            'view' => Pages\ViewStorageCleanupPlan::route('/{record}'),
        ];
    }
}

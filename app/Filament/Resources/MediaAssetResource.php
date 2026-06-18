<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaAssetResource\Pages;
use App\Filament\Resources\MediaAssetResource\RelationManagers\MediaSourcesRelationManager;
use App\Models\MediaAsset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'CDN';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->required()
                ->options([
                    'movie' => 'Movie',
                    'episode' => 'Episode',
                    'generic' => 'Generic',
                ])
                ->default('generic'),
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->rows(3),
            Forms\Components\Select::make('status')
                ->required()
                ->options([
                    'draft' => 'Draft',
                    'importing' => 'Importing',
                    'ready' => 'Ready',
                    'failed' => 'Failed',
                    'disabled' => 'Disabled',
                ])
                ->default('draft')
                ->disabled()
                ->dehydrated(fn (?MediaAsset $record): bool => $record === null)
                ->helperText('Status is managed automatically by the import system.'),
            Forms\Components\Select::make('visibility')
                ->required()
                ->options([
                    'public' => 'Public',
                    'unlisted' => 'Unlisted',
                ])
                ->default('public'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Asset ID')
                    ->searchable()
                    ->copyable()
                    ->limit(8),
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'importing' => 'warning',
                        'failed' => 'danger',
                        'disabled' => 'gray',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('visibility')
                    ->badge(),
                Tables\Columns\TextColumn::make('sources_count')
                    ->counts('sources')
                    ->label('Sources'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('has_failed_optimization')
                    ->label('Has failed optimization')
                    ->placeholder('All assets')
                    ->trueLabel('With failed sources only')
                    ->falseLabel('Without failed sources')
                    ->queries(
                        true: fn ($query) => $query->whereHas('sources', fn ($q) => $q->where('status', 'ready')->where('optimize_status', 'failed')),
                        false: fn ($query) => $query->whereDoesntHave('sources', fn ($q) => $q->where('status', 'ready')->where('optimize_status', 'failed')),
                    )
                    ->indicator('Failed optimization'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MediaSourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaAssets::route('/'),
            'create' => Pages\CreateMediaAsset::route('/create'),
            'view' => Pages\ViewMediaAsset::route('/{record}'),
            'edit' => Pages\EditMediaAsset::route('/{record}/edit'),
        ];
    }
}


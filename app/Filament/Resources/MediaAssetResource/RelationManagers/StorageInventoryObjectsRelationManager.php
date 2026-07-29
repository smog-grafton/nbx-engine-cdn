<?php

namespace App\Filament\Resources\MediaAssetResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StorageInventoryObjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'storageInventoryObjects';

    protected static ?string $title = 'Contabo storage packages';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('object_key')
            ->modifyQueryUsing(fn ($query) => $query->whereNull('missing_since'))
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->description(fn ($record): string => $record->object_key)
                    ->searchable(['filename', 'object_key'])
                    ->wrap(),
                Tables\Columns\TextColumn::make('media_role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),
                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => $this->humanBytes($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('classification')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'managed' => 'success',
                        'active_processing' => 'info',
                        'failed_residue_candidate' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),
                Tables\Columns\IconColumn::make('is_duplicate_candidate')
                    ->label('Duplicate signature')
                    ->boolean(),
                Tables\Columns\TextColumn::make('object_last_modified_at')
                    ->label('Modified')
                    ->dateTime()
                    ->sortable(),
            ])
            ->groups([
                Tables\Grouping\Group::make('logical_asset_key')
                    ->label('Storage package')
                    ->collapsible(),
            ])
            ->defaultGroup('logical_asset_key')
            ->filters([
                Tables\Filters\SelectFilter::make('media_role')
                    ->options([
                        'source_original' => 'Original',
                        'faststart_mp4' => 'Fast Start MP4',
                        'hls_master' => 'HLS master',
                        'hls_variant' => 'HLS variant',
                        'hls_segment' => 'HLS segment',
                        'subtitle' => 'Subtitle',
                        'thumbnail' => 'Thumbnail',
                        'unknown' => 'Unknown',
                    ]),
                Tables\Filters\SelectFilter::make('classification')
                    ->options([
                        'managed' => 'Managed',
                        'active_processing' => 'Active processing',
                        'failed_residue_candidate' => 'Failed residue candidate',
                        'nbx_unresolved' => 'NBX unresolved',
                        'unresolved' => 'Unresolved',
                    ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('No indexed Contabo objects')
            ->emptyStateDescription('Run a read-only storage inventory to link this asset to originals, Fast Start MP4, and HLS package objects.');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        return number_format($bytes / 1024, 2).' KB';
    }
}

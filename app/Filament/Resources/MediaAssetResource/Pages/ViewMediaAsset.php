<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMediaAsset extends ViewRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}


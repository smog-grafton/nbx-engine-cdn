<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}


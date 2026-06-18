<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Asset created. Add sources in the Media Sources tab; optimization (faststart + HLS) runs automatically when a source is ready.';
    }
}


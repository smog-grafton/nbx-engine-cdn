<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Storage\AutomaticStorageSelector;
use Filament\Pages\Page;

class StorageTargetsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Storage';

    protected static ?string $navigationLabel = 'Storage targets';

    protected static ?string $title = 'Storage targets';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.storage-targets';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageStorage('storage.view');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTargets(): array
    {
        return app(AutomaticStorageSelector::class)->statusForAllTargets();
    }
}

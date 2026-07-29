<?php

namespace App\Jobs;

use App\Services\StorageInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyStorageDuplicateGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 21600;

    public function __construct(public string $groupHash)
    {
        $this->onQueue((string) config('nbx.storage_inventory_queue', 'media-maintenance'));
    }

    public function handle(StorageInventoryService $inventory): void
    {
        $inventory->verifyDuplicateGroup($this->groupHash);
    }
}

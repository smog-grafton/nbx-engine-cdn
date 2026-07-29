<?php

namespace App\Jobs;

use App\Models\StorageInventoryRun;
use App\Services\StorageInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanContaboStorageInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(public int $runId)
    {
        $this->onQueue((string) config('nbx.storage_inventory_queue', 'media-maintenance'));
    }

    public function handle(StorageInventoryService $inventory): void
    {
        $run = StorageInventoryRun::query()->findOrFail($this->runId);
        $inventory->scan($run);
    }
}

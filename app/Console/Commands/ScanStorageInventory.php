<?php

namespace App\Console\Commands;

use App\Services\StorageInventoryService;
use Illuminate\Console\Command;

class ScanStorageInventory extends Command
{
    protected $signature = 'nbx:storage-inventory
        {--prefix= : Restrict the scan to a storage prefix}
        {--sync : Run in this process instead of dispatching to the maintenance queue}';

    protected $description = 'Build a read-only indexed inventory of Contabo objects and logical media packages';

    public function handle(StorageInventoryService $inventory): int
    {
        $run = $inventory->createRun((string) $this->option('prefix'));
        if ($this->option('sync')) {
            $inventory->scan($run);
            $run->refresh();
            $this->info(sprintf(
                'Inventory #%d completed: %d objects, %.2f GB.',
                $run->id,
                $run->object_count,
                $run->total_bytes / 1073741824,
            ));

            return self::SUCCESS;
        }

        \App\Jobs\ScanContaboStorageInventoryJob::dispatch($run->id);
        $this->info("Inventory #{$run->id} was queued.");

        return self::SUCCESS;
    }
}

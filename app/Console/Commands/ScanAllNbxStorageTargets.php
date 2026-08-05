<?php

namespace App\Console\Commands;

use App\Jobs\ScanContaboStorageInventoryJob;
use App\Services\Storage\StorageTargetRegistry;
use App\Services\StorageInventoryService;
use Illuminate\Console\Command;

class ScanAllNbxStorageTargets extends Command
{
    protected $signature = 'nbx:storage-inventory-all-targets
        {--prefix= : Restrict the scan to a storage prefix}
        {--sync : Run in this process instead of dispatching to the maintenance queue}';

    protected $description = 'Scan every Contabo bucket NBX itself owns (config/storage_targets.php), not just the legacy single-disk default';

    public function handle(StorageInventoryService $inventory, StorageTargetRegistry $registry): int
    {
        $prefix = (string) $this->option('prefix');
        $failed = false;

        foreach ($registry->all() as $target) {
            $this->info("Scanning target [{$target->key}] (disk: {$target->disk}, bucket: {$target->bucket})...");

            $run = $inventory->createRun($prefix, $target->disk);

            if ($this->option('sync')) {
                try {
                    $inventory->scan($run);
                    $run->refresh();
                    $this->info(sprintf(
                        'Inventory #%d for [%s] completed: %d objects, %.2f GB.',
                        $run->id,
                        $target->key,
                        $run->object_count,
                        $run->total_bytes / 1073741824,
                    ));
                } catch (\Throwable $exception) {
                    $failed = true;
                    $this->error("Inventory for [{$target->key}] failed: {$exception->getMessage()}");
                }

                continue;
            }

            ScanContaboStorageInventoryJob::dispatch($run->id);
            $this->info("Inventory #{$run->id} for [{$target->key}] was queued.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

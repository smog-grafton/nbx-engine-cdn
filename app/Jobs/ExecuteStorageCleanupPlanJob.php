<?php

namespace App\Jobs;

use App\Models\StorageCleanupPlan;
use App\Services\StorageDeletionService;
use App\Services\StorageInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteStorageCleanupPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $planId, public ?int $userId)
    {
        $this->onQueue((string) config('nbx.storage_inventory_queue', 'media-maintenance'));
    }

    public function handle(StorageDeletionService $deletions, StorageInventoryService $inventory): void
    {
        $plan = StorageCleanupPlan::query()->findOrFail($this->planId);
        if ($plan->status !== 'queued' || ! $plan->confirmed_at || ! $plan->grace_expires_at?->isPast()) {
            throw new \RuntimeException('Cleanup execution refused: the plan is not confirmed or its grace period is incomplete.');
        }

        $deleted = 0;
        $failed = 0;
        $items = $plan->items()->with('object')->where('status', 'approved')->get();
        foreach ($items as $item) {
            $object = $item->object;
            if (! $object) {
                $item->update(['status' => 'failed', 'review_note' => 'Inventory object no longer exists.']);
                $failed++;

                continue;
            }
            try {
                $deletions->deleteConfirmedOrphan(
                    $object->storage_disk,
                    $object->object_key,
                    ['user_id' => $this->userId, 'media_api_token_id' => null],
                    hash('sha256', 'cleanup-plan|'.$plan->id.'|'.$object->object_hash),
                );
                $item->update(['status' => 'deleted']);
                $object->update(['missing_since' => now()]);
                $deleted++;
            } catch (\Throwable $exception) {
                $item->update([
                    'status' => 'failed',
                    'review_note' => $exception->getMessage(),
                ]);
                $failed++;
            }
        }
        $plan->update([
            'status' => $failed > 0 ? 'partially_failed' : 'completed',
            'reason' => trim(($plan->reason ? $plan->reason.' ' : '')."Execution result: {$deleted} deleted, {$failed} refused."),
            'executed_at' => now(),
        ]);
        $inventory->queue(rtrim($plan->logical_asset_key, '/').'/');
    }

    public function failed(?Throwable $exception): void
    {
        StorageCleanupPlan::query()->whereKey($this->planId)->update([
            'status' => 'partially_failed',
            'reason' => 'Cleanup worker failed before completion: '.($exception?->getMessage() ?: 'unknown error'),
            'executed_at' => now(),
        ]);
    }
}

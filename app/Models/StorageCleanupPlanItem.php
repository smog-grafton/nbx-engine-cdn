<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageCleanupPlanItem extends Model
{
    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StorageCleanupPlan::class, 'storage_cleanup_plan_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(StorageInventoryObject::class, 'storage_inventory_object_id');
    }
}

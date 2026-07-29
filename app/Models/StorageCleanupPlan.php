<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageCleanupPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_count' => 'integer',
            'total_bytes' => 'integer',
            'grace_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorageCleanupPlanItem::class);
    }
}

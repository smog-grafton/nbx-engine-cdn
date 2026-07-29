<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageInventoryRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_count' => 'integer',
            'total_bytes' => 'integer',
            'pages_scanned' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function objects(): HasMany
    {
        return $this->hasMany(StorageInventoryObject::class, 'last_seen_run_id');
    }
}

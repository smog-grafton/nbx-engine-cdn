<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageInventoryObject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_manifest_member' => 'boolean',
            'is_duplicate_candidate' => 'boolean',
            'metadata' => 'array',
            'object_last_modified_at' => 'datetime',
            'checksum_verified_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function mediaSource(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class);
    }

    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(StorageInventoryRun::class, 'last_seen_run_id');
    }
}

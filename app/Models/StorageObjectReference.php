<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageObjectReference extends Model
{
    protected $fillable = [
        'reference_key',
        'media_asset_id',
        'media_source_id',
        'portal_source_id',
        'portal_sourceable_type',
        'portal_sourceable_id',
        'storage_disk',
        'storage_bucket',
        'object_key',
        'object_url',
        'media_role',
        'is_external_direct',
        'is_primary',
        'is_active',
        'health_status',
        'last_verified_at',
        'deleted_at_storage',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_external_direct' => 'boolean',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'last_verified_at' => 'datetime',
            'deleted_at_storage' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mediaSource(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}

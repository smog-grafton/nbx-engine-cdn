<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageActionAudit extends Model
{
    protected $fillable = [
        'user_id',
        'media_api_token_id',
        'idempotency_key',
        'action',
        'target_type',
        'target_id',
        'storage_disk',
        'storage_bucket',
        'object_key',
        'bytes_freed',
        'status',
        'before_state',
        'after_state',
        'failure_reason',
        'confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes_freed' => 'integer',
            'before_state' => 'array',
            'after_state' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}

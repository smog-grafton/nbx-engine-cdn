<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemoteFetchSession extends Model
{
    protected $fillable = [
        'media_source_id',
        'original_url',
        'final_url',
        'expected_size',
        'etag',
        'last_modified',
        'strategy',
        'status',
        'bytes_downloaded',
        'attempts',
        'consecutive_failures',
        'last_error',
        'last_heartbeat_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_size' => 'integer',
            'bytes_downloaded' => 'integer',
            'attempts' => 'integer',
            'consecutive_failures' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function mediaSource(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(RemoteFetchPart::class);
    }
}

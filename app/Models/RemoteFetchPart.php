<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteFetchPart extends Model
{
    protected $fillable = [
        'remote_fetch_session_id',
        'start_byte',
        'end_byte',
        'downloaded_bytes',
        'attempts',
        'status',
        'checksum',
        'last_error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_byte' => 'integer',
            'end_byte' => 'integer',
            'downloaded_bytes' => 'integer',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RemoteFetchSession::class, 'remote_fetch_session_id');
    }
}

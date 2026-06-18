<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_asset_id',
        'source_type',
        'source_url',
        'storage_disk',
        'storage_path',
        'original_storage_path',
        'mime_type',
        'file_size_bytes',
        'duration_seconds',
        'checksum',
        'status',
        'failure_reason',
        'last_error',
        'last_attempt_host',
        'is_faststart',
        'compress_enabled',
        'optimize_status',
        'optimize_retry_count',
        'optimized_path',
        'optimize_error',
        'optimized_at',
        'playback_type',
        'hls_master_path',
        'qualities_json',
        'hls_worker_status',
        'hls_worker_artifact_url',
        'hls_worker_artifact_expires_at',
        'hls_worker_last_error',
        'hls_worker_external_id',
        'hls_worker_quality_status',
        'external_job_id',
        'progress_percent',
        'bytes_downloaded',
        'bytes_total',
        'started_at',
        'last_progress_at',
        'completed_at',
        'is_active',
        'source_metadata',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'progress_percent' => 'integer',
            'bytes_downloaded' => 'integer',
            'bytes_total' => 'integer',
            'started_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'completed_at' => 'datetime',
            'optimized_at' => 'datetime',
            'hls_worker_artifact_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'is_faststart' => 'boolean',
            'compress_enabled' => 'boolean',
            'qualities_json' => 'array',
            'source_metadata' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}


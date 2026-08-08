<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MediaSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_asset_id',
        'source_type',
        'source_url',
        'storage_disk',
        'storage_target_key',
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
        'processing_stage',
        'processing_attempt_id',
        'processing_attempt_started_at',
        'processing_stage_progress',
        'processing_heartbeat_at',
        'processing_diagnostics',
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
        'idempotency_key',
        'processing_revision',
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
            'processing_revision' => 'integer',
            'progress_percent' => 'integer',
            'bytes_downloaded' => 'integer',
            'bytes_total' => 'integer',
            'started_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'completed_at' => 'datetime',
            'optimized_at' => 'datetime',
            'processing_stage_progress' => 'integer',
            'processing_heartbeat_at' => 'datetime',
            'processing_attempt_started_at' => 'datetime',
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

    /**
     * Simple linear extrapolation from elapsed time and current progress.
     * Deliberately not stage-weighted (fetch/compress/upload move at very
     * different rates) — it's a rough estimate, not a guarantee, and gets
     * more accurate as the attempt progresses. Returns null whenever there
     * isn't enough signal yet for a meaningful number rather than showing a
     * wildly wrong one.
     */
    public function estimatedSecondsRemaining(): ?int
    {
        if (! in_array($this->optimize_status, ['pending', 'processing'], true)) {
            return null;
        }
        if (! $this->processing_attempt_started_at) {
            return null;
        }

        $progress = (int) ($this->progress_percent ?? 0);
        if ($progress <= 0 || $progress >= 100) {
            return null;
        }

        $elapsed = $this->processing_attempt_started_at->diffInSeconds(now());
        if ($elapsed < 10) {
            return null;
        }

        return max(0, (int) round($elapsed * (100 - $progress) / $progress));
    }

    public function estimatedTimeRemainingLabel(): ?string
    {
        $seconds = $this->estimatedSecondsRemaining();
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 60) {
            return 'Less than a minute remaining';
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "~{$minutes}m remaining";
        }

        $hours = intdiv($minutes, 60);
        $remainderMinutes = $minutes % 60;

        return $remainderMinutes > 0 ? "~{$hours}h {$remainderMinutes}m remaining" : "~{$hours}h remaining";
    }

    /**
     * Human-readable explanation of what actually happened during
     * optimization when compression was requested. "Optimize ready" alone
     * looks identical whether the video was actually compressed, whether
     * compression ran but didn't shrink the file (so a plain remux was kept
     * instead — see OptimizeMp4FaststartJob's smaller-fallback logic), or
     * whether it was skipped because the source was already efficient —
     * all three get reported by operators as "compression isn't working."
     */
    public function compressionOutcomeLabel(): ?string
    {
        if (! $this->compress_enabled) {
            return null;
        }
        if ($this->optimize_status === 'failed') {
            return 'Compression was requested but the attempt failed — see the error for the stage/reason.';
        }

        $metadata = (array) ($this->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $mode = (string) ($nbx['processing_result']['processing_mode'] ?? $metadata['processing_result']['processing_mode'] ?? '');

        return match ($mode) {
            'video_transcode' => 'Compressed.',
            'remux_fallback_smaller' => 'Compression ran but did not reduce file size, so the compatible remux was kept instead.',
            'remux', 'remux_already_efficient' => 'Compression was skipped: the source was already within the configured efficient-bitrate threshold (CDN_COMPRESS_SKIP_BITRATE_*), so only a fast-start remux ran.',
            'audio_transcode' => 'Only the audio track was re-encoded; the video was already compatible.',
            default => null,
        };
    }

    public function remoteFetchSession(): HasOne
    {
        return $this->hasOne(RemoteFetchSession::class);
    }
}

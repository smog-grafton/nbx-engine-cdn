<?php

namespace App\Services;

use App\Models\MediaSource;

/**
 * Keeps acquisition/original/optimization/publication state independent.
 *
 * A failed derivative is recoverable when the acquired original still exists,
 * so it must never overwrite the source-ready state exposed to Portal.
 */
class PipelineStateService
{
    public function markOptimizationFailed(MediaSource $source, string $stage, string $message, array $extra = []): MediaSource
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $isNbxManaged = ($metadata['provider'] ?? null) === 'nbx_engine' || isset($metadata['nbx']);
        $previousDiagnostics = (string) ($source->processing_diagnostics ?? '');
        $diagnostics = (string) ($extra['diagnostics'] ?? $message);
        if ($isNbxManaged) {
            $nbx['status'] = 'original_ready';
            $nbx['message'] = 'Original acquired; optimization failed and can be retried without re-fetching.';
            $nbx['optimization'] = array_filter([
                'status' => 'failed',
                'stage' => $stage,
                'error' => $message,
                'failed_at' => now()->toIso8601String(),
                'duration_validation' => $extra['duration_validation'] ?? null,
                'input_probe' => $extra['input_probe'] ?? null,
                'output_probe' => $extra['output_probe'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
            $metadata['provider'] = 'nbx_engine';
            $metadata['nbx'] = $nbx;
        }

        $source->update([
            // The fetched source is still valid. Do not collapse it into a
            // terminal source failure just because a derivative failed.
            'status' => $source->status === 'ready' ? 'ready' : $source->status,
            'failure_reason' => $source->status === 'ready' ? null : $source->failure_reason,
            'optimize_status' => 'failed',
            'optimize_error' => mb_substr($message, -12000),
            'is_faststart' => false,
            'processing_stage' => 'optimization_failed',
            'processing_stage_progress' => null,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => mb_substr($diagnostics !== '' ? $diagnostics : $previousDiagnostics, -12000),
            'ffmpeg_pid' => null,
            'last_progress_at' => now(),
            'source_metadata' => $metadata,
        ]);

        return $source->fresh() ?? $source;
    }

    public function markOptimizationActive(MediaSource $source, string $stage, ?string $message = null): MediaSource
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $isNbxManaged = ($metadata['provider'] ?? null) === 'nbx_engine' || isset($metadata['nbx']);
        if (! $isNbxManaged) {
            return $source;
        }
        $nbx['status'] = $stage;
        $nbx['optimization'] = array_filter([
            'status' => 'processing',
            'stage' => $stage,
            'started_at' => $nbx['optimization']['started_at'] ?? now()->toIso8601String(),
            'message' => $message,
        ], static fn (mixed $value): bool => $value !== null);
        $metadata['provider'] = 'nbx_engine';
        $metadata['nbx'] = $nbx;
        $source->update(['source_metadata' => $metadata]);

        return $source->fresh() ?? $source;
    }
}

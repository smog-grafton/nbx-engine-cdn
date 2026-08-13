<?php

namespace App\Console\Commands;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Services\OptimizationQueueInspector;
use App\Services\PipelineStateService;
use App\Services\ProcessingStateInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverStaleOptimizations extends Command
{
    protected $signature = 'media:recover-stale-optimizations
        {--limit=5 : Maximum sources to recover}
        {--stale-minutes=20 : Minutes without an optimization heartbeat}
        {--max-retries=10 : Maximum automatic recovery attempts}
        {--source= : Recover one media source ID}
        {--job= : Recover one NBX job UUID}';

    protected $description = 'Safely requeue abandoned optimization jobs whose worker/queue evidence disappeared';

    public function handle(
        MediaSourceService $media,
        ProcessingStateInspector $inspector,
        PipelineStateService $pipeline,
        OptimizationQueueInspector $queueInspector,
        NbxEngineService $nbx,
    ): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $staleMinutes = max(5, min(1440, (int) $this->option('stale-minutes')));
        $maxRetries = max(1, min(50, (int) $this->option('max-retries')));
        $cutoff = now()->subMinutes($staleMinutes);
        $queuedGraceSeconds = max(30, (int) config('cdn.queued_optimization_missing_job_grace_seconds', 120));
        $reservedRescueSeconds = max($queuedGraceSeconds, (int) config('cdn.queued_optimization_reserved_rescue_seconds', 300));
        $queuedCutoff = now()->subSeconds($queuedGraceSeconds);

        $query = MediaSource::query()
            ->where('status', 'ready')
            ->whereIn('optimize_status', ['pending', 'processing'])
            ->where(function ($query) use ($cutoff, $queuedCutoff): void {
                $query
                    ->where(function ($query) use ($queuedCutoff): void {
                        $query->where('processing_stage', 'queued')
                            ->where(function ($query) use ($queuedCutoff): void {
                                $query->where('processing_heartbeat_at', '<', $queuedCutoff)
                                    ->orWhere(function ($query) use ($queuedCutoff): void {
                                        $query->whereNull('processing_heartbeat_at')->where('updated_at', '<', $queuedCutoff);
                                    });
                            });
                    })
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->where(function ($query): void {
                            $query->whereNull('processing_stage')
                                ->orWhere('processing_stage', '!=', 'queued');
                        })->where(function ($query) use ($cutoff): void {
                            $query->where('processing_heartbeat_at', '<', $cutoff)
                                ->orWhere(function ($query) use ($cutoff): void {
                                    $query->whereNull('processing_heartbeat_at')
                                        ->where(function ($query) use ($cutoff): void {
                                            $query->where('last_progress_at', '<', $cutoff)
                                                ->orWhere(function ($query) use ($cutoff): void {
                                                    $query->whereNull('last_progress_at')->where('updated_at', '<', $cutoff);
                                                });
                                        });
                                });
                            });
                    });
            })
            ->orderBy('processing_heartbeat_at')
            ->limit($limit);

        if ($this->option('source')) {
            $query->whereKey((int) $this->option('source'));
        }
        if ($this->option('job')) {
            $query->where('external_job_id', trim((string) $this->option('job')));
        }

        $sources = $query->get();
        $requeued = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            $stage = (string) ($source->processing_stage ?? '');
            if ($stage === 'queued') {
                $queuedAt = $source->processing_heartbeat_at ?: $source->updated_at;
                $queuedAgeSeconds = $queuedAt ? max(0, time() - $queuedAt->getTimestamp()) : $queuedGraceSeconds;
                $attemptJob = $queueInspector->currentAttemptJob($source);

                if ($attemptJob !== null) {
                    $health = $inspector->inspect($source);
                    if (
                        $attemptJob['state'] === 'reserved'
                        && (int) ($attemptJob['reserved_for_seconds'] ?? 0) >= $reservedRescueSeconds
                        && ! $health['active']
                    ) {
                        $deleted = $queueInspector->deleteJob($attemptJob['id']);
                        Log::warning('RecoverStaleOptimizations: reserved queued job had no worker heartbeat; deleting stuck queue row before requeue', [
                            'source_id' => $source->id,
                            'asset_id' => $source->media_asset_id,
                            'queue_job_id' => $attemptJob['id'],
                            'reserved_for_seconds' => $attemptJob['reserved_for_seconds'],
                            'deleted' => $deleted,
                        ]);
                    } else {
                        if ($attemptJob['state'] === 'reserved') {
                            $this->line(sprintf(
                                'Source #%d: still queued; matching Laravel job #%d is reserved for %d second(s). Skipping recovery.',
                                $source->id,
                                $attemptJob['id'],
                                (int) ($attemptJob['reserved_for_seconds'] ?? 0),
                            ));
                            $skipped++;

                            continue;
                        }

                    $this->line(sprintf(
                        'Source #%d: still queued; matching Laravel job #%d is %s%s. Skipping recovery.',
                        $source->id,
                        $attemptJob['id'],
                        $attemptJob['state'],
                        $attemptJob['state'] === 'delayed' ? ' for '.$attemptJob['available_in_seconds'].' more second(s)' : '',
                    ));
                    $skipped++;

                    continue;
                    }
                }

                if ($queuedAgeSeconds < $queuedGraceSeconds) {
                    $this->line("Source #{$source->id}: queued for {$queuedAgeSeconds}s; waiting for {$queuedGraceSeconds}s grace before recovery.");
                    $skipped++;

                    continue;
                }

                $attempts = (int) ($source->optimize_retry_count ?? 0);
                if ($attempts >= $maxRetries) {
                    $this->warn("Source #{$source->id}: queued attempt is missing its Laravel job, but retry limit reached; leaving original ready for manual recovery.");
                    $failed++;

                    continue;
                }

                $source->update([
                    'optimize_status' => 'failed',
                    'optimize_error' => 'Queued optimization attempt was abandoned: no matching Laravel queue job was found.',
                    'processing_stage' => 'optimization_requeue_pending',
                    'processing_stage_progress' => null,
                    'processing_heartbeat_at' => now(),
                    'processing_diagnostics' => 'Stale recovery could not find a matching database queue row for this processing_attempt_id.',
                    'optimize_retry_count' => $attempts + 1,
                    'last_progress_at' => now(),
                ]);

                Log::warning('RecoverStaleOptimizations: queued source had no matching Laravel job; requeueing fresh attempt', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'previous_attempt_id' => $source->processing_attempt_id,
                    'queued_age_seconds' => $queuedAgeSeconds,
                ]);

                if ($media->queuePlaybackProcessing($source->fresh() ?? $source)) {
                    $nbx->markNbxStatus(
                        $source->fresh(),
                        'pending',
                        'Abandoned queued optimization recovered and queued with a new attempt.',
                    );
                    $this->info("Source #{$source->id}: queued attempt was abandoned; queued a fresh optimization attempt.");
                    $requeued++;
                } else {
                    $this->warn("Source #{$source->id}: abandoned queued attempt could not be requeued.");
                    $skipped++;
                }

                continue;
            }

            // Liveness check: OptimizeMp4FaststartJob/GenerateHlsVariantsJob
            // refresh this short-TTL marker on every heartbeat and stage
            // transition. If it's still fresh, the job is genuinely alive
            // (e.g. past a slow non-heartbeating step, or just past the
            // 'queued' stage boundary) and must not be declared failed out
            // from under it — only a truly expired marker means dead.
            $health = $inspector->inspect($source);
            if ($health['active']) {
                $source->update([
                    'current_output_size_bytes' => $health['output_bytes'] > 0 ? $health['output_bytes'] : $source->current_output_size_bytes,
                    'output_size_observed_at' => $health['output_bytes'] > 0 ? now() : $source->output_size_observed_at,
                ]);
                $this->warn("Source #{$source->id}: {$health['message']} Skipping recovery.");
                $skipped++;

                continue;
            }

            // Restore a durable original/mislabeled legacy artifact before it is
            // removed from playable output metadata.
            $source = $media->ensureLocalWorkFileForProcessing($source) ?: $source;
            $attempts = (int) ($source->optimize_retry_count ?? 0);
            $metadata = (array) ($source->source_metadata ?? []);
            $artifact = (array) ($metadata['nbx']['final_artifacts']['faststart'] ?? []);
            $artifactPath = strtolower((string) parse_url((string) ($artifact['key'] ?? $artifact['url'] ?? ''), PHP_URL_PATH));

            if ($artifact !== [] && ! str_ends_with($artifactPath, '.mp4')) {
                $metadata['nbx']['quarantined_artifacts']['faststart'][] = array_merge($artifact, [
                    'reason' => 'Legacy artifact was published before MP4 normalization completed.',
                    'quarantined_at' => now()->toIso8601String(),
                ]);
                unset($metadata['nbx']['final_artifacts']['faststart']);
            }

            $reason = sprintf(
                'Optimization heartbeat stopped during %s after %d minute(s).',
                $source->processing_stage ?: 'processing',
                $staleMinutes,
            );
            $source->update(['source_metadata' => $metadata, 'optimize_retry_count' => $attempts + 1]);
            $source = $pipeline->markOptimizationFailed($source, 'stalled', $reason, ['diagnostics' => $reason]);

            if ($attempts >= $maxRetries) {
                $this->warn("Source #{$source->id}: retry limit reached; original remains available for manual recovery.");
                $failed++;
                continue;
            }

            $source = $source->fresh();
            if (! $source?->storage_path) {
                $this->warn('Source #'.($source?->id ?? 'unknown').': no recoverable input.');
                $failed++;
                continue;
            }

            if ($media->queuePlaybackProcessing($source)) {
                $nbx->markNbxStatus(
                    $source->fresh(),
                    'pending',
                    'Stale optimization recovered and queued with a new attempt.',
                );
                $this->info("Source #{$source->id}: queued a new optimization attempt.");
                $requeued++;
            } else {
                $this->warn("Source #{$source->id}: could not be queued.");
                $skipped++;
            }
        }

        $this->info("Recovered {$requeued}; failed {$failed}; skipped {$skipped}; inspected {$sources->count()}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Services\ProcessingLiveness;
use Illuminate\Console\Command;

class RecoverStaleOptimizations extends Command
{
    protected $signature = 'media:recover-stale-optimizations
        {--limit=5 : Maximum sources to recover}
        {--stale-minutes=20 : Minutes without an optimization heartbeat}
        {--max-retries=10 : Maximum automatic recovery attempts}
        {--source= : Recover one media source ID}
        {--job= : Recover one NBX job UUID}';

    protected $description = 'Fail and safely requeue optimization jobs whose FFmpeg heartbeat stopped';

    public function handle(MediaSourceService $media, NbxEngineService $nbx): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $staleMinutes = max(5, min(1440, (int) $this->option('stale-minutes')));
        $maxRetries = max(1, min(50, (int) $this->option('max-retries')));
        $cutoff = now()->subMinutes($staleMinutes);

        $query = MediaSource::query()
            ->where('status', 'ready')
            ->whereIn('optimize_status', ['pending', 'processing'])
            // A source still in 'queued' hasn't been picked up by a worker
            // yet — its heartbeat reflects dispatch time, not a stuck
            // process. Under CDN_SERIALIZE_OPTIMIZATION_JOBS=true, queue
            // depth alone can exceed --stale-minutes long before the job
            // ever starts; reaping it here just churns a fresh attempt to
            // the back of the same queue. NULL is left reapable for rows
            // predating this column.
            ->where('processing_stage', '!=', 'queued')
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('processing_heartbeat_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('processing_heartbeat_at')
                            ->where(function ($query) use ($cutoff): void {
                                $query->where('last_progress_at', '<', $cutoff)
                                    ->orWhere(function ($query) use ($cutoff): void {
                                        $query->whereNull('last_progress_at')->where('updated_at', '<', $cutoff);
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
            // Liveness check: OptimizeMp4FaststartJob/GenerateHlsVariantsJob
            // refresh this short-TTL marker on every heartbeat and stage
            // transition. If it's still fresh, the job is genuinely alive
            // (e.g. past a slow non-heartbeating step, or just past the
            // 'queued' stage boundary) and must not be declared failed out
            // from under it — only a truly expired marker means dead.
            if (ProcessingLiveness::isAlive($source->id)) {
                $this->warn("Source #{$source->id}: still alive (liveness marker fresh); skipping.");
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
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => $reason,
                'processing_stage' => 'failed',
                'processing_stage_progress' => null,
                'processing_heartbeat_at' => now(),
                'processing_diagnostics' => $reason,
                'source_metadata' => $metadata,
                'optimize_retry_count' => $attempts + 1,
            ]);

            if ($attempts >= $maxRetries) {
                $nbx->markNbxStatus($source->fresh(), 'failed', $reason.' Automatic retry limit reached.');
                $this->warn("Source #{$source->id}: retry limit reached.");
                $failed++;
                continue;
            }

            $source = $source->fresh();
            if (! $source?->storage_path) {
                $nbx->markNbxStatus($source, 'failed', $reason.' No recoverable input file was found.');
                $this->warn("Source #{$source->id}: no recoverable input.");
                $failed++;
                continue;
            }

            if ($media->queuePlaybackProcessing($source)) {
                $nbx->markNbxStatus($source->fresh(), 'pending', 'Stale optimization recovered and queued with a new attempt.');
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

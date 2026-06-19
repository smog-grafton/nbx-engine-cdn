<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessHlsAfterFaststartJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public int $sourceId)
    {
        $this->onQueue((string) config('cdn.optimization_queue', 'optimization'));
    }

    public function uniqueId(): string
    {
        return 'optimization:after-faststart:' . $this->sourceId;
    }

    public function middleware(): array
    {
        $locks = [
            (new WithoutOverlapping('optimization:source:' . $this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->dontRelease(),
        ];

        if ((bool) config('cdn.serialize_optimization_jobs', true)) {
            $locks[] = (new WithoutOverlapping('optimization:global'))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->releaseAfter(30);
        }

        return $locks;
    }

    public function handle(MediaSourceService $mediaSourceService): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready') {
            return;
        }

        // Do not run HLS when faststart already failed (e.g. corrupt/incomplete MP4 – moov atom not found).
        if ($source->optimize_status === 'failed') {
            return;
        }

        $workerEnabled = (bool) config('cdn.laravel_worker_enabled', false);
        $pullEnabled = (bool) config('cdn.laravel_worker_pull_enabled', true);
        $enableHls = (bool) config('cdn.enable_hls', true);

        // Verify the input file we'll use for HLS actually exists before dispatching.
        $source = $mediaSourceService->ensureLocalWorkFileForProcessing($source) ?: $source;
        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        $inputPath = $source->optimized_path ?: $source->storage_path;
        if (! $inputPath || ! Storage::disk($disk)->exists($inputPath)) {
            Log::warning('ProcessHlsAfterFaststartJob: input file missing, cannot generate HLS', [
                'source_id'    => $source->id,
                'asset_id'     => $source->media_asset_id,
                'input_path'   => $inputPath,
                'storage_path' => $source->storage_path,
                'optimized_path' => $source->optimized_path,
                'original_storage_path' => $source->original_storage_path,
            ]);
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error'  => 'HLS step skipped: input file not found on disk. Original may have been deleted.',
            ]);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source->fresh() ?? $source, 'job.partially_completed', [
                'reason' => 'HLS step skipped: input file not found on disk.',
            ]);
            return;
        }

        if ($workerEnabled && $pullEnabled && $enableHls) {
            $source->update(['hls_worker_status' => 'queued']);
            if ($mediaSourceService->queueLaravelWorkerPlaybackProcessing($source)) {
                $source->update(['optimize_status' => 'processing']);
                Log::info('ProcessHlsAfterFaststartJob: dispatched to Laravel worker (pull mode)', [
                    'source_id' => $source->id,
                    'asset_id'  => $source->media_asset_id,
                ]);
                return;
            }
            // Worker submit failed – fall through to local HLS.
            $source->update(['hls_worker_status' => null]);
            Log::warning('ProcessHlsAfterFaststartJob: worker submit failed, falling back to local HLS', [
                'source_id' => $source->id,
                'asset_id'  => $source->media_asset_id,
            ]);
        } elseif ($workerEnabled && ! $pullEnabled && $enableHls) {
            if ($mediaSourceService->queueLaravelWorkerPlaybackProcessing($source)) {
                $source->update(['optimize_status' => 'processing']);
                Log::info('ProcessHlsAfterFaststartJob: dispatched to Laravel worker (push mode)', [
                    'source_id' => $source->id,
                    'asset_id'  => $source->media_asset_id,
                ]);
                return;
            }
            // Worker submit failed – fall through to local HLS.
            Log::warning('ProcessHlsAfterFaststartJob: worker push submit failed, falling back to local HLS', [
                'source_id' => $source->id,
                'asset_id'  => $source->media_asset_id,
            ]);
        }

        // Worker is disabled or submission failed – use local FFmpeg for HLS.
        if ($enableHls) {
            Log::info('ProcessHlsAfterFaststartJob: dispatching local HLS generation', [
                'source_id' => $source->id,
                'asset_id'  => $source->media_asset_id,
            ]);
            GenerateHlsVariantsJob::dispatch($source->id)
                ->onQueue((string) config('cdn.optimization_queue', 'optimization'));
        } else {
            // HLS disabled; mark the source as fully ready with mp4 playback.
            $source->update([
                'optimize_status' => 'ready',
                'playback_type'   => 'mp4',
            ]);
            $source = app(NbxEngineService::class)->finalizeStorageIfNeeded($source->fresh() ?? $source);
            app(\App\Services\NbxWebhookDispatcher::class)->dispatch($source, 'job.completed', [
                'hls_enabled' => false,
                'reason' => 'HLS generation is disabled.',
            ]);
        }
    }
}

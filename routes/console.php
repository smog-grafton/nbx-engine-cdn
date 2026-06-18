<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use App\Models\MediaApiToken;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Illuminate\Support\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cdn:token {name} {--abilities=*} {--expires-days=}', function (string $name) {
    $abilitiesOption = $this->option('abilities');
    $abilities = is_array($abilitiesOption)
        ? array_values(array_filter(array_map('trim', $abilitiesOption)))
        : array_values(array_filter(array_map('trim', explode(',', (string) $abilitiesOption))));
    if ($abilities === [] || $abilities === ['*']) {
        $abilities = ['*'];
    }

    $expiresAt = null;
    if ($this->option('expires-days') !== null) {
        $days = (int) $this->option('expires-days');
        $expiresAt = Carbon::now()->addDays(max(1, $days));
    }

    [$tokenModel, $plainToken] = MediaApiToken::issue($name, $abilities, $expiresAt);

    $this->info('Token created successfully.');
    $this->line('Token ID: ' . $tokenModel->id);
    $this->line('Use this bearer token (shown once):');
    $this->line($plainToken);
})->purpose('Issue a server-to-server CDN API token');

Artisan::command('cdn:reconcile {--minutes=30}', function (MediaSourceService $mediaSourceService) {
    $minutes = max(1, (int) $this->option('minutes'));

    /** @var \Illuminate\Support\Collection<int, MediaSource> $staleSources */
    $staleSources = MediaSource::with('asset')
        ->whereIn('status', ['downloading', 'processing', 'proxying', 'uploading'])
        ->where('updated_at', '<', now()->subMinutes($minutes))
        ->get();

    $requeued = 0;
    $markedFailed = 0;
    $restoredReady = 0;

    foreach ($staleSources as $source) {
        /** @var MediaSource $source */
        if ($source->storage_path && $source->storage_disk) {
            $exists = \Illuminate\Support\Facades\Storage::disk($source->storage_disk)->exists($source->storage_path);
            if ($exists) {
                $source->update([
                    'status' => 'ready',
                    'failure_reason' => null,
                ]);
                $mediaSourceService->refreshAssetStatus($source->asset);
                $restoredReady++;
                continue;
            }
        }

        if ($source->source_type === 'remote_fetch' && $source->source_url) {
            $mediaSourceService->queueRemoteImport($source);
            $requeued++;
        } else {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Reconciler marked source as failed after stale processing state.',
            ]);
            $mediaSourceService->refreshAssetStatus($source->asset);
            $markedFailed++;
        }
    }

    $this->info("Requeued: {$requeued}, restored ready: {$restoredReady}, marked failed: {$markedFailed}");
})->purpose('Reconcile stale CDN media source imports');

Artisan::command('media:retry-failed-optimizations {--limit=5} {--stale-minutes=30} {--max-retries=10}', function (MediaSourceService $mediaSourceService) {
    $limit = max(1, (int) $this->option('limit'));
    $maxRetries = max(1, (int) $this->option('max-retries'));
    $disk = (string) config('cdn.disk', 'public');

    // Only re-queue actually failed sources. Do NOT re-queue pending/processing (they are
    // already in the queue or running); that caused hundreds of duplicate jobs.
    $sources = MediaSource::with('asset')
        ->where('status', 'ready')
        ->where('optimize_status', 'failed')
        ->where(function ($q) use ($maxRetries): void {
            // Cap infinite retries: skip sources that have already been retried too many times.
            $q->whereNull('optimize_retry_count')
              ->orWhere('optimize_retry_count', '<', $maxRetries);
        })
        ->limit($limit)
        ->get();

    $requeued = 0;
    $skippedMissing = 0;
    $skippedWorkerOnly = 0;

    foreach ($sources as $source) {
        /** @var MediaSource $source */

        // Determine whether the faststart/compression step already succeeded.
        // If optimized_path is set and exists, compression already ran – only the HLS/worker step failed.
        // In that case we must NOT re-run compression (that would delete the working _play.mp4).
        $faststartAlreadyDone = $source->is_faststart &&
            $source->optimized_path &&
            Storage::disk($source->storage_disk ?: $disk)->exists((string) $source->optimized_path);

        if ($faststartAlreadyDone) {
            // Only re-dispatch the HLS/worker step – skip compression entirely.
            $mediaSourceService->retryWorkerHlsOnly($source);
            $skippedWorkerOnly++;
            continue;
        }

        // Faststart hasn't succeeded yet – full pipeline retry is safe.
        // Verify the input file actually exists before re-queuing.
        $inputPath = $source->storage_path;
        if (! $inputPath || ! Storage::disk($source->storage_disk ?: $disk)->exists($inputPath)) {
            // Also check original_storage_path as a fallback.
            $origPath = $source->original_storage_path;
            if ($origPath && Storage::disk($source->storage_disk ?: $disk)->exists($origPath)) {
                // Restore storage_path to the original if it was accidentally cleared.
                $source->update(['storage_path' => $origPath]);
            } else {
                $skippedMissing++;
                continue;
            }
        }

        $mediaSourceService->queuePlaybackProcessing($source->fresh());
        $requeued++;
    }

    $this->info("Re-queued {$requeued} source(s) for full optimization, {$skippedWorkerOnly} for HLS-only retry, skipped {$skippedMissing} with missing files.");
})->purpose('Re-queue failed optimization sources (smart: HLS-only retry when faststart already done, full pipeline otherwise)');

Artisan::command('media:queue-pending-for-worker {--limit=0 : Max sources to queue (0 = no limit)}', function (MediaSourceService $mediaSourceService) {
    $limit = (int) $this->option('limit');
    $query = MediaSource::with('asset')
        ->where('status', 'ready')
        ->whereIn('optimize_status', ['pending', 'failed']);
    if ($limit > 0) {
        $query->limit($limit);
    }
    $sources = $query->get();

    $disk = (string) config('cdn.disk', 'public');
    $queued = 0;
    foreach ($sources as $source) {
        /** @var MediaSource $source */
        if (! $source->storage_path || ! \Illuminate\Support\Facades\Storage::disk($source->storage_disk ?: $disk)->exists($source->storage_path)) {
            continue;
        }
        $mediaSourceService->queuePlaybackProcessing($source);
        $queued++;
    }

    $workerEnabled = (bool) config('cdn.laravel_worker_enabled', false);
    $this->info("Queued {$queued} media source(s) for playback processing." . ($workerEnabled ? ' (Laravel worker is enabled – jobs sent to worker.)' : ' (Laravel worker disabled – jobs on local optimization queue.)'));
})->purpose('Queue all pending/failed optimization sources; when CDN_LARAVEL_WORKER_ENABLED=true they are sent to the worker');

Artisan::command('media:refresh-asset-statuses {--importing-only : Only refresh assets currently marked as importing}', function (MediaSourceService $mediaSourceService) {
    $importingOnly = $this->option('importing-only');

    $query = MediaAsset::with('sources');
    if ($importingOnly) {
        $query->where('status', 'importing');
    }
    /** @var \Illuminate\Support\Collection<int, MediaAsset> $assets */
    $assets = $query->get();

    $updated = 0;
    foreach ($assets as $asset) {
        /** @var MediaAsset $asset */
        $mediaSourceService->refreshAssetStatus($asset);
        $updated++;
    }

    $this->info("Refreshed status for {$updated} media asset(s).");
})->purpose('Recompute and fix media asset status from source states (fix stuck importing)');

Artisan::command('media:process-optimization-queue {--max-jobs=10 : Max optimization jobs to run}', function () {
    $maxJobs = max(1, (int) $this->option('max-jobs'));
    $queue = (string) config('cdn.optimization_queue', 'optimization');

    $this->info("Processing up to {$maxJobs} optimization job(s)...");
    Artisan::call('queue:work', [
        '--queue' => $queue,
        '--max-jobs' => $maxJobs,
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--timeout' => 7200,
    ]);

    $this->info('Done.');
})->purpose('Run optimization queue worker to process pending/failed optimization jobs');

Artisan::command('media:clear-optimization-queue', function () {
    $connection = config('queue.default');
    $driver = config("queue.connections.{$connection}.driver");
    $queue = (string) config('cdn.optimization_queue', 'optimization');

    if ($driver !== 'database') {
        $this->warn("Queue driver is '{$driver}'. Clear optimization jobs manually (e.g. Redis: flush the optimization list).");
        return;
    }

    $table = config("queue.connections.{$connection}.table", 'jobs');
    $dbConnection = config("queue.connections.{$connection}.connection");
    $deleted = DB::connection($dbConnection)->table($table)->where('queue', $queue)->delete();

    $this->info("Cleared {$deleted} job(s) from the optimization queue. Pending sources will need to be re-queued (retry or Run re-optimise).");
})->purpose('Clear all pending optimization jobs to stop server load; re-queue later via retry or Filament');

Artisan::command('media:refetch-missing-mp4s {--limit=0 : Max sources to process (0 = no limit)} {--dry-run : Report only, do not refetch or mark failed} {--source-id-min= : Minimum media_source id (inclusive)} {--source-id-max= : Maximum media_source id (inclusive)}', function (MediaSourceService $mediaSourceService) {
    $limit = max(0, (int) $this->option('limit'));
    $dryRun = $this->option('dry-run');
    $sourceIdMin = $this->option('source-id-min') ? (int) $this->option('source-id-min') : null;
    $sourceIdMax = $this->option('source-id-max') ? (int) $this->option('source-id-max') : null;

    $disk = (string) config('cdn.disk', 'public');

    $query = MediaSource::with('asset')
        ->whereIn('status', ['ready', 'failed'])
        ->where(function ($q) use ($disk): void {
            $q->whereNull('storage_path')
              ->orWhereRaw('1=1'); // We filter by file existence in the loop
        });

    if ($sourceIdMin !== null) {
        $query->where('id', '>=', $sourceIdMin);
    }
    if ($sourceIdMax !== null) {
        $query->where('id', '<=', $sourceIdMax);
    }
    if ($limit > 0) {
        $query->limit($limit);
    }

    $sources = $query->get();

    $refetched = 0;
    $markedUnreachable = 0;
    $markedNoUrl = 0;
    $skippedIntact = 0;

    foreach ($sources as $source) {
        $storagePath = $source->storage_path;
        $originalPath = $source->original_storage_path;
        $optimPath = $source->optimized_path;

        $storageExists = $storagePath && Storage::disk($source->storage_disk ?: $disk)->exists($storagePath);
        $originalExists = $originalPath && Storage::disk($source->storage_disk ?: $disk)->exists($originalPath);
        $optimExists = $optimPath && Storage::disk($source->storage_disk ?: $disk)->exists($optimPath);

        if ($storageExists || $originalExists || $optimExists) {
            $skippedIntact++;
            continue;
        }

        if ($source->source_type === 'remote_fetch' && ! empty($source->source_url)) {
            $url = trim((string) $source->source_url);
            $reachable = false;
            $failureMsg = null;

            try {
                $response = \Illuminate\Support\Facades\Http::connectTimeout(15)
                    ->timeout(20)
                    ->withHeaders(['User-Agent' => 'NaraboxCDN/1.0'])
                    ->head($url);
                if ($response->successful()) {
                    $reachable = true;
                } else {
                    $failureMsg = sprintf('Original URL unreachable: %s (HTTP %d)', $url, $response->status());
                }
            } catch (\Throwable $e) {
                $failureMsg = sprintf('Original URL unreachable: %s (connection failed: %s)', $url, $e->getMessage());
            }

            if ($reachable && ! $dryRun) {
                $mediaSourceService->queueRemoteImport($source);
                $refetched++;
            } elseif (! $reachable && ! $dryRun) {
                $source->update([
                    'status' => 'failed',
                    'failure_reason' => $failureMsg,
                    'optimize_status' => 'failed',
                    'optimize_error' => $failureMsg,
                ]);
                $mediaSourceService->refreshAssetStatus($source->asset);
                $markedUnreachable++;
            } elseif ($reachable && $dryRun) {
                $this->line("[DRY-RUN] Would refetch source {$source->id} (asset {$source->media_asset_id}) from {$url}");
                $refetched++;
            } else {
                $this->line("[DRY-RUN] Would mark source {$source->id} as failed: {$failureMsg}");
                $markedUnreachable++;
            }
        } else {
            $msg = sprintf('Cannot refetch: no original URL available (source_type=%s)', $source->source_type ?? 'unknown');
            if (! $dryRun) {
                $source->update([
                    'status' => 'failed',
                    'failure_reason' => $msg,
                    'optimize_status' => 'failed',
                    'optimize_error' => $msg,
                ]);
                $mediaSourceService->refreshAssetStatus($source->asset);
                $markedNoUrl++;
            } else {
                $this->line("[DRY-RUN] Would mark source {$source->id} as failed: {$msg}");
                $markedNoUrl++;
            }
        }
    }

    $this->info(sprintf(
        'Refetched: %d | URL unreachable: %d | No URL: %d | Skipped (intact): %d%s',
        $refetched,
        $markedUnreachable,
        $markedNoUrl,
        $skippedIntact,
        $dryRun ? ' [DRY-RUN]' : ''
    ));
})->purpose('Re-fetch assets with missing MP4 files from original source_url; mark failed with clear message if URL unreachable');

Artisan::command('media:audit-deleted-originals {--csv : Output CSV}', function () {
    $disk = (string) config('cdn.disk', 'public');

    $sources = MediaSource::whereNotNull('storage_path')
        ->where('status', 'ready')
        ->get(['id', 'media_asset_id', 'storage_path', 'original_storage_path', 'optimized_path', 'is_faststart', 'optimize_status', 'hls_worker_status']);

    $missing = [];
    $intact  = [];

    foreach ($sources as $source) {
        /** @var MediaSource $source */
        $storagePath  = $source->storage_path;
        $originalPath = $source->original_storage_path;
        $optimPath    = $source->optimized_path;

        $storageExists  = $storagePath && Storage::disk($source->storage_disk ?: $disk)->exists($storagePath);
        $originalExists = $originalPath && Storage::disk($source->storage_disk ?: $disk)->exists($originalPath);
        $optimExists    = $optimPath && Storage::disk($source->storage_disk ?: $disk)->exists($optimPath);

        $row = [
            'source_id'      => $source->id,
            'asset_id'       => $source->media_asset_id,
            'storage_path'   => $storagePath,
            'storage_exists' => $storageExists ? 'yes' : 'NO',
            'original_path'  => $originalPath,
            'original_exists' => $originalExists ? 'yes' : ($originalPath ? 'NO' : 'not-set'),
            'optimized_path' => $optimPath,
            'optimized_exists' => $optimExists ? 'yes' : ($optimPath ? 'NO' : 'not-set'),
            'is_faststart'   => $source->is_faststart ? 'yes' : 'no',
            'optimize_status' => $source->optimize_status,
            'worker_status'  => $source->hls_worker_status,
        ];

        if (! $storageExists) {
            $missing[] = $row;
        } else {
            $intact[] = $row;
        }
    }

    $total   = count($sources);
    $nMiss   = count($missing);
    $nIntact = count($intact);

    $this->info("Total sources: {$total} | Intact: {$nIntact} | Missing storage_path file: {$nMiss}");

    if ($missing !== []) {
        $this->warn("\n--- Sources with MISSING files ---");
        if ($this->option('csv')) {
            $this->line(implode(',', array_keys($missing[0])));
            foreach ($missing as $row) {
                $this->line(implode(',', $row));
            }
        } else {
            foreach ($missing as $row) {
                $this->line(json_encode($row));
            }
        }
    }
})->purpose('Audit all ready media sources and report which have missing files on disk (forensic tool)');

Artisan::command('media:reset-worker-failed-to-pending {--limit=50}', function (MediaSourceService $mediaSourceService) {
    $limit = max(1, (int) $this->option('limit'));
    $disk  = (string) config('cdn.disk', 'public');

    // Find sources where the worker HLS step failed but faststart already succeeded.
    // Reset them so the retry scheduler picks them up for HLS-only retry (not full re-compression).
    $sources = MediaSource::where('status', 'ready')
        ->where('optimize_status', 'failed')
        ->where('is_faststart', true)
        ->whereNotNull('optimized_path')
        ->where(function ($q) use ($disk): void {
            // Only pick those whose optimized_path still exists – we can retry HLS from it.
            $q->whereRaw("1=1"); // All; file existence checked in loop below.
        })
        ->limit($limit)
        ->get();

    $reset   = 0;
    $skipped = 0;

    foreach ($sources as $source) {
        /** @var MediaSource $source */
        $inputPath = $source->optimized_path ?: $source->storage_path;
        if (! $inputPath || ! Storage::disk($source->storage_disk ?: $disk)->exists($inputPath)) {
            $skipped++;
            continue;
        }

        // Increment retry count but do NOT restart from compression.
        $source->update([
            'optimize_status'  => 'failed', // keep failed so scheduler picks it up
            'optimize_error'   => 'Reset for HLS-only retry after worker failure.',
            'hls_worker_status' => null,
            'hls_worker_last_error' => null,
            'optimize_retry_count' => 0, // reset counter so scheduler will try again
        ]);
        $reset++;
    }

    $this->info("Reset {$reset} sources for HLS-only retry, skipped {$skipped} with missing files.");
})->purpose('Reset worker-failed sources for HLS-only retry without re-running compression');

Schedule::call(function (): void {
    Artisan::call('cdn:reconcile', ['--minutes' => 30]);
})->name('cdn:reconcile')->withoutOverlapping()->everyTenMinutes();

Artisan::command('telegram-imports:sync {--limit=20}', function () {
    $limit = max(1, (int) $this->option('limit'));
    $service = app(\App\Services\TelegramImportDispatchService::class);

    $sources = MediaSource::with('asset')
        ->where('source_metadata->source', 'telegram')
        ->whereIn('source_metadata->telebot_status', [
            'waiting_for_capacity',
            'queued',
            'downloading',
            'uploading',
        ])
        ->latest('id')
        ->limit($limit)
        ->get();

    $dispatched = 0;
    $refreshed = 0;

    foreach ($sources as $source) {
        $metadata = (array) ($source->source_metadata ?? []);
        if (empty($metadata['telebot_job_id'])) {
            if ($service->dispatch($source)) {
                $dispatched++;
            }
            continue;
        }

        try {
            $service->refreshJobStatus($source);
            $refreshed++;
        } catch (\Throwable $exception) {
            $source->update([
                'source_metadata' => array_merge($metadata, [
                    'telebot_message' => 'Could not refresh Telebot status: ' . $exception->getMessage(),
                ]),
            ]);
        }
    }

    $this->info("Telegram imports synced. Dispatched {$dispatched}, refreshed {$refreshed}.");
})->purpose('Dispatch waiting Telegram imports to Telebot and refresh active Telebot jobs');

Schedule::command('telegram-imports:sync')
    ->name('telegram-imports-sync')
    ->withoutOverlapping()
    ->everyMinute();

Schedule::call(function (): void {
    Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 1,
        '--tries' => 1,
        '--timeout' => 7200,
        '--max-time' => 55,
    ]);
})->name('cdn:queue-work:default')->withoutOverlapping()->everyMinute();

Schedule::call(function (): void {
    Artisan::call('queue:work', [
        '--queue' => (string) config('cdn.optimization_queue', 'optimization'),
        '--max-jobs' => 1,
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--timeout' => 7200,
        '--max-time' => 58,
    ]);
})->name('cdn:queue-work:optimization')->withoutOverlapping()->everyTwoMinutes();

Schedule::call(function (): void {
    Artisan::call('media:retry-failed-optimizations', ['--limit' => 5, '--stale-minutes' => 30]);
})->name('cdn:retry-failed-optimizations')->withoutOverlapping()->everyFiveMinutes();

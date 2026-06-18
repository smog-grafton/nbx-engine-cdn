<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\MediaStreamController;

Route::get('/health', fn () => response()->json([
    'ok' => true,
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::get('/', function () {
    return view('welcome');
});

Route::get('/media/{asset}/{source}/{filename?}', [MediaStreamController::class, 'stream'])
    ->whereUuid('asset')
    ->whereNumber('source')
    ->name('media.stream.source');
Route::options('/media/{asset}/{source}/{filename?}', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
    'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
    'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
]));

Route::get('/media-hls/{asset}/{source}/{file}', [MediaStreamController::class, 'hlsRoot'])
    ->whereUuid('asset')
    ->whereNumber('source')
    ->name('media.hls.root');
Route::options('/media-hls/{asset}/{source}/{file}', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
    'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
    'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
]));

Route::get('/media-hls/{asset}/{source}/{path}', [MediaStreamController::class, 'hlsPath'])
    ->whereUuid('asset')
    ->whereNumber('source')
    ->where('path', '.+')
    ->name('media.hls.source');
Route::options('/media-hls/{asset}/{source}/{path}', fn () => response('', 204, [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
    'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
    'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
]))->whereUuid('asset')
    ->whereNumber('source')
    ->where('path', '.+');

Route::get('/_run/clear-caches', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $commands = [
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
        'clear-compiled',
        'optimize:clear',
    ];

    $results = [];
    foreach ($commands as $cmd) {
        Artisan::call($cmd);
        $results[$cmd] = trim(Artisan::output());
    }

    return response()->json([
        'ok' => true,
        'message' => 'All caches cleared.',
        'output' => $results,
    ]);
})->name('run.clear-caches');

Route::get('/_deploy/run', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $results = [];

    // Clear caches
    foreach ([
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
        'clear-compiled',
        'optimize:clear',
    ] as $cmd) {
        Artisan::call($cmd);
        $results[$cmd] = trim(Artisan::output());
    }

    // Ensure writable dirs
    $paths = [
        base_path('bootstrap/cache'),
        storage_path(),
        storage_path('app'),
        storage_path('app/public'),
        storage_path('framework'),
        storage_path('framework/cache'),
        storage_path('framework/sessions'),
        storage_path('framework/views'),
        storage_path('logs'),
        public_path(),
    ];

    foreach ($paths as $path) {
        if (is_dir($path)) {
            @chmod($path, 0775);
            try {
                $items = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                foreach ($items as $item) {
                    if ($item->isLink()) {
                        continue;
                    }
                    @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
                }
            } catch (\Throwable $e) {
                $results['chmod_skipped'][] = [
                    'path' => $path,
                    'reason' => $e->getMessage(),
                ];
            }
        }
    }

    // storage:link equivalent
    $target = storage_path('app/public');
    $link = public_path('storage');

    $linkExists = false;
    try {
        // Avoid file_exists() first because it resolves symlink targets and can trigger open_basedir.
        $linkExists = @is_link($link) || @is_dir($link);
    } catch (\Throwable $e) {
        $results['storage_link_check_error'] = $e->getMessage();
    }

    if (! $linkExists) {
        if (function_exists('symlink')) {
            try {
                @symlink($target, $link);
            } catch (\Throwable $e) {
                $results['symlink_error'] = $e->getMessage();
            }
        }

        try {
            $linkExists = @is_link($link) || @is_dir($link);
        } catch (\Throwable $e) {
            $results['storage_link_recheck_error'] = $e->getMessage();
        }

        if (! $linkExists) {
            // fallback via artisan
            try {
                Artisan::call('storage:link');
                $results['storage:link'] = trim(Artisan::output());
            } catch (\Throwable $e) {
                $results['storage_link_artisan_error'] = $e->getMessage();
            }
        }
    }

    try {
        $linkExists = @is_link($link) || @is_dir($link);
    } catch (\Throwable) {
        // keep previous state
    }

    $results['storage_link_exists'] = $linkExists;

    return response()->json([
        'ok' => true,
        'results' => $results,
    ]);
});

Route::get('/_run/refresh-asset-statuses', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $importingOnly = request()->boolean('importing-only', true);

    try {
        Artisan::call('media:refresh-asset-statuses', [
            '--importing-only' => $importingOnly,
        ]);
        $output = trim(Artisan::output());
        $exitCode = 0;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
        'importing_only' => $importingOnly,
    ]);
})->name('run.refresh-asset-statuses');

Route::get('/_run/retry-failed-optimizations', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $limit = max(1, min(200, (int) request('limit', 50)));
    $staleMinutes = max(5, min(1440, (int) request('stale_minutes', 30)));

    try {
        Artisan::call('media:retry-failed-optimizations', [
            '--limit' => $limit,
            '--stale-minutes' => $staleMinutes,
        ]);
        $output = trim(Artisan::output());
        $exitCode = 0;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
        'limit' => $limit,
        'stale_minutes' => $staleMinutes,
    ]);
})->name('run.retry-failed-optimizations');

Route::get('/_run/process-optimization-queue', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $maxJobs = max(1, min(10, (int) request('max_jobs', 5)));
    $queue = (string) config('cdn.optimization_queue', 'optimization');
    @set_time_limit(0);

    try {
        Artisan::call('queue:work', [
            '--queue' => $queue,
            '--max-jobs' => $maxJobs,
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 7200,
        ]);
        $output = trim(Artisan::output());
        $exitCode = 0;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
        'max_jobs' => $maxJobs,
    ]);
})->name('run.process-optimization-queue');

Route::get('/_run/optimization', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $retryLimit = max(1, min(200, (int) request('retry_limit', 50)));
    $staleMinutes = max(5, min(1440, (int) request('stale_minutes', 30)));
    $maxJobs = max(1, min(10, (int) request('max_jobs', 5)));
    $queue = (string) config('cdn.optimization_queue', 'optimization');
    @set_time_limit(0);

    $steps = [];

    try {
        Artisan::call('media:retry-failed-optimizations', [
            '--limit' => $retryLimit,
            '--stale-minutes' => $staleMinutes,
        ]);
        $steps['retry'] = trim(Artisan::output());
    } catch (\Throwable $e) {
        $steps['retry'] = 'Error: ' . $e->getMessage();
    }

    try {
        Artisan::call('queue:work', [
            '--queue' => $queue,
            '--max-jobs' => $maxJobs,
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 7200,
        ]);
        $steps['process'] = trim(Artisan::output());
    } catch (\Throwable $e) {
        $steps['process'] = 'Error: ' . $e->getMessage();
    }

    return response()->json([
        'ok' => true,
        'steps' => $steps,
        'retry_limit' => $retryLimit,
        'max_jobs' => $maxJobs,
    ]);
})->name('run.optimization');

Route::get('/_run/clear-optimization-queue', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    try {
        Artisan::call('media:clear-optimization-queue');
        $output = trim(Artisan::output());
        $ok = true;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $ok = false;
    }

    return response()->json([
        'ok' => $ok,
        'output' => $output,
    ]);
})->name('run.clear-optimization-queue');

/**
 * Clear queue, then queue exactly N sources. Use for batch workflow:
 * Hit this URL -> wait for 5 to finish -> hit again for next 5.
 * ?key=...&limit=5 (default 5)
 */
Route::get('/_run/batch-optimize', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $limit = max(1, min(50, (int) request('limit', 5)));

    $cleared = 0;
    $queued = 0;
    $errors = [];

    try {
        Artisan::call('media:clear-optimization-queue');
        $out = trim(Artisan::output());
        if (preg_match('/Cleared (\d+)/', $out, $m)) {
            $cleared = (int) $m[1];
        }
    } catch (\Throwable $e) {
        $errors[] = 'clear: ' . $e->getMessage();
    }

    try {
        Artisan::call('media:queue-pending-for-worker', ['--limit' => $limit]);
        $out = trim(Artisan::output());
        if (preg_match('/Queued (\d+)/', $out, $m)) {
            $queued = (int) $m[1];
        }
    } catch (\Throwable $e) {
        $errors[] = 'queue: ' . $e->getMessage();
    }

    $enableHls = (bool) config('cdn.enable_hls', true);
    $jobsPerSource = $enableHls ? 2 : 1;
    $approxJobs = $queued * $jobsPerSource;

    return response()->json([
        'ok' => empty($errors),
        'cleared' => $cleared,
        'queued' => $queued,
        'limit' => $limit,
        'approx_jobs' => $approxJobs,
        'message' => "Cleared {$cleared} jobs, queued {$queued} sources (≈{$approxJobs} jobs). " . ($enableHls ? 'Each source = 2 jobs (faststart + HLS).' : 'HLS disabled, 1 job per source (faststart only).') . ' Wait for queue to drain, then hit again.',
        'errors' => $errors,
    ]);
})->name('run.batch-optimize');

Route::get('/_run/queue-pending-for-worker', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $limit = max(0, (int) request('limit', 0));

    try {
        $args = $limit > 0 ? ['--limit' => $limit] : [];
        Artisan::call('media:queue-pending-for-worker', $args);
        $output = trim(Artisan::output());
        $exitCode = 0;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
        'limit' => $limit,
    ]);
})->name('run.queue-pending-for-worker');

Route::get('/_run/refetch-missing-mp4s', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $limit = max(0, (int) request('limit', 0));
    $dryRun = request()->boolean('dry_run', false);
    $sourceIdMin = request('source_id_min') ? (int) request('source_id_min') : null;
    $sourceIdMax = request('source_id_max') ? (int) request('source_id_max') : null;

    try {
        $args = ['--limit' => $limit];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }
        if ($sourceIdMin !== null) {
            $args['--source-id-min'] = (string) $sourceIdMin;
        }
        if ($sourceIdMax !== null) {
            $args['--source-id-max'] = (string) $sourceIdMax;
        }
        Artisan::call('media:refetch-missing-mp4s', $args);
        $output = trim(Artisan::output());
        $exitCode = 0;
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
        'limit' => $limit,
        'dry_run' => $dryRun,
    ]);
})->name('run.refetch-missing-mp4s');

Route::get('/_deploy/migrate', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $exitCode = 0;
    $output = '';
    try {
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());
    } catch (\Throwable $e) {
        $output = $e->getMessage();
        $exitCode = 1;
    }

    return response()->json([
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit_code' => $exitCode,
    ]);
});

Route::get('/debug/exec-test', function () {
    abort_unless(request('key') === env('DEPLOY_KEY'), 403);

    $commands = [
        'uname -a 2>&1',
        'id 2>&1',
        'php -v 2>&1',
        base_path('ffmpeg/ffmpeg') . ' -version 2>&1',
    ];

    $results = [];

    foreach ($commands as $command) {
        $out = [];
        $code = 0;

        @exec($command, $out, $code);

        $results[] = [
            'command' => $command,
            'exit_code' => $code,
            'output' => implode("\n", $out),
        ];
    }

    return response()->json([
        'ok' => true,
        'cwd' => base_path(),
        'results' => $results,
        'note' => 'Remove /debug/exec-test route after testing.',
    ]);
});

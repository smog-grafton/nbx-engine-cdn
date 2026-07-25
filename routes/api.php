<?php

use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NbxEngineController;
use App\Http\Controllers\Api\StorageObjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('cdn.token')
    ->group(function (): void {
        Route::post('/media/import', [MediaController::class, 'import'])
            ->middleware('throttle:20,1');
        Route::post('/media/upload', [MediaController::class, 'upload'])
            ->middleware('throttle:10,1');
        Route::post('/media/telegram-intake', [MediaController::class, 'telegramIntake'])
            ->middleware('throttle:10,1');
        Route::post('/media/telegram-stream-intake', [MediaController::class, 'telegramStreamIntake'])
            ->middleware('throttle:10,1');
        Route::post('/media/telegram-handoff', [MediaController::class, 'telegramHandoff'])
            ->middleware('throttle:20,1');
        Route::get('/media/{assetId}', [MediaController::class, 'showAsset'])
            ->whereUuid('assetId');
        Route::get('/media/{assetId}/playback', [MediaController::class, 'playback'])
            ->whereUuid('assetId');
        Route::get('/media/sources/lookup', [MediaController::class, 'lookupSource']);
        Route::get('/media/sources/{sourceId}', [MediaController::class, 'showSource'])
            ->whereNumber('sourceId');
        Route::post('/media/sources/{sourceId}/optimize', [MediaController::class, 'queueSourceOptimization'])
            ->whereNumber('sourceId')
            ->middleware('throttle:60,1');
        Route::delete('/media/sources/{sourceId}', [MediaController::class, 'destroySource'])
            ->whereNumber('sourceId');
        Route::post('/media/worker/callback', [MediaController::class, 'workerCallback']);
        Route::post('/media/worker/upload', [MediaController::class, 'workerUpload']);

        Route::post('/nbx/jobs', [NbxEngineController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::post('/nbx/jobs/upload', [NbxEngineController::class, 'upload'])
            ->middleware('throttle:10,1');
        Route::post('/nbx/uploads/init', [NbxEngineController::class, 'initUpload'])
            ->middleware('throttle:20,1');
        Route::get('/nbx/jobs/{jobId}', [NbxEngineController::class, 'show'])
            ->where('jobId', '[A-Za-z0-9:_\\-]+');
        Route::post('/nbx/jobs/{jobId}/actions', [NbxEngineController::class, 'action'])
            ->where('jobId', '[A-Za-z0-9:_\\-]+')
            ->middleware('throttle:20,1');
        Route::delete('/nbx/jobs/{jobId}/original', [NbxEngineController::class, 'destroyOriginal'])
            ->where('jobId', '[A-Za-z0-9:_\\-]+')
            ->middleware('throttle:20,1');
        Route::get('/nbx/discover', [NbxEngineController::class, 'discover'])
            ->middleware('throttle:60,1');
        Route::get('/nbx/diagnostics/binaries', [NbxEngineController::class, 'diagnostics'])
            ->middleware('throttle:30,1');
    });

Route::prefix('v1')
    ->group(function (): void {
        Route::post('/nbx/uploads/{session}/complete', [NbxEngineController::class, 'completeUpload'])
            ->whereUuid('session')
            ->middleware('throttle:10,1');
        Route::post('/nbx/uploads/{session}/cancel', [NbxEngineController::class, 'cancelUpload'])
            ->whereUuid('session')
            ->middleware('throttle:30,1');
    });

Route::prefix('v1/storage')
    ->group(function (): void {
        Route::get('/objects', [StorageObjectController::class, 'index'])
            ->middleware(['cdn.token:storage.view', 'throttle:60,1']);
        Route::get('/audits', [StorageObjectController::class, 'audits'])
            ->middleware(['cdn.token:storage.view', 'throttle:60,1']);
        Route::post('/references', [StorageObjectController::class, 'register'])
            ->middleware(['cdn.token:storage.manage.direct', 'throttle:60,1']);
        Route::delete('/sources/{source}/artifacts/{role}', [StorageObjectController::class, 'destroyArtifact'])
            ->whereNumber('source')
            ->whereIn('role', ['original', 'faststart', 'hls', 'asset'])
            ->middleware(['cdn.token', 'throttle:20,1']);
        Route::delete('/references/{reference}', [StorageObjectController::class, 'destroyReference'])
            ->whereNumber('reference')
            ->middleware(['cdn.token:storage.manage.direct', 'throttle:20,1']);
        Route::delete('/objects/orphan', [StorageObjectController::class, 'destroyOrphan'])
            ->middleware(['cdn.token:storage.delete.orphan', 'throttle:20,1']);
    });

Route::post('/ingest/asset-source-upload', [IngestController::class, 'assetSourceUpload'])
    ->middleware('throttle:20,1');

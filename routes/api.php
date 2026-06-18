<?php

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\NbxEngineController;
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

Route::post('/ingest/asset-source-upload', [IngestController::class, 'assetSourceUpload'])
    ->middleware('throttle:20,1');

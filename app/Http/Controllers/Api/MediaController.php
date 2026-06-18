<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FetchWorkerHlsArtifactJob;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Support\MediaUrl;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use ZipArchive;

class MediaController extends Controller
{
    public function import(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => ['sometimes', 'string', 'max:2048', static function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    if ($value !== null) {
                        SafeRemoteMediaUrl::assertAllowed(is_string($value) ? $value : null);
                    }
                } catch (\Throwable $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'import_mode' => ['nullable', Rule::in(['now', 'queue'])],
            'import_strategy' => ['nullable', Rule::in(['auto', 'python_worker'])],
            // Creator portal metadata
            'source_metadata' => ['nullable', 'array'],
            'source_metadata.creator_ref' => ['nullable', 'string', 'max:100'],
            'source_metadata.creator_type' => ['nullable', 'string', 'max:50'],
            'source_metadata.portal_movie_id' => ['nullable', 'integer'],
        ]);

        // Allow creating an asset with no source_url (used for pre-creating CDN asset for direct uploads)
        $sourceUrl = MediaUrl::normalize($validated['source_url'] ?? null);

        $asset = MediaAsset::create([
            'type' => $validated['asset_type'] ?? 'generic',
            'title' => $validated['title'] ?? basename((string) parse_url((string) $sourceUrl, PHP_URL_PATH)) ?: 'Imported Media',
            'description' => $validated['description'] ?? null,
            'status' => 'importing',
            'visibility' => $validated['visibility'] ?? 'public',
        ]);

        // If no source_url, just create the asset and return (for direct upload flow)
        if (!$sourceUrl) {
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $asset->id,
                    'asset_id' => $asset->id,
                    'status' => $asset->status,
                    'source_id' => null,
                    'title' => $asset->title,
                ],
            ]);
        }

        $creatorMeta = $validated['source_metadata'] ?? [];
        $extraMeta = array_filter([
            'creator_ref'     => $creatorMeta['creator_ref'] ?? null,
            'creator_type'    => $creatorMeta['creator_type'] ?? null,
            'portal_movie_id' => $creatorMeta['portal_movie_id'] ?? null,
        ]);

        $source = MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => $sourceUrl,
            'status' => 'pending',
            'is_active' => true,
        ]);

        // Store creator metadata if provided and column exists
        if (!empty($extraMeta) && Schema::hasColumn('media_sources', 'source_metadata')) {
            $existing = $source->source_metadata ?? [];
            $source->update(['source_metadata' => array_merge($existing, $extraMeta)]);
        }

        $importMode = (string) ($validated['import_mode'] ?? config('cdn.default_import_mode', 'queue'));
        $importStrategy = (string) ($validated['import_strategy'] ?? 'auto');
        if ($importStrategy === 'python_worker') {
            try {
                $mediaSourceService->queuePythonWorkerImport($source);
            } catch (\Throwable $workerError) {
                Log::warning('CDN python worker enqueue failed, falling back to remote import', [
                    'asset_id' => $asset->id,
                    'source_id' => $source->id,
                    'error' => $workerError->getMessage(),
                ]);
                if ($importMode === 'now') {
                    $mediaSourceService->importRemoteNow($source);
                } else {
                    $mediaSourceService->queueRemoteImport($source);
                }
                $importStrategy = 'auto';
            }
        } elseif ($importMode === 'now') {
            $mediaSourceService->importRemoteNow($source);
        } else {
            $mediaSourceService->queueRemoteImport($source);
        }
        $source->refresh();

        Log::info('CDN media import accepted', [
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'job_id' => $source->external_job_id,
            'import_mode' => $importMode,
            'import_strategy' => $importStrategy,
        ]);

        $payload = [
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'job_id' => $source->external_job_id,
            'status' => $source->status,
            'import_strategy' => $importStrategy,
            'failure_reason' => $source->failure_reason,
            'file_size_bytes' => $source->file_size_bytes,
            'mime_type' => $source->mime_type,
            'public_url_if_ready' => $mediaSourceService->buildPublicUrl($source),
            'playback' => $mediaSourceService->buildPlaybackManifest($source),
        ];

        if ($importMode === 'now' && $source->status === 'failed') {
            return response()->json([
                'success' => false,
                'data' => $payload,
                'error' => $source->failure_reason ?: 'Remote import failed.',
            ], 422);
        }

        $statusCode = $importMode === 'now' ? 200 : 202;

        return $this->success($payload, $statusCode);
    }

    public function upload(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $maxUploadMb = (int) config('cdn.max_upload_mb', 2048);
        $allowedExtensions = (array) config('cdn.allowed_video_extensions', ['mp4']);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . ($maxUploadMb * 1024)],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {
            return $this->error('File extension is not allowed for video upload.', 422);
        }

        $asset = MediaAsset::create([
            'type' => $validated['asset_type'] ?? 'generic',
            'title' => $validated['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'description' => $validated['description'] ?? null,
            'status' => 'importing',
            'visibility' => $validated['visibility'] ?? 'public',
        ]);

        $source = $mediaSourceService->storeUploadedSource($asset, $file);
        $asset->refresh();

        Log::info('CDN media upload complete', [
            'asset_id' => $asset->id,
            'source_id' => $source->id,
        ]);

        return $this->success([
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'status' => $source->status,
            'public_url_if_ready' => $mediaSourceService->buildPublicUrl($source),
            'playback' => $mediaSourceService->buildPlaybackManifest($source),
        ], 201);
    }

    public function telegramIntake(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        Log::info('CDN telegram intake request started', [
            'content_length' => $request->header('Content-Length'),
            'has_file' => $request->hasFile('file'),
        ]);

        $maxUploadMb = (int) config('cdn.max_upload_mb', 2048);
        $allowedExtensions = (array) config('cdn.allowed_video_extensions', ['mp4']);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . ($maxUploadMb * 1024)],
            'title' => ['nullable', 'string', 'max:255'],
            'original_filename' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'episode' => ['nullable', 'string', 'max:50'],
            'vj' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_message_id' => ['nullable', 'string', 'max:64'],
            'telegram_channel' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'string', 'max:4096'],
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {
            return $this->error('File extension is not allowed for video upload.', 422);
        }

        $title = $validated['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $asset = MediaAsset::create([
            'type' => $validated['asset_type'] ?? 'movie',
            'title' => $title,
            'description' => null,
            'status' => 'importing',
            'visibility' => 'public',
        ]);

        $source = $mediaSourceService->storeUploadedSource($asset, $file);
        $asset->refresh();

        $sourceMetadata = array_filter([
            'title_guess' => $title,
            'original_filename' => $validated['original_filename'] ?? $file->getClientOriginalName(),
            'episode_guess' => $validated['episode'] ?? null,
            'vj_guess' => $validated['vj'] ?? null,
            'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
            'telegram_message_id' => $validated['telegram_message_id'] ?? null,
            'telegram_channel' => $validated['telegram_channel'] ?? null,
            'source' => 'telegram',
        ]);

        if (! empty($validated['metadata'])) {
            $decoded = json_decode($validated['metadata'], true);
            if (is_array($decoded)) {
                $sourceMetadata = array_merge($sourceMetadata, $decoded);
            }
        }

        $sourceMetadata['telebot_status'] = $source->status === 'failed' ? 'failed' : 'done';
        $sourceMetadata['telebot_message'] = $source->status === 'failed'
            ? ($source->failure_reason ?: 'Telebot stream failed at CDN intake.')
            : 'Telebot stream completed. CDN optimization has started.';

        if ($sourceMetadata !== [] && Schema::hasColumn('media_sources', 'source_metadata')) {
            $source->update(['source_metadata' => $sourceMetadata]);
            $source->refresh();
        }

        Log::info('CDN telegram intake complete', [
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'telegram_channel' => $validated['telegram_channel'] ?? null,
        ]);

        return $this->success([
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'status' => $source->status,
            'public_url_if_ready' => $mediaSourceService->buildPublicUrl($source),
            'playback' => $mediaSourceService->buildPlaybackManifest($source),
        ], 201);
    }

    public function telegramStreamIntake(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        Log::info('CDN telegram stream intake request started', [
            'content_length' => $request->header('Content-Length'),
            'transfer_encoding' => $request->header('Transfer-Encoding'),
        ]);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'original_filename' => ['required', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'episode' => ['nullable', 'string', 'max:50'],
            'vj' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_message_id' => ['nullable', 'string', 'max:64'],
            'telegram_channel' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'string', 'max:8192'],
            'bytes_total' => ['nullable', 'integer', 'min:1'],
            'asset_id' => ['nullable', 'string', 'uuid'],
            'source_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $allowedExtensions = (array) config('cdn.allowed_video_extensions', ['mp4']);
        $extension = strtolower(pathinfo($validated['original_filename'], PATHINFO_EXTENSION));

        if (! in_array($extension, $allowedExtensions, true)) {
            return $this->error('File extension is not allowed for video upload.', 422);
        }

        $title = $validated['title'] ?? pathinfo($validated['original_filename'], PATHINFO_FILENAME);
        $asset = ! empty($validated['asset_id'])
            ? MediaAsset::findOrFail($validated['asset_id'])
            : MediaAsset::create([
                'type' => $validated['asset_type'] ?? 'movie',
                'title' => $title,
                'description' => null,
                'status' => 'importing',
                'visibility' => 'public',
            ]);

        $source = null;
        if (! empty($validated['source_id'])) {
            $source = MediaSource::where('id', (int) $validated['source_id'])
                ->where('media_asset_id', $asset->id)
                ->firstOrFail();
        }

        $input = $request->getContent(true);
        $source = $mediaSourceService->storeStreamedSource(
            $asset,
            $input,
            $validated['original_filename'],
            isset($validated['bytes_total']) ? (int) $validated['bytes_total'] : null,
            $request->header('Content-Type') ?: 'application/octet-stream',
            null,
            $source,
        );
        if (is_resource($input)) {
            fclose($input);
        }

        $sourceMetadata = array_filter([
            'title_guess' => $title,
            'original_filename' => $validated['original_filename'],
            'episode_guess' => $validated['episode'] ?? null,
            'vj_guess' => $validated['vj'] ?? null,
            'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
            'telegram_message_id' => $validated['telegram_message_id'] ?? null,
            'telegram_channel' => $validated['telegram_channel'] ?? null,
            'source' => 'telegram',
            'handoff_mode' => 'stream',
        ]);

        if (! empty($validated['metadata'])) {
            $decoded = json_decode($validated['metadata'], true);
            if (is_array($decoded)) {
                $sourceMetadata = array_merge($sourceMetadata, $decoded);
            }
        }

        $sourceMetadata['telebot_status'] = $source->status === 'failed' ? 'failed' : 'done';
        $sourceMetadata['telebot_message'] = $source->status === 'failed'
            ? ($source->failure_reason ?: 'Telebot stream failed at CDN intake.')
            : 'Telebot stream completed. CDN optimization has started.';

        if ($sourceMetadata !== [] && Schema::hasColumn('media_sources', 'source_metadata')) {
            $source->update(['source_metadata' => $sourceMetadata]);
            $source->refresh();
        }

        Log::info('CDN telegram stream intake complete', [
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'status' => $source->status,
            'telegram_channel' => $validated['telegram_channel'] ?? null,
        ]);

        return $this->success([
            'asset_id' => $asset->id,
            'source_id' => $source->id,
            'status' => $source->status,
            'public_url_if_ready' => $mediaSourceService->buildPublicUrl($source),
            'playback' => $mediaSourceService->buildPlaybackManifest($source),
        ], $source->status === 'failed' ? 422 : 201);
    }

    public function showAsset(string $assetId, MediaSourceService $mediaSourceService): JsonResponse
    {
        $asset = MediaAsset::with('sources')->findOrFail($assetId);

        $activeReadySource = $asset->sources
            ->where('is_active', true)
            ->first(fn (MediaSource $source) => $source->status === 'ready');

        return $this->success([
            'asset' => [
                'id' => $asset->id,
                'type' => $asset->type,
                'title' => $asset->title,
                'description' => $asset->description,
                'status' => $asset->status,
                'visibility' => $asset->visibility,
                'created_at' => $asset->created_at?->toIso8601String(),
                'updated_at' => $asset->updated_at?->toIso8601String(),
            ],
            'sources' => $asset->sources->map(fn (MediaSource $source) => $this->sourcePayload($source, $mediaSourceService))->values()->all(),
            'best_public_playback_url' => $activeReadySource ? $mediaSourceService->buildPublicUrl($activeReadySource) : null,
            'playback' => $activeReadySource ? $mediaSourceService->buildPlaybackManifest($activeReadySource) : null,
        ]);
    }

    public function playback(string $assetId, MediaSourceService $mediaSourceService): JsonResponse
    {
        $asset = MediaAsset::with('sources')->findOrFail($assetId);
        $activeReadySource = $asset->sources
            ->where('is_active', true)
            ->first(fn (MediaSource $source) => $source->status === 'ready');

        if (! $activeReadySource) {
            return $this->error('No active ready source found for this asset.', 404);
        }

        $playback = $mediaSourceService->buildPlaybackManifest($activeReadySource);
        Log::info('CDN playback manifest generated', [
            'asset_id' => $asset->id,
            'source_id' => $activeReadySource->id,
            'type' => $playback['type'] ?? 'mp4',
            'hls_master_url' => $playback['hls_master_url'] ?? null,
            'mp4_play_url' => $playback['mp4_play_url'] ?? ($playback['mp4_url'] ?? null),
            'download_url' => $playback['download_url'] ?? null,
            'qualities_count' => is_array($playback['qualities'] ?? null) ? count($playback['qualities']) : 0,
        ]);

        return $this->success([
            'asset_id' => $asset->id,
            'source_id' => $activeReadySource->id,
            'playback' => $playback,
        ]);
    }

    public function showSource(int $sourceId, MediaSourceService $mediaSourceService): JsonResponse
    {
        $source = MediaSource::with('asset')->findOrFail($sourceId);

        return $this->success($this->sourcePayload($source, $mediaSourceService));
    }

    public function lookupSource(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => ['required', 'string', 'max:2048', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! MediaUrl::isValid(is_string($value) ? $value : null)) {
                    $fail("The {$attribute} field must be a valid URL.");
                }
            }],
        ]);

        $sourceUrl = MediaUrl::normalize($validated['source_url']) ?? $validated['source_url'];
        $source = MediaSource::with('asset')
            ->where(function ($query) use ($sourceUrl, $validated): void {
                $query->where('source_url', $sourceUrl);

                if ($sourceUrl !== $validated['source_url']) {
                    $query->orWhere('source_url', $validated['source_url']);
                }
            })
            ->latest('id')
            ->first();

        if (! $source) {
            return $this->error('Source not found for provided URL.', 404);
        }

        return $this->success($this->sourcePayload($source, $mediaSourceService));
    }

    public function destroySource(int $sourceId, MediaSourceService $mediaSourceService): JsonResponse
    {
        $source = MediaSource::with('asset')->findOrFail($sourceId);
        $asset = $source->asset;
        $source->delete();

        if ($asset) {
            $mediaSourceService->refreshAssetStatus($asset);
        }

        return $this->success(['deleted' => true]);
    }

    public function queueSourceOptimization(int $sourceId, Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'compress_enabled' => ['nullable', 'boolean'],
        ]);

        $source = MediaSource::with('asset')->findOrFail($sourceId);

        if (array_key_exists('compress_enabled', $validated)) {
            $source->update(['compress_enabled' => (bool) $validated['compress_enabled']]);
            $source->refresh();
        }

        $queued = $mediaSourceService->queuePlaybackProcessing($source);
        $source->refresh();

        if ($source->asset) {
            $mediaSourceService->refreshAssetStatus($source->asset);
        }

        return $this->success([
            'queued' => $queued,
            'source' => $this->sourcePayload($source, $mediaSourceService),
            'message' => $queued
                ? 'Source queued for playback optimization.'
                : 'Source already has optimization pending or running, or is not eligible.',
        ], $queued ? 202 : 200);
    }

    /**
     * Worker callback: worker reports processing result (success or failure).
     * Pull mode: status completed/partial with artifact_download_url -> CDN will fetch ZIP and install.
     * Push mode: status completed with optimized_path/hls_master_path -> legacy behavior.
     */
    public function workerCallback(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'string', 'uuid'],
            'source_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:completed,failed,partial'],
            'failure_reason' => ['nullable', 'string', 'max:2048'],
            'optimized_path' => ['nullable', 'string', 'max:1024'],
            'hls_master_path' => ['nullable', 'string', 'max:1024'],
            'qualities_json' => ['nullable', 'array'],
            'is_faststart' => ['nullable', 'boolean'],
            'playback_type' => ['nullable', 'string', 'in:mp4,hls'],
            'artifact_download_url' => ['nullable', 'url', 'max:2048'],
            'artifact_expires_at' => ['nullable', 'string', 'max:64'],
            'quality_status' => ['nullable', 'string', 'max:32'],
            'external_id' => ['nullable', 'string', 'max:64'],
        ]);

        $source = MediaSource::where('media_asset_id', $validated['asset_id'])
            ->where('id', (int) $validated['source_id'])
            ->first();

        if (! $source) {
            return $this->error('Source not found.', 404);
        }

        if ($validated['status'] === 'failed') {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => $validated['failure_reason'] ?? 'Worker reported failure.',
                'is_faststart' => false,
                'optimized_at' => null,
                'hls_worker_status' => 'failed',
                'hls_worker_last_error' => $validated['failure_reason'] ?? null,
            ]);
            $mediaSourceService->refreshAssetStatus($source->asset);
            return $this->success(['updated' => true, 'optimize_status' => 'failed']);
        }

        $artifactUrl = $validated['artifact_download_url'] ?? null;
        if ($artifactUrl !== null && $artifactUrl !== '') {
            $expiresAt = null;
            if (! empty($validated['artifact_expires_at'])) {
                try {
                    $expiresAt = new \DateTimeImmutable($validated['artifact_expires_at']);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            $source->update([
                'hls_worker_status' => 'artifact_ready',
                'hls_worker_artifact_url' => $artifactUrl,
                'hls_worker_artifact_expires_at' => $expiresAt,
                'hls_worker_external_id' => $validated['external_id'] ?? null,
                'hls_worker_quality_status' => $validated['quality_status'] ?? null,
                'qualities_json' => $validated['qualities_json'] ?? $source->qualities_json,
            ]);
            FetchWorkerHlsArtifactJob::dispatch($source->id);
            $mediaSourceService->refreshAssetStatus($source->asset);
            return $this->success(['updated' => true, 'hls_worker_status' => 'artifact_ready', 'fetch_dispatched' => true]);
        }

        $source->update([
            'optimize_status' => 'ready',
            'optimized_path' => $validated['optimized_path'] ?? $source->optimized_path,
            'optimize_error' => null,
            'is_faststart' => (bool) ($validated['is_faststart'] ?? true),
            'playback_type' => $validated['playback_type'] ?? 'mp4',
            'hls_master_path' => $validated['hls_master_path'] ?? $source->hls_master_path,
            'qualities_json' => $validated['qualities_json'] ?? $source->qualities_json,
            'optimized_at' => now(),
        ]);
        $mediaSourceService->refreshAssetStatus($source->asset);

        return $this->success(['updated' => true, 'optimize_status' => 'ready']);
    }

    /**
     * Worker upload: worker uploads optimized MP4 and HLS zip for an existing source.
     * HLS is required for streaming. Same auth as other API routes (Bearer CDN_API_TOKEN).
     */
    public function workerUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'string', 'uuid'],
            'source_id' => ['required', 'integer', 'min:1'],
            'optimized' => ['required', 'file', 'mimes:mp4,m4v', 'max:2147483648'],
            'hls_zip' => ['required', 'file', 'mimes:zip', 'max:5368709120'],
        ]);

        $source = MediaSource::where('media_asset_id', $validated['asset_id'])
            ->where('id', (int) $validated['source_id'])
            ->first();

        if (! $source || $source->status !== 'ready') {
            Log::warning('Worker upload: source not found or not ready', [
                'asset_id' => $validated['asset_id'],
                'source_id' => $validated['source_id'],
                'source_status' => $source?->status,
            ]);
            return $this->error('Source not found or not ready.', 404);
        }

        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        $assetId = $source->media_asset_id;
        $sourceId = $source->id;
        $baseDir = 'media/' . $assetId . '/' . $sourceId;
        $optimizedPath = $baseDir . '/optimized_play.mp4';

        Storage::disk($disk)->makeDirectory($baseDir);
        $request->file('optimized')->storeAs($baseDir, 'optimized_play.mp4', ['disk' => $disk]);

        if (! Storage::disk($disk)->exists($optimizedPath)) {
            return $this->error('Failed to store optimized file.', 500);
        }

        $hlsDir = $baseDir . '/hls';
        Storage::disk($disk)->deleteDirectory($hlsDir);
        Storage::disk($disk)->makeDirectory($hlsDir);

        $zip = new ZipArchive;
        $zipPath = $request->file('hls_zip')->getRealPath();
        if ($zip->open($zipPath) !== true) {
            Log::warning('Worker upload: invalid HLS zip', ['source_id' => $sourceId]);
            return $this->error('Invalid HLS zip file.', 422);
        }
        $extractPath = Storage::disk($disk)->path($hlsDir);
        $zip->extractTo($extractPath);
        $zip->close();

        $hlsMasterPath = $hlsDir . '/master.m3u8';
        if (! Storage::disk($disk)->exists($hlsMasterPath)) {
            Log::warning('Worker upload: HLS zip missing master.m3u8 at root', [
                'source_id' => $sourceId,
                'extract_path' => $hlsDir,
            ]);
            return $this->error('HLS zip must contain master.m3u8 at root.', 422);
        }

        $data = [
            'optimized_path' => $optimizedPath,
            'hls_master_path' => $hlsMasterPath,
            'qualities_json' => null,
        ];

        return $this->success($data);
    }

    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'error' => $message,
        ], $status);
    }

    private function sourcePayload(MediaSource $source, MediaSourceService $mediaSourceService): array
    {
        return [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'source_type' => $source->source_type,
            'status' => $source->status,
            'progress_percent' => $source->progress_percent,
            'bytes_downloaded' => $source->bytes_downloaded,
            'bytes_total' => $source->bytes_total,
            'file_size_bytes' => $source->file_size_bytes,
            'mime_type' => $source->mime_type,
            'failure_reason' => $source->failure_reason,
            'last_error' => $source->last_error,
            'last_attempt_host' => $source->last_attempt_host,
            'is_faststart' => (bool) $source->is_faststart,
            'optimize_status' => $source->optimize_status,
            'optimized_path' => $source->optimized_path,
            'optimize_error' => $source->optimize_error,
            'optimized_at' => $source->optimized_at?->toIso8601String(),
            'playback_type' => $source->playback_type,
            'hls_master_path' => $source->hls_master_path,
            'qualities' => $source->qualities_json,
            'hls_worker_status' => $source->hls_worker_status,
            'hls_worker_last_error' => $source->hls_worker_last_error,
            'public_url' => $mediaSourceService->buildPublicUrl($source),
            'download_url' => $mediaSourceService->buildDownloadUrl($source),
            'playback' => $mediaSourceService->buildPlaybackManifest($source),
            'created_at' => $source->created_at?->toIso8601String(),
            'updated_at' => $source->updated_at?->toIso8601String(),
        ];
    }
}

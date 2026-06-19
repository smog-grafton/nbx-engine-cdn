<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContaboStorageCredentialService;
use App\Services\MediaSourceService;
use App\Services\MediaBinaryDetector;
use App\Services\NbxEngineService;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NbxEngineController extends Controller
{
    public function store(Request $request, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        abort_unless((bool) config('nbx.enabled', true), 503, 'NBX Engine is disabled.');

        $validated = $request->validate([
            'input_type' => ['required', Rule::in(['remote_fetch', 'upload', 'object_storage'])],
            'source_url' => ['required_if:input_type,remote_fetch', 'nullable', 'string', 'max:4096'],
            'object_url' => ['required_if:input_type,object_storage', 'nullable', 'string', 'max:4096'],
            'object_disk' => ['nullable', 'string', 'max:100'],
            'object_key' => ['nullable', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'import_mode' => ['nullable', Rule::in(['now', 'queue'])],
            'storage_target' => ['nullable', Rule::in(['contabo', 'public', 'local'])],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
        ]);

        if (in_array(($validated['input_type'] ?? null), ['remote_fetch', 'object_storage'], true)) {
            $validated['source_url'] = SafeRemoteMediaUrl::assertAllowed(
                $validated['source_url'] ?? $validated['object_url'] ?? null
            );
            $source = $nbx->createRemoteJob($validated, $mediaSourceService);

            return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
        }

        return $this->error('Use the NBX upload endpoint for upload jobs.', 422);
    }

    public function upload(Request $request, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        abort_unless((bool) config('nbx.enabled', true), 503, 'NBX Engine is disabled.');

        $maxUploadMb = max(1, (int) config('nbx.max_upload_mb', config('cdn.max_upload_mb', 2048)));
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . ($maxUploadMb * 1024)],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'storage_target' => ['nullable', Rule::in(['contabo', 'public', 'local'])],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
        ]);

        $source = $nbx->createUploadJob($validated, $request->file('file'), $mediaSourceService);

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
    }

    public function initUpload(Request $request): JsonResponse
    {
        abort_unless((bool) config('nbx.enabled', true), 503, 'NBX Engine is disabled.');

        $maxUploadMb = max(1, (int) config('nbx.max_upload_mb', config('cdn.max_upload_mb', 2048)));
        $maxBytes = $maxUploadMb * 1024 * 1024;
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['nullable', 'integer', 'min:1', 'max:' . $maxBytes],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'extension' => ['nullable', 'string', 'max:20'],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'storage_target' => ['nullable', Rule::in(['contabo', 'public', 'local'])],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
        ]);

        if ($error = $this->uploadPolicyError($validated['filename'], $validated['mime_type'] ?? null, $validated['extension'] ?? null)) {
            return $this->error($error, 422);
        }

        $sessionId = (string) Str::uuid();
        $token = Str::random(64);
        $ttlMinutes = max(5, (int) config('nbx.upload_session_ttl_minutes', 60));
        $expiresAt = now()->addMinutes($ttlMinutes);
        $session = array_merge($validated, [
            'session_id' => $sessionId,
            'token_hash' => hash('sha256', $token),
            'max_upload_size_bytes' => $maxBytes,
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ]);

        Cache::put($this->uploadSessionKey($sessionId), $session, $expiresAt);

        $publicBaseUrl = rtrim((string) (config('nbx.public_url') ?: config('app.url')), '/');
        $completeUrl = $publicBaseUrl . '/api/v1/nbx/uploads/' . $sessionId . '/complete';
        $cancelUrl = $publicBaseUrl . '/api/v1/nbx/uploads/' . $sessionId . '/cancel';

        return $this->success([
            'session_id' => $sessionId,
            'upload_url' => $completeUrl,
            'complete_url' => $completeUrl,
            'cancel_url' => $cancelUrl,
            'method' => 'POST',
            'field' => 'file',
            'headers' => [
                'X-NBX-Upload-Token' => $token,
            ],
            'expires_at' => $expiresAt->toIso8601String(),
            'max_upload_size_bytes' => $maxBytes,
            'allowed_extensions' => $this->allowedUploadExtensions(),
            'allowed_mimes' => $this->allowedUploadMimes(),
        ], 201);
    }

    public function completeUpload(
        Request $request,
        string $session,
        NbxEngineService $nbx,
        MediaSourceService $mediaSourceService
    ): JsonResponse {
        abort_unless((bool) config('nbx.enabled', true), 503, 'NBX Engine is disabled.');

        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }

        $maxUploadMb = max(1, (int) ceil(((int) ($sessionData['max_upload_size_bytes'] ?? 0)) / 1024 / 1024));
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . ($maxUploadMb * 1024)],
        ]);

        $file = $request->file('file');
        if (! $file) {
            return $this->error('Upload file is missing.', 422);
        }

        $actualSize = (int) ($file->getSize() ?: 0);
        $expectedSize = isset($sessionData['size_bytes']) ? (int) $sessionData['size_bytes'] : null;
        if ($expectedSize && $actualSize > $expectedSize) {
            return $this->error('Uploaded file is larger than the initialized session size.', 422);
        }

        if ($error = $this->uploadPolicyError($file->getClientOriginalName(), $file->getMimeType() ?: $file->getClientMimeType(), $sessionData['extension'] ?? null)) {
            return $this->error($error, 422);
        }

        $jobData = $sessionData;
        unset($jobData['session_id'], $jobData['token_hash'], $jobData['max_upload_size_bytes'], $jobData['expires_at'], $jobData['created_at']);
        $jobData['title'] = $jobData['title'] ?? pathinfo((string) ($jobData['filename'] ?? $file->getClientOriginalName()), PATHINFO_FILENAME);

        $source = $nbx->createUploadJob($jobData, $file, $mediaSourceService);
        Cache::forget($this->uploadSessionKey($session));

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
    }

    public function cancelUpload(Request $request, string $session): JsonResponse
    {
        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }

        Cache::forget($this->uploadSessionKey($session));

        return $this->success([
            'session_id' => $session,
            'cancelled' => true,
        ]);
    }

    public function show(string $jobId, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        $source = $nbx->findForDiscovery(['job_id' => $jobId]);
        if (! $source) {
            return $this->error('NBX job not found.', 404);
        }

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService));
    }

    public function discover(Request $request, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'source_url' => ['nullable', 'string', 'max:4096'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
        ]);

        $source = $nbx->findForDiscovery($validated);
        if (! $source) {
            return $this->error('No NBX source matched the discovery request.', 404);
        }

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService));
    }

    public function diagnostics(MediaBinaryDetector $binaries, ContaboStorageCredentialService $contabo): JsonResponse
    {
        $contaboReady = $contabo->ensureRuntimeDiskCredentials();
        $contaboConfig = config('filesystems.disks.contabo', []);

        return $this->success([
            'binaries' => $binaries->diagnostics(),
            'docker_expected_paths' => [
                'ffmpeg' => '/usr/bin/ffmpeg',
                'ffprobe' => '/usr/bin/ffprobe',
            ],
            'hls_enabled' => (bool) config('cdn.enable_hls', true),
            'default_hls_profiles' => (array) config('cdn.hls_profiles', []),
            'nbx_defaults' => [
                'storage' => (string) config('nbx.default_storage', 'contabo'),
                'faststart' => (bool) config('nbx.default_faststart', true),
                'hls_480p' => (bool) config('nbx.default_hls_480', true),
                'hls_720p' => (bool) config('nbx.default_hls_720', false),
                'hls_1080p' => (bool) config('nbx.default_hls_1080', false),
            ],
            'contabo' => [
                'ready' => $contaboReady,
                'bucket' => $contaboConfig['bucket'] ?? null,
                'endpoint' => $contaboConfig['endpoint'] ?? null,
                'public_url' => $contaboConfig['url'] ?? null,
                'error' => $contaboReady ? null : $contabo->configurationError(),
            ],
        ]);
    }

    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'error' => null], $status);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'error' => $message], $status);
    }

    private function uploadSessionKey(string $session): string
    {
        return 'nbx:upload-session:' . $session;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function authorizedUploadSession(Request $request, string $session): ?array
    {
        $sessionData = Cache::get($this->uploadSessionKey($session));
        if (! is_array($sessionData)) {
            return null;
        }

        $expiresAt = isset($sessionData['expires_at']) ? strtotime((string) $sessionData['expires_at']) : false;
        if ($expiresAt === false || $expiresAt < time()) {
            Cache::forget($this->uploadSessionKey($session));

            return null;
        }

        $provided = (string) ($request->header('X-NBX-Upload-Token') ?: $request->bearerToken() ?: $request->input('upload_token', ''));
        if ($provided === '' || ! hash_equals((string) ($sessionData['token_hash'] ?? ''), hash('sha256', $provided))) {
            return null;
        }

        return $sessionData;
    }

    private function uploadPolicyError(string $filename, ?string $mimeType = null, ?string $expectedExtension = null): ?string
    {
        $extension = strtolower(trim((string) ($expectedExtension ?: pathinfo($filename, PATHINFO_EXTENSION))));
        if ($extension === '') {
            return 'Upload filename must include a video file extension.';
        }

        if (! in_array($extension, $this->allowedUploadExtensions(), true)) {
            return 'Upload extension .' . $extension . ' is not allowed for NBX Engine.';
        }

        $mime = strtolower(trim((string) $mimeType));
        if ($mime !== '' && ! in_array($mime, $this->allowedUploadMimes(), true)) {
            return 'Upload MIME type ' . $mime . ' is not allowed for NBX Engine.';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function allowedUploadExtensions(): array
    {
        return array_values(array_filter((array) config('nbx.allowed_upload_extensions', []), 'is_string'));
    }

    /**
     * @return array<int, string>
     */
    private function allowedUploadMimes(): array
    {
        return array_values(array_filter((array) config('nbx.allowed_upload_mimes', []), 'is_string'));
    }
}

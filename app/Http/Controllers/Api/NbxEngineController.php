<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaSource;
use App\Services\ContaboStorageCredentialService;
use App\Services\MediaBinaryDetector;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Support\SafeRemoteMediaUrl;
use App\Support\LegacyCdnUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NbxEngineController extends Controller
{
    public function store(Request $request, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        abort_unless((bool) config('nbx.enabled', true), 503, 'NBX Engine is disabled.');

        $validated = $request->validate([
            'input_type' => ['required', Rule::in(['remote_fetch', 'upload', 'object_storage', 'telegram'])],
            'source_url' => ['required_if:input_type,remote_fetch', 'nullable', 'string', 'max:4096'],
            'migration' => ['nullable', 'array'],
            'migration.kind' => ['nullable', 'string', 'in:legacy_cdn'],
            'migration.legacy_asset_id' => ['nullable', 'string', 'max:100'],
            'migration.legacy_source_id' => ['nullable', 'integer', 'min:1'],
            'migration.original_source_url' => ['nullable', 'url', 'max:4096'],
            'migration.fallback_source_url' => ['nullable', 'url', 'max:4096'],
            'migration.stored_filename' => ['nullable', 'string', 'max:255'],
            'migration.stored_size_bytes' => ['nullable', 'integer', 'min:1'],
            'migration.stored_mime_type' => ['nullable', 'string', 'max:255'],
            'migration.lookup_url' => ['nullable', 'url', 'max:4096'],
            'migration.idempotency_key' => ['nullable', 'string', 'max:128'],
            'telegram_url' => ['required_if:input_type,telegram', 'nullable', 'url', 'max:4096'],
            'object_url' => ['required_if:input_type,object_storage', 'nullable', 'string', 'max:4096'],
            'object_disk' => ['nullable', 'string', 'max:100'],
            'object_key' => ['nullable', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'import_mode' => ['nullable', Rule::in(['now', 'queue'])],
            'storage_target' => ['nullable', 'string', 'max:32'],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'retention_policy' => ['nullable', Rule::in(['keep_original', 'retain_original', 'keep_both', 'keep_original_and_optimized', 'keep_original_only', 'original_only', 'delete_after_optimization', 'delete_original_after_successful_optimization', 'optimized_only', 'keep_optimized_only'])],
            'keep_original' => ['nullable', 'boolean'],
            'delete_original_after_optimization' => ['nullable', 'boolean'],
            'max_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'source_profile_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'processing_preset' => ['nullable', Rule::in(['automatic', 'faststart_only', 'balanced_720p', 'smaller_720p', 'keep_source_resolution', 'custom'])],
            'crf' => ['nullable', 'integer', 'min:16', 'max:35'],
            'encoder_preset' => ['nullable', Rule::in(['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow'])],
            'audio_bitrate' => ['nullable', Rule::in(['64k', '96k', '128k', '160k', '192k'])],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'processing_revision' => ['nullable', 'integer', 'min:1'],
            'operation' => ['nullable', 'string', 'max:64'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $validated = $this->normalizeProcessingOptions($validated);

        if (($validated['input_type'] ?? null) === 'telegram') {
            $source = $nbx->createTelegramJob($validated);

            return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
        }

        if (in_array(($validated['input_type'] ?? null), ['remote_fetch', 'object_storage'], true)) {
            $validated['source_url'] = app(LegacyCdnUrlResolver::class)->resolve(
                $validated['source_url'] ?? $validated['object_url'] ?? null
            );
            try {
                $validated['source_url'] = SafeRemoteMediaUrl::assertAllowed(
                    $validated['source_url'] ?? $validated['object_url'] ?? null
                );
            } catch (\Throwable $originalError) {
                $migration = (array) ($validated['migration'] ?? []);
                $fallbackUrl = trim((string) ($migration['fallback_source_url'] ?? ''));
                if (($migration['kind'] ?? null) !== 'legacy_cdn' || $fallbackUrl === '') {
                    throw $originalError;
                }

                $validated['source_url'] = SafeRemoteMediaUrl::assertAllowed($fallbackUrl);
                $migration['state'] = 'trying_legacy_public';
                $migration['original_error'] = substr(
                    preg_replace('/\s+/', ' ', trim($originalError->getMessage())) ?: 'Original source was unavailable.',
                    0,
                    1000,
                );
                $validated['migration'] = $migration;
            }
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
            'file' => ['required', 'file', 'max:'.($maxUploadMb * 1024)],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'storage_target' => ['nullable', 'string', 'max:32'],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'retention_policy' => ['nullable', Rule::in(['keep_original', 'retain_original', 'keep_both', 'keep_original_and_optimized', 'keep_original_only', 'original_only', 'delete_after_optimization', 'delete_original_after_successful_optimization', 'optimized_only', 'keep_optimized_only'])],
            'keep_original' => ['nullable', 'boolean'],
            'delete_original_after_optimization' => ['nullable', 'boolean'],
            'max_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'source_profile_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'processing_preset' => ['nullable', 'string', 'max:64'],
            'crf' => ['nullable', 'integer', 'min:16', 'max:35'],
            'encoder_preset' => ['nullable', 'string', 'max:32'],
            'audio_bitrate' => ['nullable', 'string', 'max:16'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'processing_revision' => ['nullable', 'integer', 'min:1'],
            'operation' => ['nullable', 'string', 'max:64'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
        ]);
        $validated = $this->normalizeProcessingOptions($validated);

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
            'size_bytes' => ['nullable', 'integer', 'min:1', 'max:'.$maxBytes],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'extension' => ['nullable', 'string', 'max:20'],
            'title' => ['nullable', 'string', 'max:255'],
            'asset_type' => ['nullable', Rule::in(['movie', 'episode', 'generic'])],
            'description' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
            'storage_target' => ['nullable', 'string', 'max:32'],
            'faststart' => ['nullable', 'boolean'],
            'compress_enabled' => ['nullable', 'boolean'],
            'hls_480p' => ['nullable', 'boolean'],
            'hls_720p' => ['nullable', 'boolean'],
            'hls_1080p' => ['nullable', 'boolean'],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'retention_policy' => ['nullable', Rule::in(['keep_original', 'retain_original', 'keep_both', 'keep_original_and_optimized', 'keep_original_only', 'original_only', 'delete_after_optimization', 'delete_original_after_successful_optimization', 'optimized_only', 'keep_optimized_only'])],
            'keep_original' => ['nullable', 'boolean'],
            'delete_original_after_optimization' => ['nullable', 'boolean'],
            'max_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'source_profile_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'processing_preset' => ['nullable', 'string', 'max:64'],
            'crf' => ['nullable', 'integer', 'min:16', 'max:35'],
            'encoder_preset' => ['nullable', 'string', 'max:32'],
            'audio_bitrate' => ['nullable', 'string', 'max:16'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'processing_revision' => ['nullable', 'integer', 'min:1'],
            'operation' => ['nullable', 'string', 'max:64'],
            'video_ref_type' => ['nullable', 'string', 'max:100'],
            'video_ref_id' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url', 'max:4096'],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $validated = $this->normalizeProcessingOptions($validated);

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
            'status' => 'initialized',
            'received_chunks' => [],
        ]);

        Cache::put($this->uploadSessionKey($sessionId), $session, $expiresAt);

        $publicBaseUrl = rtrim((string) (config('nbx.public_url') ?: config('app.url')), '/');
        $completeUrl = $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/complete';
        $cancelUrl = $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/cancel';
        $chunkSize = max(1, (int) config('nbx.upload_chunk_size_mb', 8)) * 1024 * 1024;
        $statusUrl = $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId;
        $chunkCompleteUrl = $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/complete-chunks';

        return $this->success([
            'session_id' => $sessionId,
            'upload_url' => $completeUrl,
            'complete_url' => $completeUrl,
            'cancel_url' => $cancelUrl,
            'upload_mode' => 'chunked',
            'chunk_size_bytes' => $chunkSize,
            'total_chunks' => isset($validated['size_bytes'])
                ? (int) ceil(((int) $validated['size_bytes']) / $chunkSize)
                : null,
            'chunk_url_template' => $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/chunks/{index}',
            'status_url' => $statusUrl,
            'chunk_complete_url' => $chunkCompleteUrl,
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

    public function uploadStatus(Request $request, string $session): JsonResponse
    {
        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }

        return $this->success($this->uploadSessionPayload($sessionData));
    }

    public function uploadChunk(Request $request, string $session, int $chunk): JsonResponse
    {
        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }
        if (($sessionData['status'] ?? null) === 'completed') {
            return $this->success($this->uploadSessionPayload($sessionData));
        }

        $expectedSize = (int) ($sessionData['size_bytes'] ?? 0);
        if ($expectedSize <= 0) {
            return $this->error('Chunked uploads require size_bytes during initialization.', 422);
        }
        $chunkSize = max(1, (int) config('nbx.upload_chunk_size_mb', 8)) * 1024 * 1024;
        $totalChunks = (int) ceil($expectedSize / $chunkSize);
        if ($chunk < 0 || $chunk >= $totalChunks) {
            return $this->error('Chunk index is outside this upload session.', 422);
        }
        $expectedChunkSize = $chunk === $totalChunks - 1
            ? $expectedSize - ($chunk * $chunkSize)
            : $chunkSize;
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > $expectedChunkSize || $contentLength > $chunkSize) {
            return $this->error('Chunk body exceeds the allowed chunk size.', 413);
        }

        $directory = $this->uploadSessionDirectory($session);
        File::ensureDirectoryExists($directory, 0700, true);
        $target = $directory.'/chunk-'.$chunk.'.part';
        $temporary = $target.'.'.Str::random(8).'.tmp';
        $input = $request->getContent(true);
        $output = fopen($temporary, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);

            return $this->error('Could not open the chunk stream.', 500);
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (! feof($input)) {
                $buffer = fread($input, 1024 * 1024);
                if ($buffer === false) {
                    throw new \RuntimeException('Could not read the chunk stream.');
                }
                if ($buffer === '') {
                    break;
                }
                $length = strlen($buffer);
                $written += $length;
                if ($written > $expectedChunkSize) {
                    throw new \RuntimeException('Chunk body exceeds the expected size.');
                }
                hash_update($hash, $buffer);
                if (fwrite($output, $buffer) !== $length) {
                    throw new \RuntimeException('Could not write the complete chunk.');
                }
            }
        } catch (\Throwable $exception) {
            fclose($output);
            @unlink($temporary);

            return $this->error($exception->getMessage(), 422);
        }
        fclose($output);
        if ($written !== $expectedChunkSize) {
            @unlink($temporary);

            return $this->error("Chunk {$chunk} must contain exactly {$expectedChunkSize} bytes.", 422);
        }
        $digest = hash_final($hash);
        $providedDigest = strtolower(trim((string) $request->header('X-Chunk-SHA256', '')));
        if ($providedDigest !== '' && ! hash_equals($providedDigest, $digest)) {
            @unlink($temporary);

            return $this->error('Chunk checksum does not match.', 422);
        }
        if (is_file($target)) {
            $existingDigest = hash_file('sha256', $target);
            if ($existingDigest === $digest) {
                @unlink($temporary);
            } elseif (! rename($temporary, $target)) {
                @unlink($temporary);

                return $this->error('Could not persist the verified upload chunk.', 500);
            }
        } elseif (! rename($temporary, $target)) {
            @unlink($temporary);

            return $this->error('Could not persist the verified upload chunk.', 500);
        }

        $sessionData['status'] = 'uploading';
        $sessionData['received_chunks'][(string) $chunk] = [
            'size_bytes' => $written,
            'sha256' => $digest,
            'received_at' => now()->toIso8601String(),
        ];
        $this->putUploadSession($session, $sessionData);

        return $this->success([
            ...$this->uploadSessionPayload($sessionData),
            'accepted_chunk' => $chunk,
            'chunk_sha256' => $digest,
        ], 202);
    }

    public function completeChunkedUpload(
        Request $request,
        string $session,
        NbxEngineService $nbx,
        MediaSourceService $mediaSourceService
    ): JsonResponse {
        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }
        if (($sessionData['status'] ?? null) === 'completed' && is_array($sessionData['result'] ?? null)) {
            return $this->success($sessionData['result'], 202);
        }

        $lock = Cache::lock('nbx:upload-session-complete:'.$session, 300);
        if (! $lock->get()) {
            return $this->error('This upload is already being completed.', 409);
        }

        try {
            $expectedSize = (int) ($sessionData['size_bytes'] ?? 0);
            $chunkSize = max(1, (int) config('nbx.upload_chunk_size_mb', 8)) * 1024 * 1024;
            $totalChunks = $expectedSize > 0 ? (int) ceil($expectedSize / $chunkSize) : 0;
            $received = array_map('intval', array_keys((array) ($sessionData['received_chunks'] ?? [])));
            $missing = array_values(array_diff(range(0, max(0, $totalChunks - 1)), $received));
            if ($totalChunks < 1 || $missing !== []) {
                return response()->json([
                    'success' => false,
                    'data' => ['missing_chunks' => $missing],
                    'error' => 'Upload is incomplete.',
                ], 422);
            }

            $directory = $this->uploadSessionDirectory($session);
            $assembled = $directory.'/assembled-upload';
            $output = fopen($assembled, 'wb');
            if (! is_resource($output)) {
                return $this->error('Could not assemble the uploaded chunks.', 500);
            }
            $fileHash = hash_init('sha256');
            $assembledBytes = 0;
            try {
                for ($index = 0; $index < $totalChunks; $index++) {
                    $input = fopen($directory.'/chunk-'.$index.'.part', 'rb');
                    if (! is_resource($input)) {
                        throw new \RuntimeException("Chunk {$index} is missing from temporary storage.");
                    }
                    while (! feof($input)) {
                        $buffer = fread($input, 1024 * 1024);
                        if ($buffer === false) {
                            fclose($input);
                            throw new \RuntimeException("Could not read chunk {$index}.");
                        }
                        if ($buffer === '') {
                            break;
                        }
                        $assembledBytes += strlen($buffer);
                        hash_update($fileHash, $buffer);
                        $length = strlen($buffer);
                        if (fwrite($output, $buffer) !== $length) {
                            fclose($input);
                            throw new \RuntimeException('Could not write the complete assembled upload.');
                        }
                    }
                    fclose($input);
                }
            } catch (\Throwable $exception) {
                fclose($output);
                @unlink($assembled);

                return $this->error($exception->getMessage(), 422);
            }
            fclose($output);
            if ($assembledBytes !== $expectedSize) {
                @unlink($assembled);

                return $this->error('Assembled file size does not match the initialized upload.', 422);
            }
            $digest = hash_final($fileHash);
            $providedDigest = strtolower(trim((string) ($request->header('X-File-SHA256') ?: ($sessionData['checksum_sha256'] ?? ''))));
            if ($providedDigest !== '' && ! hash_equals($providedDigest, $digest)) {
                @unlink($assembled);

                return $this->error('Complete file checksum does not match.', 422);
            }
            $detectedMime = class_exists(\finfo::class)
                ? ((new \finfo(FILEINFO_MIME_TYPE))->file($assembled) ?: 'application/octet-stream')
                : 'application/octet-stream';
            if ($error = $this->uploadPolicyError(
                (string) $sessionData['filename'],
                $detectedMime,
                $sessionData['extension'] ?? null
            )) {
                @unlink($assembled);

                return $this->error($error, 422);
            }

            $jobData = $sessionData;
            unset(
                $jobData['session_id'],
                $jobData['token_hash'],
                $jobData['max_upload_size_bytes'],
                $jobData['expires_at'],
                $jobData['created_at'],
                $jobData['received_chunks'],
                $jobData['status']
            );
            $filename = (string) $sessionData['filename'];
            $uploaded = new UploadedFile(
                $assembled,
                $filename,
                $detectedMime,
                null,
                true
            );
            if (! empty($sessionData['existing_source_id'])) {
                $source = MediaSource::with('asset')->find((int) $sessionData['existing_source_id']);
                if (! $source) {
                    return $this->error('The NBX migration source no longer exists.', 404);
                }
                $source = $nbx->acceptPushedUpload($source, $uploaded, $mediaSourceService, $sessionData);
            } else {
                $source = $nbx->createUploadJob($jobData, $uploaded, $mediaSourceService);
            }
            $result = $nbx->discoveryPayload($source, $mediaSourceService);
            $sessionData['status'] = 'completed';
            $sessionData['completed_at'] = now()->toIso8601String();
            $sessionData['file_sha256'] = $digest;
            $sessionData['result'] = $result;
            $sessionData['received_chunks'] = [];
            $this->putUploadSession($session, $sessionData);
            File::deleteDirectory($directory);

            return $this->success($result, 202);
        } finally {
            $lock->release();
        }
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
            'file' => ['required', 'file', 'max:'.($maxUploadMb * 1024)],
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
        File::deleteDirectory($this->uploadSessionDirectory($session));

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
    }

    public function cancelUpload(Request $request, string $session): JsonResponse
    {
        $sessionData = $this->authorizedUploadSession($request, $session);
        if (! $sessionData) {
            return $this->error('Upload session was not found, expired, or the upload token is invalid.', 401);
        }

        Cache::forget($this->uploadSessionKey($session));
        File::deleteDirectory($this->uploadSessionDirectory($session));

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

    public function action(
        Request $request,
        string $jobId,
        NbxEngineService $nbx,
        MediaSourceService $mediaSourceService
    ): JsonResponse {
        $validated = $request->validate([
            'operation' => ['required', Rule::in([
                'retry',
                'retry_storage',
                'retry_portal_sync',
                'reconcile',
                'reprocess',
                'force_reprocess',
                'force_reimport',
                'generate_faststart',
                'compress',
                'generate_hls',
            ])],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'compress_enabled' => ['nullable', 'boolean'],
            'faststart' => ['nullable', 'boolean'],
            'retention_policy' => ['nullable', Rule::in(['keep_original', 'retain_original', 'keep_both', 'keep_original_and_optimized', 'keep_original_only', 'original_only', 'delete_after_optimization', 'delete_original_after_successful_optimization', 'optimized_only', 'keep_optimized_only'])],
            'allow_downloads' => ['nullable', 'boolean'],
            'allow_hls_streaming' => ['nullable', 'boolean'],
            'hls' => ['nullable', 'array'],
            'hls.480p' => ['nullable', 'boolean'],
            'hls.720p' => ['nullable', 'boolean'],
            'hls.1080p' => ['nullable', 'boolean'],
            'max_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'source_profile_resolution' => ['nullable', Rule::in([240, 360, 480, 720, 1080, '240p', '360p', '480p', '720p', '1080p'])],
            'processing_preset' => ['nullable', 'string', 'max:64'],
            'crf' => ['nullable', 'integer', 'min:16', 'max:35'],
            'encoder_preset' => ['nullable', 'string', 'max:32'],
            'audio_bitrate' => ['nullable', 'string', 'max:16'],
        ]);
        $validated = $this->normalizeProcessingOptions($validated);
        $source = $nbx->findForDiscovery(['job_id' => $jobId]);
        if (! $source) {
            return $this->error('NBX job not found.', 404);
        }

        $source = $nbx->performAction($source, (string) $validated['operation'], $validated, $mediaSourceService);

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService), 202);
    }

    public function destroyOriginal(
        Request $request,
        string $jobId,
        NbxEngineService $nbx,
        MediaSourceService $mediaSourceService
    ): JsonResponse {
        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $source = $nbx->findForDiscovery(['job_id' => $jobId]);
        if (! $source) {
            return $this->error('NBX job not found.', 404);
        }

        $source = $nbx->deleteOriginal($source, $validated['idempotency_key'] ?? null);

        return $this->success($nbx->discoveryPayload($source, $mediaSourceService));
    }

    public function discover(Request $request, NbxEngineService $nbx, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'asset_id' => ['nullable', 'uuid'],
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
                'storage' => (string) config('nbx.default_storage', 'auto'),
                'faststart' => (bool) config('nbx.default_faststart', true),
                'hls_480p' => (bool) config('nbx.default_hls_480', false),
                'hls_720p' => (bool) config('nbx.default_hls_720', false),
                'hls_1080p' => (bool) config('nbx.default_hls_1080', false),
            ],
            'upload_limits' => [
                'php_upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'php_post_max_size' => (string) ini_get('post_max_size'),
                'php_max_execution_time_seconds' => (int) ini_get('max_execution_time'),
                'nbx_max_upload_mb' => (int) config('nbx.max_upload_mb', 2048),
                'telegram_handoff_mode' => 'source_url',
                'diagnosis' => 'HTTP 413 happens before Laravel when a reverse proxy or PHP body limit rejects a streamed multipart upload. Telegram source_url handoff avoids sending the media body through that request.',
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

    /** @param array<string, mixed> $options */
    private function normalizeProcessingOptions(array $options): array
    {
        // Portal historically sent either `max_resolution` (number) or
        // `resolution` (often "720p"). Normalize both at the API boundary so
        // no downstream default can silently overwrite an explicit request.
        if (array_key_exists('max_resolution', $options)) {
            $options['max_resolution'] = $this->normalizeResolution($options['max_resolution']);
        } elseif (array_key_exists('resolution', $options)) {
            $options['max_resolution'] = $this->normalizeResolution($options['resolution']);
        }
        if (array_key_exists('source_profile_resolution', $options)) {
            $options['source_profile_resolution'] = $this->normalizeResolution($options['source_profile_resolution']);
        }

        return $options;
    }

    private function normalizeResolution(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = rtrim(strtolower(trim($value)), 'p');
        }

        return is_numeric($value) && in_array((int) $value, [240, 360, 480, 720, 1080], true)
            ? (int) $value
            : null;
    }

    private function uploadSessionKey(string $session): string
    {
        return 'nbx:upload-session:'.$session;
    }

    private function uploadSessionDirectory(string $session): string
    {
        return storage_path('app/'.trim((string) config('nbx.upload_session_dir', 'nbx/upload-sessions'), '/').'/'.$session);
    }

    private function putUploadSession(string $session, array $sessionData): void
    {
        $seconds = max(60, now()->diffInSeconds(
            \Illuminate\Support\Carbon::parse((string) $sessionData['expires_at']),
            false
        ));
        Cache::put($this->uploadSessionKey($session), $sessionData, $seconds);
    }

    private function uploadSessionPayload(array $sessionData): array
    {
        $expectedSize = (int) ($sessionData['size_bytes'] ?? 0);
        $chunkSize = max(1, (int) config('nbx.upload_chunk_size_mb', 8)) * 1024 * 1024;
        $totalChunks = $expectedSize > 0 ? (int) ceil($expectedSize / $chunkSize) : 0;
        $received = array_map('intval', array_keys((array) ($sessionData['received_chunks'] ?? [])));
        sort($received);

        return [
            'session_id' => $sessionData['session_id'],
            'status' => $sessionData['status'] ?? 'initialized',
            'expires_at' => $sessionData['expires_at'],
            'size_bytes' => $expectedSize,
            'chunk_size_bytes' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => $received,
            'missing_chunks' => $totalChunks > 0
                ? array_values(array_diff(range(0, $totalChunks - 1), $received))
                : [],
            'uploaded_bytes' => array_sum(array_column((array) ($sessionData['received_chunks'] ?? []), 'size_bytes')),
            'result' => $sessionData['status'] === 'completed' ? ($sessionData['result'] ?? null) : null,
        ];
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
            return 'Upload extension .'.$extension.' is not allowed for NBX Engine.';
        }

        $mime = strtolower(trim((string) $mimeType));
        if ($mime !== '' && ! in_array($mime, $this->allowedUploadMimes(), true)) {
            return 'Upload MIME type '.$mime.' is not allowed for NBX Engine.';
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

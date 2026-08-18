<?php

namespace App\Services;

use App\Models\MediaSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LegacyCdnPushService
{
    /**
     * Ask the old CDN to push its local file into a one-time NBX upload
     * session. The video bytes travel old-CDN → NBX; Portal is not involved.
     */
    public function request(MediaSource $source, string $triggerError): void
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $migration = (array) ($metadata['migration'] ?? []);
        $legacySourceId = (int) ($migration['legacy_source_id'] ?? 0);
        $size = (int) ($migration['stored_size_bytes'] ?? 0);

        if ($legacySourceId < 1 || $size < 1) {
            throw new \RuntimeException('Legacy CDN push is unavailable because the stored source identity or size is missing.', 0, null);
        }

        $baseUrl = rtrim((string) config('cdn.legacy_cdn_api_base_url', ''), '/');
        $apiToken = trim((string) config('cdn.legacy_cdn_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            throw new \RuntimeException('Legacy CDN push is not configured on NBX.');
        }

        $existingSession = (string) data_get($migration, 'push.session_id', '');
        if ($existingSession !== '') {
            $existing = Cache::get($this->sessionKey($existingSession));
            if (is_array($existing) && ($existing['status'] ?? null) !== 'completed') {
                return;
            }
        }

        $filename = $this->filename(
            $migration['stored_filename'] ?? null,
            (string) ($source->source_url ?? ''),
            $source->id,
        );
        $token = Str::random(64);
        $sessionId = (string) Str::uuid();
        $ttlMinutes = max(10, (int) config('nbx.upload_session_ttl_minutes', 60));
        $expiresAt = now()->addMinutes($ttlMinutes);
        $chunkSize = max(1, (int) config('nbx.upload_chunk_size_mb', 8)) * 1024 * 1024;
        $maxUploadBytes = max(1, (int) config('nbx.max_upload_mb', 20480)) * 1024 * 1024;
        if ($size > $maxUploadBytes) {
            throw new \RuntimeException('The legacy CDN source exceeds NBX’s configured upload limit.');
        }
        $publicBaseUrl = rtrim((string) (config('nbx.public_url') ?: config('app.url')), '/');

        $requested = (array) data_get($metadata, 'nbx.requested', []);
        $session = [
            'session_id' => $sessionId,
            'token_hash' => hash('sha256', $token),
            'max_upload_size_bytes' => $maxUploadBytes,
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => now()->toIso8601String(),
            'status' => 'initialized',
            'received_chunks' => [],
            'size_bytes' => $size,
            'filename' => $filename,
            'mime_type' => (string) ($migration['stored_mime_type'] ?? 'video/mp4'),
            'extension' => strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)),
            'title' => (string) ($source->asset?->title ?? pathinfo($filename, PATHINFO_FILENAME)),
            'asset_type' => (string) ($source->asset?->type ?? 'generic'),
            'description' => (string) ($source->asset?->description ?? ''),
            'visibility' => (string) ($source->asset?->visibility ?? 'public'),
            'storage_target' => (string) ($metadata['nbx']['storage_target'] ?? $source->storage_target_key ?? 'auto'),
            'faststart' => (bool) ($requested['faststart'] ?? true),
            'compress_enabled' => (bool) ($requested['compression'] ?? false),
            'hls_480p' => (bool) data_get($requested, 'hls.480p', false),
            'hls_720p' => (bool) data_get($requested, 'hls.720p', false),
            'hls_1080p' => (bool) data_get($requested, 'hls.1080p', false),
            'allow_downloads' => (bool) ($requested['allow_downloads'] ?? true),
            'allow_hls_streaming' => (bool) ($requested['allow_hls_streaming'] ?? true),
            'retention_policy' => (string) ($requested['retention_policy'] ?? 'optimized_only'),
            'max_resolution' => $requested['max_resolution'] ?? null,
            'processing_preset' => (string) ($requested['processing_preset'] ?? 'automatic'),
            'crf' => $requested['crf'] ?? null,
            'encoder_preset' => $requested['encoder_preset'] ?? null,
            'audio_bitrate' => $requested['audio_bitrate'] ?? null,
            'existing_source_id' => $source->id,
            'existing_asset_id' => (string) $source->media_asset_id,
        ];
        Cache::put($this->sessionKey($sessionId), $session, $expiresAt);

        $target = [
            'session_id' => $sessionId,
            'token' => $token,
            'size_bytes' => $size,
            'chunk_size_bytes' => $chunkSize,
            'filename' => $filename,
            'chunk_url_template' => $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/chunks/{index}',
            'complete_url' => $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/complete-chunks',
            'cancel_url' => $publicBaseUrl.'/api/v1/nbx/uploads/'.$sessionId.'/cancel',
        ];

        $migration['state'] = 'legacy_push_requested';
        $migration['push'] = [
            'session_id' => $sessionId,
            'requested_at' => now()->toIso8601String(),
            'trigger_error' => $this->safeError($triggerError),
        ];
        $metadata['migration'] = $migration;
        $source->update([
            'status' => 'proxying',
            'processing_stage' => 'legacy_cdn_push',
            'processing_diagnostics' => 'Waiting for the legacy CDN to push its local media copy to NBX.',
            'source_metadata' => $metadata,
        ]);

        try {
            $response = $this->client($baseUrl, $apiToken)
                ->post('/api/v1/media/sources/'.$legacySourceId.'/push-to-nbx', [
                    'nbx_upload' => $target,
                ]);
        } catch (\Throwable $exception) {
            Cache::forget($this->sessionKey($sessionId));
            throw new \RuntimeException('The legacy CDN push request could not be delivered.', 0, $exception);
        }

        if (! $response->successful()) {
            Cache::forget($this->sessionKey($sessionId));
            $body = $response->json();
            $message = is_array($body) ? ($body['error'] ?? null) : null;
            throw new \RuntimeException((string) ($message ?: 'The legacy CDN rejected the migration push request.'));
        }
    }

    public function sessionKey(string $sessionId): string
    {
        return 'nbx:upload-session:'.$sessionId;
    }

    private function client(string $baseUrl, string $token): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($token)
            ->connectTimeout(30)
            ->timeout(60);
    }

    private function filename(mixed $candidate, string $url, int $sourceId): string
    {
        $value = is_string($candidate) && trim($candidate) !== ''
            ? $candidate
            : basename((string) parse_url($url, PHP_URL_PATH));
        $value = preg_replace('/[^A-Za-z0-9._-]/', '_', urldecode((string) $value)) ?: '';
        $value = ltrim($value, '.');

        return $value !== '' && pathinfo($value, PATHINFO_EXTENSION) !== ''
            ? $value
            : 'legacy-source-'.$sourceId.'.mp4';
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/\s+/', ' ', trim($error)) ?: 'Legacy CDN public fetch failed.', 0, 1000);
    }
}

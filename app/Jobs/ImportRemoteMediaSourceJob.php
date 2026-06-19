<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Services\NbxEngineService;
use App\Services\VideoProbeService;
use App\Support\MediaUrl;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportRemoteMediaSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    public function __construct(
        public int $sourceId,
        public string $jobId
    ) {
        $this->onQueue((string) config('cdn.import_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'remote-import:' . $this->sourceId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('remote-import:' . $this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->dontRelease(),
        ];
    }

    public function handle(MediaSourceService $mediaSourceService): void
    {
        $source = MediaSource::with('asset')->find($this->sourceId);

        if (! $source || ! in_array($source->source_type, ['remote_fetch', 'object_storage'], true)) {
            return;
        }

        if (! $source->source_url) {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Remote URL is missing.',
                'progress_percent' => 0,
                'completed_at' => now(),
            ]);
            $mediaSourceService->refreshAssetStatus($source->asset);
            return;
        }

        try {
            $sourceUrl = SafeRemoteMediaUrl::assertAllowed((string) $source->source_url);
        } catch (\Throwable $urlError) {
            $source->update([
                'status' => 'failed',
                'failure_reason' => $urlError->getMessage(),
                'last_error' => $urlError->getMessage(),
                'progress_percent' => 0,
                'completed_at' => now(),
            ]);
            app(NbxEngineService::class)->markNbxStatus($source->fresh() ?? $source, 'failed', $urlError->getMessage());
            $mediaSourceService->refreshAssetStatus($source->asset);
            return;
        }
        if ($sourceUrl !== '' && $sourceUrl !== (string) $source->source_url) {
            $source->forceFill(['source_url' => $sourceUrl])->save();
        }

        if ($source->external_job_id && $source->external_job_id !== $this->jobId) {
            return;
        }

        $source->update([
            'status' => 'downloading',
            'failure_reason' => null,
            'last_error' => null,
            'last_attempt_host' => parse_url($sourceUrl, PHP_URL_HOST) ?: null,
            'external_job_id' => $this->jobId,
            'storage_disk' => (string) config('nbx.work_storage', $mediaSourceService->storageDisk()),
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
            'completed_at' => null,
        ]);

        $storagePath = null;
        $absolutePath = null;

        try {
            app(NbxEngineService::class)->markNbxStatus($source, 'fetching');
            $contentLength = null;
            $contentType = null;
            try {
                $headResponse = Http::connectTimeout(30)
                    ->timeout(45)
                    ->withHeaders([
                        'User-Agent' => 'NaraboxCDNImporter/1.0',
                        'Accept' => '*/*',
                    ])
                    ->withOptions($this->safeRedirectOptions())
                    ->head($sourceUrl);
                if ($headResponse->successful()) {
                    $lengthHeader = $headResponse->header('Content-Length');
                    if (is_numeric($lengthHeader)) {
                        $contentLength = max(0, (int) $lengthHeader);
                    }

                    $headerContentType = $headResponse->header('Content-Type');
                    if (is_string($headerContentType) && $headerContentType !== '') {
                        $contentType = trim(strtolower(explode(';', $headerContentType)[0]));
                    }
                }
            } catch (\Throwable) {
                $contentLength = null;
                $contentType = null;
            }

            $filename = $this->resolveRemoteFilename($sourceUrl, $source->id, $contentType);
            $storagePath = sprintf('media/%s/%d/%s', $source->media_asset_id, $source->id, $filename);
            $workDisk = (string) config('nbx.work_storage', $mediaSourceService->storageDisk());
            $absolutePath = Storage::disk($workDisk)->path($storagePath);

            Storage::disk($workDisk)->makeDirectory(dirname($storagePath));

            $source->update([
                'status' => 'processing',
                'bytes_total' => $contentLength,
            ]);

            $lastPersistedBytes = 0;
            $lastPersistedPercent = 0;

            $downloadProgress = function (
                int $downloadTotal,
                int $downloadedBytes
            ) use (
                &$contentLength,
                &$lastPersistedBytes,
                &$lastPersistedPercent,
                $mediaSourceService,
                $source
            ): void {
                $total = $downloadTotal > 0 ? $downloadTotal : $contentLength;
                $downloaded = max(0, $downloadedBytes);
                // If a retry or new attempt resets the counters, restart our local tracking
                if ($downloaded < $lastPersistedBytes) {
                    $lastPersistedBytes = 0;
                    $lastPersistedPercent = 0;
                }
                $percent = $total && $total > 0
                    ? (int) floor(($downloaded / $total) * 100)
                    : 0;
                $percent = max(0, min(99, $percent));
                // Never let the visible progress go backwards
                if ($percent < $lastPersistedPercent) {
                    $percent = $lastPersistedPercent;
                }

                $shouldPersist =
                    ($downloaded - $lastPersistedBytes) >= (1024 * 1024) ||
                    ($percent - $lastPersistedPercent) >= 2;

                if (! $shouldPersist) {
                    return;
                }

                $lastPersistedBytes = $downloaded;
                $lastPersistedPercent = $percent;
                $mediaSourceService->touchRemoteProgress(
                    $source->fresh(),
                    $downloaded,
                    $total,
                    'processing'
                );
            };

            try {
                $this->downloadRemoteFile(
                    $sourceUrl,
                    $absolutePath,
                    $downloadProgress,
                    $source
                );
            } catch (\Throwable $downloadError) {
                if (! $this->shouldUseProxyFallback($downloadError)) {
                    throw $downloadError;
                }

                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }

                $source->update([
                    'status' => 'proxying',
                    'failure_reason' => null,
                    'last_error' => $downloadError->getMessage(),
                    'last_attempt_host' => parse_url((string) $source->source_url, PHP_URL_HOST) ?: null,
                    'last_progress_at' => now(),
                ]);

                $this->requestExternalFallback($source, $filename, $contentType, $contentLength, $downloadError);
                $mediaSourceService->refreshAssetStatus($source->asset);

                return;
            }

            if (! is_file($absolutePath) || filesize($absolutePath) <= 0) {
                throw new \RuntimeException('Downloaded file is empty.');
            }

            $mimeType = @mime_content_type($absolutePath) ?: 'application/octet-stream';
            $size = (int) filesize($absolutePath);
            if ($contentLength !== null && $contentLength > 0 && $size > 0) {
                // Allow a tolerance for providers that slightly mis-report Content-Length,
                // especially on large files – only treat as truncated if we are clearly short.
                $toleranceBytes = max((int) ($contentLength * 0.02), 5 * 1024 * 1024); // 2% or 5MB
                if ($size + $toleranceBytes < $contentLength) {
                    if (is_file($absolutePath)) {
                        @unlink($absolutePath);
                    }

                    $source->update([
                        'status' => 'proxying',
                        'failure_reason' => null,
                        'last_error' => sprintf('remote_truncated: downloaded %d of %d bytes (expected %d)', $size, $contentLength, $contentLength),
                        'last_attempt_host' => parse_url((string) $source->source_url, PHP_URL_HOST) ?: null,
                        'last_progress_at' => now(),
                    ]);

                    $this->requestExternalFallback(
                        $source,
                        $filename,
                        $contentType,
                        $contentLength,
                        new \RuntimeException('remote_truncated')
                    );
                    $mediaSourceService->refreshAssetStatus($source->asset);

                    return;
                }
            }
            $preferredExtension = $this->extensionFromMimeType($mimeType);
            $currentExtension = strtolower((string) pathinfo($storagePath, PATHINFO_EXTENSION));
            $knownVideoExtensions = ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'mpeg', 'mpg', 'ts'];

            if (
                is_string($preferredExtension) &&
                $preferredExtension !== '' &&
                ! in_array($currentExtension, $knownVideoExtensions, true)
            ) {
                $newStoragePath = preg_replace('/\.[A-Za-z0-9]+$/', '', $storagePath) . '.' . $preferredExtension;
                if ($newStoragePath !== $storagePath) {
                    Storage::disk($workDisk)->move($storagePath, $newStoragePath);
                    $storagePath = $newStoragePath;
                    $absolutePath = Storage::disk($workDisk)->path($storagePath);
                }
            }

            app(NbxEngineService::class)->markNbxStatus($source->fresh() ?? $source, 'probing');
            $probe = app(VideoProbeService::class)->probe($absolutePath);
            $metadata = (array) (($source->fresh() ?? $source)->source_metadata ?? []);
            if ($probe !== []) {
                $metadata['probe'] = $probe;
            }

            $source->update([
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size_bytes' => $size > 0 ? $size : null,
                'duration_seconds' => isset($probe['duration_seconds']) ? (int) $probe['duration_seconds'] : null,
                'checksum' => hash_file('sha256', $absolutePath),
                'status' => 'ready',
                'failure_reason' => null,
                'last_error' => null,
                'progress_percent' => 100,
                'bytes_downloaded' => $size > 0 ? $size : null,
                'bytes_total' => $size > 0 ? $size : $contentLength,
                'last_progress_at' => now(),
                'completed_at' => now(),
                'source_metadata' => $metadata,
            ]);

            $source = app(NbxEngineService::class)->publishAvailableArtifacts($source->fresh() ?? $source, ['original']);
            app(NbxEngineService::class)->markNbxStatus($source->fresh() ?? $source, 'faststarting');
            $mediaSourceService->queuePlaybackProcessing($source->fresh());
        } catch (\Throwable $throwable) {
            Log::error('CDN remote import failed', [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'job_id' => $this->jobId,
                'error' => $throwable->getMessage(),
            ]);

            if (is_string($absolutePath) && is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            // Only mark source as failed if it never reached ready (e.g. don't overwrite if only queuePlaybackProcessing threw)
            $source->refresh();
            if ($source->status !== 'ready') {
                $source->update([
                    'status' => 'failed',
                    'failure_reason' => $throwable->getMessage(),
                    'last_error' => $throwable->getMessage(),
                    'last_attempt_host' => parse_url((string) $source->source_url, PHP_URL_HOST) ?: null,
                    'completed_at' => now(),
                ]);
                app(NbxEngineService::class)->markNbxStatus($source->fresh() ?? $source, 'failed', $throwable->getMessage());
            }
        } finally {
            $mediaSourceService->refreshAssetStatus($source->asset);
        }
    }

    private function resolveRemoteFilename(string $sourceUrl, int $sourceId, ?string $contentType = null): string
    {
        $videoExtensions = ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'mpeg', 'mpg', 'ts'];
        $fallbackExtension = $this->extensionFromMimeType($contentType) ?? 'mp4';
        $fallbackName = $this->applyBrandingToFilename(sprintf('source-%d.%s', $sourceId, $fallbackExtension));

        $urlPath = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $pathCandidate = $this->sanitizeFilenameCandidate(basename($urlPath));
        if (
            is_string($pathCandidate) &&
            in_array(strtolower((string) pathinfo($pathCandidate, PATHINFO_EXTENSION)), $videoExtensions, true)
        ) {
            return $this->applyBrandingToFilename($pathCandidate);
        }

        $query = (string) parse_url($sourceUrl, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $queryParams);
            foreach (['file', 'filename', 'name', 'title', 'download', 'url', 'path'] as $key) {
                $candidateValue = $queryParams[$key] ?? null;
                if (! is_string($candidateValue) || trim($candidateValue) === '') {
                    continue;
                }

                $queryCandidate = $this->sanitizeFilenameCandidate(basename($candidateValue));
                if (
                    is_string($queryCandidate) &&
                    in_array(strtolower((string) pathinfo($queryCandidate, PATHINFO_EXTENSION)), $videoExtensions, true)
                ) {
                    return $this->applyBrandingToFilename($queryCandidate);
                }
            }
        }

        if (is_string($pathCandidate) && $pathCandidate !== '') {
            $base = pathinfo($pathCandidate, PATHINFO_FILENAME) ?: sprintf('source-%d', $sourceId);
            return $this->applyBrandingToFilename($base . '.' . $fallbackExtension);
        }

        return $fallbackName;
    }

    private function sanitizeFilenameCandidate(?string $filename): ?string
    {
        if (! is_string($filename) || trim($filename) === '') {
            return null;
        }

        $decoded = urldecode($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $decoded) ?: '';
        $clean = ltrim($clean, '.');

        return $clean !== '' ? $clean : null;
    }

    private function applyBrandingToFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $suffix = 'naraboxtv_com';

        $normalizedBase = preg_replace('/(?:[_.-]+)?mobifliks(?:[_.-]*com)?$/i', '', $base) ?: $base;
        $normalizedBase = preg_replace('/(?:[_.-]+)?naraboxtv(?:[_.-]*com)?$/i', '', $normalizedBase) ?: $normalizedBase;
        $normalizedBase = rtrim($normalizedBase, '_-');
        if ($normalizedBase === '') {
            $normalizedBase = 'source';
        }

        return $extension !== ''
            ? ($normalizedBase . '_' . $suffix . '.' . $extension)
            : ($normalizedBase . '_' . $suffix);
    }

    private function extensionFromMimeType(?string $mimeType): ?string
    {
        if (! is_string($mimeType) || trim($mimeType) === '') {
            return null;
        }

        $normalized = trim(strtolower(explode(';', $mimeType)[0]));

        return match ($normalized) {
            'video/mp4' => 'mp4',
            'video/x-m4v' => 'm4v',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/mpeg' => 'mpeg',
            'video/mp2t' => 'ts',
            default => null,
        };
    }

    private function shouldUseProxyFallback(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'curl error 7')
            || str_contains($message, 'curl error 18')
            || str_contains($message, 'transfer closed')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'failed writing body')
            || str_contains($message, 'unexpected eof')
            || str_contains($message, "couldn't connect to server")
            || str_contains($message, 'failed to connect');
    }

    private function downloadRemoteFile(string $url, string $absolutePath, callable $progress, MediaSource $source): void
    {
        SafeRemoteMediaUrl::assertAllowed($url);

        $attempts = [
            [
                'label' => 'default-network',
                'options' => [],
            ],
            [
                'label' => 'force-ipv4',
                'options' => ['force_ip_resolve' => 'v4'],
            ],
            [
                'label' => 'force-ipv6',
                'options' => ['force_ip_resolve' => 'v6'],
            ],
        ];

        $lastError = null;

        foreach ($attempts as $attempt) {
            $curlConnectTime = null;
            try {
                Http::connectTimeout(60)
                    ->timeout(7200)
                    ->retry(2, 1200)
                    ->withHeaders([
                        'User-Agent' => 'NaraboxCDNImporter/1.0',
                        'Accept' => '*/*',
                    ])
                    ->withOptions(array_merge([
                        'sink' => $absolutePath,
                        'progress' => $progress,
                        'allow_redirects' => $this->safeRedirectOptions()['allow_redirects'],
                        'on_stats' => function ($stats) use (&$curlConnectTime): void {
                            if (method_exists($stats, 'getHandlerStats')) {
                                $handlerStats = (array) $stats->getHandlerStats();
                                $curlConnectTime = isset($handlerStats['connect_time']) ? (float) $handlerStats['connect_time'] : null;
                            }
                        },
                    ], $attempt['options']))
                    ->get($url)
                    ->throw();

                return;
            } catch (\Throwable $error) {
                $lastError = $error;

                Log::warning('Remote download attempt failed', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'attempt' => $attempt['label'],
                    'host' => parse_url($url, PHP_URL_HOST) ?: null,
                    'curl_errno' => $this->extractCurlErrno($error),
                    'connect_time' => $curlConnectTime,
                    'error' => $error->getMessage(),
                ]);

                if (! $this->shouldUseProxyFallback($error)) {
                    throw $error;
                }
            }
        }

        if ($lastError) {
            throw $lastError;
        }
    }

    private function safeRedirectOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => max(0, (int) config('nbx.ssrf.max_redirects', 5)),
                'strict' => true,
                'on_redirect' => static function ($request, $response, $uri): void {
                    SafeRemoteMediaUrl::assertAllowed((string) $uri);
                },
            ],
        ];
    }

    private function requestExternalFallback(
        MediaSource $source,
        string $filename,
        ?string $contentType,
        ?int $contentLength,
        \Throwable $triggerError
    ): void {
        $workerAttempted = false;
        $workerError = null;

        try {
            $this->requestPythonWorkerFallback($source, $filename, $contentType, $contentLength);
            return;
        } catch (\Throwable $workerThrowable) {
            $workerAttempted = true;
            $workerError = $workerThrowable->getMessage();
            Log::warning('Python worker fallback request failed, trying portal proxy', [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'error' => $workerError,
            ]);
        }

        try {
            $this->requestPortalProxy($source, $filename, $contentType, $contentLength);
            return;
        } catch (\Throwable $portalThrowable) {
            $combined = $portalThrowable->getMessage();
            if ($workerAttempted && is_string($workerError) && $workerError !== '') {
                $combined .= ' | python_worker_error=' . $workerError;
            }
            throw new \RuntimeException($combined, 0, $triggerError);
        }
    }

    private function requestPythonWorkerFallback(MediaSource $source, string $filename, ?string $contentType, ?int $contentLength): void
    {
        $workerUrl = trim((string) config('cdn.python_worker_queue_url', ''));
        $workerToken = trim((string) config('cdn.python_worker_auth_token', ''));
        $enabled = (bool) config('cdn.python_worker_enabled', false);

        if (! $enabled || $workerUrl === '' || $workerToken === '') {
            throw new \RuntimeException('Python worker fallback is not configured on CDN.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(20)
            ->timeout(60)
            ->withToken($workerToken)
            ->post($workerUrl, [
                'source_id' => $source->id,
                'asset_id' => (string) $source->media_asset_id,
                'source_url' => $source->source_url,
                'filename' => $filename,
                'mime_type' => $contentType,
                'size_bytes' => $contentLength,
            ]);

        if (! $response->successful()) {
            $body = $response->json();
            $error = is_array($body) ? ($body['error'] ?? null) : null;
            throw new \RuntimeException((string) ($error ?: ('Python worker request failed with status ' . $response->status())));
        }
    }

    private function requestPortalProxy(MediaSource $source, string $filename, ?string $contentType, ?int $contentLength): void
    {
        $proxyUrl = trim((string) config('cdn.portal_fetch_proxy_url', ''));
        $proxyToken = trim((string) config('cdn.portal_fetch_proxy_token', ''));

        if ($proxyUrl === '' || $proxyToken === '') {
            throw new \RuntimeException('Portal proxy endpoint is not configured on CDN.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(20)
            ->timeout(60)
            ->withToken($proxyToken)
            ->post($proxyUrl, [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'url' => $source->source_url,
                'filename' => $filename,
                'mime_type' => $contentType,
                'size_bytes' => $contentLength,
            ]);

        if (! $response->successful()) {
            $body = $response->json();
            $error = is_array($body) ? ($body['error'] ?? null) : null;
            throw new \RuntimeException((string) ($error ?: ('Portal proxy request failed with status ' . $response->status())));
        }
    }

    private function extractCurlErrno(\Throwable $throwable): ?int
    {
        $message = $throwable->getMessage();

        if (preg_match('/cURL error\s+(\d+)/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}

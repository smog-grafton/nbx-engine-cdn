<?php

namespace App\Jobs;

use App\Models\MediaSource;
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
use ZipArchive;

class FetchWorkerHlsArtifactJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public int $sourceId)
    {
        $this->onQueue((string) config('cdn.hls_artifacts_queue', config('cdn.optimization_queue', 'optimization')));
    }

    public function uniqueId(): string
    {
        return 'worker-artifact-fetch:' . $this->sourceId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('worker-artifact-fetch:' . $this->sourceId))
                ->expireAfter(max(300, (int) config('cdn.optimization_overlap_lock_seconds', 14400)))
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source) {
            return;
        }

        if ($source->hls_worker_status !== 'artifact_ready' || ! $source->hls_worker_artifact_url) {
            Log::info('FetchWorkerHlsArtifactJob: source not ready or no artifact URL', [
                'source_id' => $source->id,
                'status' => $source->hls_worker_status,
            ]);
            return;
        }

        Log::info('FetchWorkerHlsArtifactJob: starting', [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'artifact_url' => $source->hls_worker_artifact_url,
        ]);

        $tempDisk = (string) config('cdn.worker_artifacts_temp_disk', 'local');
        $tempPath = (string) config('cdn.worker_artifacts_temp_path', 'worker-artifacts');
        $prefix = $tempPath . '/' . $source->media_asset_id . '-' . $source->id . '-' . $this->job?->getJobId();
        $zipRelative = $prefix . '.zip';
        $extractRelative = $prefix . '-extracted';

        $source->update(['hls_worker_status' => 'fetching']);
        $token = (string) config('cdn.laravel_worker_api_token', '');
        $timeout = (int) config('cdn.laravel_worker_artifact_fetch_timeout', 600);
        $connectTimeout = (int) config('cdn.laravel_worker_artifact_connect_timeout', 60);
        $retries = (int) config('cdn.laravel_worker_artifact_retry_times', 3);
        $retrySleepMs = (int) config('cdn.laravel_worker_artifact_retry_sleep_ms', 2000);

        try {
            $response = Http::withToken($token)
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retries, $retrySleepMs)
                ->get($source->hls_worker_artifact_url);

            if (! $response->successful()) {
                $err = 'Download failed: HTTP ' . $response->status();
                Log::warning('FetchWorkerHlsArtifactJob: download failed', ['source_id' => $source->id, 'error' => $err]);
                $this->markFailed($source, $err);
                return;
            }

            $zipBytes = $response->body();
            if ($zipBytes === '') {
                $err = 'Download returned empty body';
                Log::warning('FetchWorkerHlsArtifactJob: empty body', ['source_id' => $source->id]);
                $this->markFailed($source, $err);
                return;
            }

            Log::info('FetchWorkerHlsArtifactJob: zip downloaded', ['source_id' => $source->id, 'bytes' => strlen($zipBytes)]);

            Storage::disk($tempDisk)->put($zipRelative, $zipBytes);
            $zipFullPath = Storage::disk($tempDisk)->path($zipRelative);
            if (! is_file($zipFullPath)) {
                $err = 'Failed to write temp zip to disk ' . $tempDisk;
                Log::warning('FetchWorkerHlsArtifactJob: write failed', ['source_id' => $source->id, 'path' => $zipFullPath]);
                $this->markFailed($source, $err);
                return;
            }

            $extractFullPath = Storage::disk($tempDisk)->path($extractRelative);
            if (! is_dir($extractFullPath)) {
                @mkdir($extractFullPath, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipFullPath) !== true) {
                $this->markFailed($source, 'Invalid ZIP file');
                $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                return;
            }

            $safeExtractPath = realpath($extractFullPath) ?: $extractFullPath;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) {
                    continue;
                }
                $entry = str_replace('\\', '/', $entry);
                if (str_contains($entry, '..')) {
                    $zip->close();
                    $this->markFailed($source, 'ZIP contains invalid path');
                    $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                    return;
                }
            }
            $zip->extractTo($safeExtractPath);
            $zip->close();

            $masterPath = $safeExtractPath . '/master.m3u8';
            if (! is_file($masterPath) || filesize($masterPath) === 0) {
                $this->markFailed($source, 'ZIP missing or empty master.m3u8 at root');
                $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                return;
            }

            $masterContent = @file_get_contents($masterPath);
            if ($masterContent === false) {
                $this->markFailed($source, 'Could not read master.m3u8');
                $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                return;
            }

            $variantPaths = $this->parseMasterPlaylistVariants($masterContent);
            foreach ($variantPaths as $relPath) {
                $absPath = $safeExtractPath . '/' . $relPath;
                if (! is_file($absPath)) {
                    $this->markFailed($source, 'Variant playlist missing: ' . $relPath);
                    $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                    return;
                }
                $variantDir = dirname($absPath);
                $content = @file_get_contents($absPath);
                if ($content !== false && ! $this->variantPlaylistHasValidSegment($content, $variantDir)) {
                    $this->markFailed($source, 'Variant has no valid segment: ' . $relPath);
                    $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
                    return;
                }
            }

            $source->update(['hls_worker_status' => 'installing']);

            $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
            $baseDir = 'media/' . $source->media_asset_id . '/' . $source->id;
            $hlsDir = $baseDir . '/hls';
            $finalHlsPath = Storage::disk($disk)->path($hlsDir);

            if (is_dir($finalHlsPath)) {
                $this->deleteDirectory($finalHlsPath);
            }
            @mkdir($finalHlsPath, 0755, true);

            $this->moveDirectoryContents($safeExtractPath, $finalHlsPath);

            $hlsMasterPath = $hlsDir . '/master.m3u8';
            $qualitiesJson = is_array($source->qualities_json) ? $source->qualities_json : [];
            $finalWorkerStatus = ($source->hls_worker_quality_status === 'partial') ? 'partial' : 'completed';

            $source->update([
                'hls_master_path' => $hlsMasterPath,
                'qualities_json' => $qualitiesJson,
                'playback_type' => 'hls',
                'hls_worker_status' => $finalWorkerStatus,
                'hls_worker_last_error' => null,
                'optimize_status' => 'ready',
                'optimize_error' => null,
                'optimized_at' => $source->optimized_at ?? now(),
            ]);

            $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);

            $this->notifyWorkerAck($source);
            $this->notifyPortalSync($source, 'completed', true);
            if ($source->asset) {
                app(\App\Services\MediaSourceService::class)->refreshAssetStatus($source->asset);
            }

            Log::info('FetchWorkerHlsArtifactJob: completed', [
                'source_id' => $source->id,
                'hls_path' => $hlsDir,
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            Log::error('FetchWorkerHlsArtifactJob: exception', [
                'source_id' => $source->id,
                'message' => $message,
            ]);
            $this->markFailed($source, $this->sanitizeArtifactErrorMessage($message));
            $this->cleanupTemp($tempDisk, $zipRelative, $extractRelative);
        }
    }

    /**
     * Short error for DB/UI; avoid storing full HTML or long cURL messages.
     */
    private function sanitizeArtifactErrorMessage(string $message): string
    {
        if (str_contains($message, 'status code 500') || str_contains($message, 'status code 5')) {
            return 'Download failed: HTTP 500 (worker server error).';
        }
        if (str_contains($message, 'status code 4')) {
            if (preg_match('/status code (\d+)/', $message, $m)) {
                return 'Download failed: HTTP ' . $m[1] . '.';
            }
        }
        if (str_contains($message, 'Connection timeout') || str_contains($message, 'cURL error 28')) {
            return 'Connection timeout when fetching artifact. Increase CDN_WORKER_ARTIFACT_CONNECT_TIMEOUT or check worker reachability.';
        }
        if (str_contains($message, 'Operation timed out') || str_contains($message, 'timed out')) {
            return 'Artifact download timed out. Increase CDN_WORKER_ARTIFACT_FETCH_TIMEOUT for large ZIPs.';
        }
        if (strlen($message) > 200) {
            return substr($message, 0, 197) . '...';
        }
        return $message;
    }

    private function markFailed(MediaSource $source, string $error): void
    {
        $source->update([
            'hls_worker_status' => 'failed',
            'hls_worker_last_error' => $error,
            'optimize_status' => 'failed',
            'optimize_error' => $error,
            // Track retries so the scheduler can cap infinite loops.
            'optimize_retry_count' => ($source->optimize_retry_count ?? 0) + 1,
        ]);

        Log::warning('FetchWorkerHlsArtifactJob: marked failed', [
            'source_id'     => $source->id,
            'asset_id'      => $source->media_asset_id,
            'error'         => $error,
            'retry_count'   => ($source->optimize_retry_count ?? 0),
            'is_faststart'  => $source->is_faststart,
            'storage_path'  => $source->storage_path,
            'optimized_path' => $source->optimized_path,
            'original_storage_path' => $source->original_storage_path,
        ]);

        $this->notifyPortalSync($source, 'failed', false);
    }

    private function parseMasterPlaylistVariants(string $content): array
    {
        $paths = [];
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $expectPath = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                $expectPath = true;
                continue;
            }
            if ($expectPath && $line !== '' && ! str_starts_with($line, '#')) {
                $paths[] = $line;
                $expectPath = false;
            }
        }
        return $paths;
    }

    private function variantPlaylistHasValidSegment(string $content, string $variantDir): bool
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $i = 0;
        while ($i < count($lines)) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, '#EXTINF')) {
                $i++;
                while ($i < count($lines) && (trim($lines[$i]) === '' || str_starts_with(trim($lines[$i]), '#'))) {
                    $i++;
                }
                if ($i < count($lines)) {
                    $seg = trim($lines[$i]);
                    if ($seg !== '' && is_file($variantDir . '/' . $seg)) {
                        return true;
                    }
                }
            }
            $i++;
        }
        return false;
    }

    private function moveDirectoryContents(string $fromDir, string $toDir): void
    {
        $entries = @scandir($fromDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $fromDir . '/' . $entry;
            $dst = $toDir . '/' . $entry;
            if (is_dir($src)) {
                @mkdir($dst, 0755, true);
                $this->moveDirectoryContents($src, $dst);
                @rmdir($src);
            } else {
                @rename($src, $dst);
            }
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    private function cleanupTemp(string $disk, string $zipRelative, string $extractRelative): void
    {
        if (Storage::disk($disk)->exists($zipRelative)) {
            Storage::disk($disk)->delete($zipRelative);
        }
        $extractFull = Storage::disk($disk)->path($extractRelative);
        if (is_dir($extractFull)) {
            $this->deleteDirectory($extractFull);
        }
    }

    private function notifyWorkerAck(MediaSource $source): void
    {
        $baseUrl = config('cdn.laravel_worker_api_url', '');
        $token = config('cdn.laravel_worker_api_token', '');
        $externalId = $source->hls_worker_external_id;
        if ($baseUrl === '' || $token === '' || ! $externalId) {
            return;
        }

        $url = rtrim($baseUrl, '/') . '/api/v1/artifacts/' . $externalId . '/ack';
        try {
            Http::withToken($token)->timeout(15)->post($url, [
                'cdn_asset_id' => (string) $source->media_asset_id,
                'cdn_source_id' => $source->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FetchWorkerHlsArtifactJob: worker ack failed', ['url' => $url, 'message' => $e->getMessage()]);
        }
    }

    private function notifyPortalSync(MediaSource $source, string $status, bool $playbackReady): void
    {
        $url = trim((string) config('cdn.portal_worker_sync_url', ''));
        $token = trim((string) config('cdn.portal_worker_api_token', ''));

        if ($url === '' || $token === '') {
            return;
        }

        try {
            Http::acceptJson()
                ->withToken($token)
                ->timeout(15)
                ->post($url, [
                    'hint' => 'cdn_hls_artifact',
                    'cdn_asset_id' => (string) $source->media_asset_id,
                    'cdn_source_id' => $source->id,
                    'status' => $status,
                    'playback_ready' => $playbackReady,
                ]);
        } catch (\Throwable $e) {
            Log::warning('FetchWorkerHlsArtifactJob: portal sync failed', [
                'url' => $url,
                'source_id' => $source->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

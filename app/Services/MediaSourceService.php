<?php

namespace App\Services;

use App\Jobs\ImportRemoteMediaSourceJob;
use App\Jobs\GenerateHlsVariantsJob;
use App\Jobs\OptimizeMp4FaststartJob;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaSourceService
{
    public function storageDisk(): string
    {
        return (string) config('cdn.disk', 'public');
    }

    public function buildPublicUrl(MediaSource $source): ?string
    {
        if ($source->status !== 'ready' || ! $source->is_active) {
            return null;
        }

        if ($this->shouldUseNbxFinalArtifacts($source)) {
            return $this->nbxArtifactUrl($source, 'original')
                ?: $this->nbxArtifactUrl($source, 'faststart');
        }

        if ($source->source_type === 'url') {
            return MediaUrl::normalize($source->source_url) ?? $source->source_url;
        }

        if ($source->source_type === 'embed') {
            return $source->source_url;
        }

        if (! $source->storage_path) {
            return null;
        }

        $disk = $source->storage_disk ?: $this->storageDisk();

        if ($url = $this->buildDirectStorageUrl($disk, (string) $source->storage_path)) {
            return $url;
        }

        $filename = basename($source->storage_path);

        return $this->absoluteRoute('media.stream.source', [
            'asset' => $source->media_asset_id,
            'source' => $source->id,
            'filename' => $filename,
        ]);
    }

    public function buildMp4PlayUrl(MediaSource $source): ?string
    {
        if ($source->status !== 'ready' || ! $source->is_active || ! $source->storage_path) {
            return null;
        }

        if ($this->shouldUseNbxFinalArtifacts($source)) {
            return $this->nbxArtifactUrl($source, 'faststart')
                ?: $this->nbxArtifactUrl($source, 'original');
        }

        $disk = $source->storage_disk ?: $this->storageDisk();
        $preferredPath = $source->optimized_path && Storage::disk($disk)->exists($source->optimized_path)
            ? $source->optimized_path
            : $source->storage_path;

        if ($url = $this->buildDirectStorageUrl($disk, (string) $preferredPath)) {
            return $url;
        }

        return $this->absoluteRoute('media.stream.source', [
            'asset' => $source->media_asset_id,
            'source' => $source->id,
            'filename' => basename($preferredPath),
        ]);
    }

    public function buildDownloadUrl(MediaSource $source): ?string
    {
        if ($source->status !== 'ready' || ! $source->is_active || ! $source->storage_path) {
            return null;
        }

        if ($this->shouldUseNbxFinalArtifacts($source)) {
            return $this->mp4OnlyUrl($this->nbxArtifactUrl($source, 'faststart'))
                ?: $this->mp4OnlyUrl($this->nbxArtifactUrl($source, 'original'));
        }

        $disk = $source->storage_disk ?: $this->storageDisk();

        if ($url = $this->buildDirectStorageUrl($disk, (string) $source->storage_path)) {
            return $url;
        }

        return $this->absoluteRoute('media.stream.source', [
            'asset' => $source->media_asset_id,
            'source' => $source->id,
            'filename' => basename($source->storage_path),
            'download' => 1,
        ]);
    }

    public function buildHlsMasterUrl(MediaSource $source): ?string
    {
        if ($source->status !== 'ready' || ! $source->is_active || ! $source->hls_master_path) {
            return null;
        }

        if ($this->shouldUseNbxFinalArtifacts($source)) {
            return $this->nbxArtifactUrl($source, 'hls_master');
        }

        $disk = $source->storage_disk ?: $this->storageDisk();

        if ($url = $this->buildDirectStorageUrl($disk, (string) $source->hls_master_path)) {
            return $url;
        }

        $hlsBase = dirname((string) $source->hls_master_path);
        $relative = ltrim(str_replace($hlsBase, '', (string) $source->hls_master_path), '/');

        return $this->absoluteRoute('media.hls.source', [
            'asset' => $source->media_asset_id,
            'source' => $source->id,
            'path' => $relative,
        ]);
    }

    public function buildHlsVariantUrl(MediaSource $source, string $variantPath): ?string
    {
        if ($source->status !== 'ready' || ! $source->is_active || ! $source->hls_master_path) {
            return null;
        }

        if ($this->shouldUseNbxFinalArtifacts($source)) {
            return $this->nbxHlsVariantUrl($source, $variantPath);
        }

        $disk = $source->storage_disk ?: $this->storageDisk();

        if ($url = $this->buildDirectStorageUrl($disk, $variantPath)) {
            return $url;
        }

        $hlsBase = dirname((string) $source->hls_master_path);
        $relative = ltrim(str_replace($hlsBase . '/', '', $variantPath), '/');

        return $this->absoluteRoute('media.hls.source', [
            'asset' => $source->media_asset_id,
            'source' => $source->id,
            'path' => $relative,
        ]);
    }

    public function buildPlaybackManifest(MediaSource $source): array
    {
        $disk = $source->storage_disk ?: $this->storageDisk();
        $usesFinalArtifacts = $this->shouldUseNbxFinalArtifacts($source);
        $hlsReady = $source->hls_master_path && (
            ($usesFinalArtifacts && $this->nbxArtifactUrl($source, 'hls_master') !== null)
            || Storage::disk($disk)->exists((string) $source->hls_master_path)
        );
        $playbackType = $source->playback_type === 'hls' && $hlsReady ? 'hls' : 'mp4';
        $qualities = [];
        $qualityRows = is_array($source->qualities_json) ? $source->qualities_json : [];
        if ($usesFinalArtifacts) {
            $metadata = (array) ($source->source_metadata ?? []);
            $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
            $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
            if (is_array($artifacts['qualities'] ?? null) && $artifacts['qualities'] !== []) {
                $qualityRows = $artifacts['qualities'];
            }
        }
        if ($qualityRows === [] && $hlsReady) {
            $qualityRows = $this->parseHlsQualities($source);
        }
        if (is_array($qualityRows)) {
            foreach ($qualityRows as $quality) {
                if (! is_array($quality)) {
                    continue;
                }

                $path = isset($quality['path']) && is_string($quality['path']) ? $quality['path'] : null;
                $qualities[] = [
                    'id' => (string) ($quality['id'] ?? 'unknown'),
                    'label' => (string) ($quality['label'] ?? 'Unknown'),
                    'type' => 'hls',
                    'bandwidth' => isset($quality['bandwidth']) ? (int) $quality['bandwidth'] : null,
                    'width' => isset($quality['width']) ? (int) $quality['width'] : null,
                    'height' => isset($quality['height']) ? (int) $quality['height'] : null,
                    'url' => $path ? $this->buildHlsVariantUrl($source, $path) : null,
                ];
            }
        }

        if ($playbackType === 'hls') {
            array_unshift($qualities, [
                'id' => 'auto',
                'label' => 'Auto',
                'type' => 'hls',
                'bandwidth' => null,
                'width' => null,
                'height' => null,
                'url' => $this->buildHlsMasterUrl($source),
            ]);
        }

        $mp4PlayUrl = $this->buildMp4PlayUrl($source);

        return [
            'type' => $playbackType,
            'hls_master_url' => $this->buildHlsMasterUrl($source),
            'mp4_play_url' => $mp4PlayUrl,
            'mp4_url' => $mp4PlayUrl, // backward compatibility for older portal/frontend clients
            'download_url' => $this->buildDownloadUrl($source),
            'qualities' => $qualities,
        ];
    }

    /**
     * @return array<int, array{id:string,label:string,bandwidth:int,width:int,height:int,path:string}>
     */
    private function parseHlsQualities(MediaSource $source): array
    {
        if (! $source->hls_master_path) {
            return [];
        }

        $disk = $source->storage_disk ?: $this->storageDisk();
        if (! Storage::disk($disk)->exists((string) $source->hls_master_path)) {
            return [];
        }

        $masterText = (string) Storage::disk($disk)->get((string) $source->hls_master_path);
        $lines = preg_split("/\r\n|\n|\r/", $masterText) ?: [];
        $rows = [];
        $baseDir = dirname((string) $source->hls_master_path);

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if (! str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                continue;
            }

            $attrs = str_replace('#EXT-X-STREAM-INF:', '', $line);
            $nextLine = trim((string) ($lines[$i + 1] ?? ''));
            if ($nextLine === '' || str_starts_with($nextLine, '#')) {
                continue;
            }

            $bandwidth = null;
            $width = null;
            $height = null;

            if (preg_match('/BANDWIDTH=(\d+)/', $attrs, $m) === 1) {
                $bandwidth = (int) $m[1];
            }
            if (preg_match('/RESOLUTION=(\d+)x(\d+)/', $attrs, $m) === 1) {
                $width = (int) $m[1];
                $height = (int) $m[2];
            }

            $variantPath = $baseDir . '/' . ltrim($nextLine, '/');
            $label = $height ? ($height . 'p') : ('Q' . (count($rows) + 1));
            $rows[] = [
                'id' => $label,
                'label' => strtoupper($label),
                'bandwidth' => $bandwidth ?? 0,
                'width' => $width ?? 0,
                'height' => $height ?? 0,
                'path' => $variantPath,
            ];
        }

        return $rows;
    }

    public function storeUploadedSource(MediaAsset $asset, UploadedFile $file): MediaSource
    {
        $source = MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => $this->storageDisk(),
            'status' => 'processing',
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
            'completed_at' => null,
            'is_active' => true,
        ]);

        $filename = $this->sanitizeFileName($file->getClientOriginalName());
        $storagePath = sprintf('media/%s/%d/%s', $asset->id, $source->id, $filename);

        Storage::disk($this->storageDisk())->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath)
        );

        $absolutePath = Storage::disk($this->storageDisk())->path($storagePath);
        $mimeType = @mime_content_type($absolutePath) ?: $file->getClientMimeType() ?: 'application/octet-stream';
        $size = (int) (Storage::disk($this->storageDisk())->size($storagePath) ?: 0);

        $source->update([
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'file_size_bytes' => $size > 0 ? $size : null,
            'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'status' => 'ready',
            'failure_reason' => null,
            'progress_percent' => 100,
            'bytes_downloaded' => $size > 0 ? $size : null,
            'bytes_total' => $size > 0 ? $size : null,
            'last_progress_at' => now(),
            'completed_at' => now(),
        ]);

        $this->queuePlaybackProcessing($source);
        $this->refreshAssetStatus($asset);

        return $source->fresh();
    }

    /**
     * Store a raw request body stream directly into CDN storage.
     *
     * @param resource $inputStream
     */
    public function storeStreamedSource(
        MediaAsset $asset,
        mixed $inputStream,
        string $originalFilename,
        ?int $bytesTotal = null,
        ?string $mimeType = null,
        ?callable $progressCallback = null,
        ?MediaSource $existingSource = null,
    ): MediaSource {
        $source = $existingSource;

        if ($source) {
            $source->update([
                'source_type' => 'upload',
                'storage_disk' => $this->storageDisk(),
                'status' => 'processing',
                'failure_reason' => null,
                'last_error' => null,
                'progress_percent' => 0,
                'bytes_downloaded' => 0,
                'bytes_total' => $bytesTotal,
                'started_at' => now(),
                'last_progress_at' => now(),
                'completed_at' => null,
                'is_active' => true,
            ]);
            $source->refresh();
        } else {
            $source = MediaSource::create([
                'media_asset_id' => $asset->id,
                'source_type' => 'upload',
                'storage_disk' => $this->storageDisk(),
                'status' => 'processing',
                'progress_percent' => 0,
                'bytes_downloaded' => 0,
                'bytes_total' => $bytesTotal,
                'started_at' => now(),
                'last_progress_at' => now(),
                'completed_at' => null,
                'is_active' => true,
            ]);
        }

        $filename = $this->sanitizeFileName($originalFilename);
        $storagePath = sprintf('media/%s/%d/%s', $asset->id, $source->id, $filename);
        $disk = Storage::disk($this->storageDisk());
        $disk->makeDirectory(dirname($storagePath));

        $absolutePath = $disk->path($storagePath);
        $output = fopen($absolutePath, 'wb');
        if (! is_resource($inputStream) || ! is_resource($output)) {
            if (is_resource($output)) {
                fclose($output);
            }
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Could not open stream for CDN storage.',
                'completed_at' => now(),
            ]);
            $this->refreshAssetStatus($asset);
            return $source->fresh();
        }

        $bytesWritten = 0;
        $hash = hash_init('sha256');
        $lastProgressSaveAt = microtime(true);

        try {
            while (! feof($inputStream)) {
                $chunk = fread($inputStream, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed while reading streamed upload.');
                }
                if ($chunk === '') {
                    continue;
                }

                $written = fwrite($output, $chunk);
                if ($written === false) {
                    throw new \RuntimeException('Failed while writing streamed upload to CDN disk.');
                }

                $bytesWritten += $written;
                hash_update($hash, substr($chunk, 0, $written));

                $progress = $bytesTotal && $bytesTotal > 0
                    ? min(99, (int) floor(($bytesWritten / $bytesTotal) * 100))
                    : null;

                if ($progressCallback) {
                    $progressCallback($bytesWritten, $bytesTotal, $progress);
                }

                if ((microtime(true) - $lastProgressSaveAt) >= 1.5) {
                    $source->update([
                        'progress_percent' => $progress,
                        'bytes_downloaded' => $bytesWritten,
                        'last_progress_at' => now(),
                    ]);
                    $lastProgressSaveAt = microtime(true);
                }
            }
        } catch (\Throwable $exception) {
            fclose($output);
            @unlink($absolutePath);
            $source->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'last_error' => $exception->getMessage(),
                'progress_percent' => null,
                'bytes_downloaded' => $bytesWritten,
                'last_progress_at' => now(),
                'completed_at' => now(),
            ]);
            $this->refreshAssetStatus($asset);
            return $source->fresh();
        }

        fclose($output);

        $detectedMimeType = $mimeType ?: (@mime_content_type($absolutePath) ?: 'application/octet-stream');
        $size = (int) ($disk->size($storagePath) ?: $bytesWritten);

        $source->update([
            'storage_path' => $storagePath,
            'mime_type' => $detectedMimeType,
            'file_size_bytes' => $size > 0 ? $size : null,
            'checksum' => hash_final($hash),
            'status' => 'ready',
            'failure_reason' => null,
            'progress_percent' => 100,
            'bytes_downloaded' => $size > 0 ? $size : $bytesWritten,
            'bytes_total' => $bytesTotal ?: ($size > 0 ? $size : null),
            'last_progress_at' => now(),
            'completed_at' => now(),
        ]);

        $this->queuePlaybackProcessing($source);
        $this->refreshAssetStatus($asset);

        return $source->fresh();
    }

    public function attachStoredUpload(MediaAsset $asset, string $temporaryPath): MediaSource
    {
        $source = MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => $this->storageDisk(),
            'status' => 'processing',
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
            'completed_at' => null,
            'is_active' => true,
        ]);

        $filename = $this->sanitizeFileName(basename($temporaryPath));
        $finalPath = sprintf('media/%s/%d/%s', $asset->id, $source->id, $filename);

        if (Storage::disk($this->storageDisk())->exists($temporaryPath)) {
            Storage::disk($this->storageDisk())->makeDirectory(dirname($finalPath));
            Storage::disk($this->storageDisk())->move($temporaryPath, $finalPath);
        } else {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Uploaded file was not found in temporary storage.',
                'progress_percent' => 0,
                'completed_at' => now(),
            ]);
            $this->refreshAssetStatus($asset);
            return $source->fresh();
        }

        $absolutePath = Storage::disk($this->storageDisk())->path($finalPath);
        $mimeType = @mime_content_type($absolutePath) ?: 'application/octet-stream';
        $size = (int) (Storage::disk($this->storageDisk())->size($finalPath) ?: 0);

        $source->update([
            'storage_path' => $finalPath,
            'mime_type' => $mimeType,
            'file_size_bytes' => $size > 0 ? $size : null,
            'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'status' => 'ready',
            'failure_reason' => null,
            'progress_percent' => 100,
            'bytes_downloaded' => $size > 0 ? $size : null,
            'bytes_total' => $size > 0 ? $size : null,
            'last_progress_at' => now(),
            'completed_at' => now(),
        ]);

        $this->queuePlaybackProcessing($source);
        $this->refreshAssetStatus($asset);

        return $source->fresh();
    }

    public function replaceStoredUpload(MediaSource $source, string $temporaryPath): MediaSource
    {
        if ($source->source_type !== 'upload') {
            return $source->fresh();
        }

        $source->update([
            'status' => 'processing',
            'failure_reason' => null,
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
            'completed_at' => null,
            'storage_disk' => $this->storageDisk(),
        ]);

        if (! Storage::disk($this->storageDisk())->exists($temporaryPath)) {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Replacement upload was not found in temporary storage.',
                'completed_at' => now(),
            ]);
            $this->refreshAssetStatus($source->asset);
            return $source->fresh();
        }

        $filename = $this->sanitizeFileName(basename($temporaryPath));
        $finalPath = sprintf('media/%s/%d/%s', $source->media_asset_id, $source->id, $filename);

        Storage::disk($this->storageDisk())->makeDirectory(dirname($finalPath));
        Storage::disk($this->storageDisk())->move($temporaryPath, $finalPath);

        $absolutePath = Storage::disk($this->storageDisk())->path($finalPath);
        $size = (int) (Storage::disk($this->storageDisk())->size($finalPath) ?: 0);

        $source->update([
            'storage_path' => $finalPath,
            'mime_type' => @mime_content_type($absolutePath) ?: 'application/octet-stream',
            'file_size_bytes' => $size > 0 ? $size : null,
            'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'status' => 'ready',
            'failure_reason' => null,
            'progress_percent' => 100,
            'bytes_downloaded' => $size > 0 ? $size : null,
            'bytes_total' => $size > 0 ? $size : null,
            'last_progress_at' => now(),
            'completed_at' => now(),
        ]);

        $this->queuePlaybackProcessing($source);
        $this->refreshAssetStatus($source->asset);

        return $source->fresh();
    }

    public function queueRemoteImport(MediaSource $source): void
    {
        $queued = $this->withLock('media-source:queue-remote-import:' . $source->id, function () use ($source): bool {
            $source = $source->fresh() ?? $source;

            if (
                in_array((string) $source->status, ['pending', 'downloading', 'processing', 'proxying', 'uploading'], true)
                && filled($source->external_job_id)
            ) {
                return false;
            }

            $jobId = (string) Str::uuid();

            $source->update([
                'status' => 'pending',
                'failure_reason' => null,
                'external_job_id' => $jobId,
                'storage_disk' => (string) config('nbx.work_storage', $this->storageDisk()),
                'progress_percent' => 0,
                'bytes_downloaded' => null,
                'bytes_total' => null,
                'started_at' => null,
                'last_progress_at' => now(),
                'completed_at' => null,
                'is_faststart' => false,
                'compress_enabled' => $source->compress_enabled ?? true,
                'optimize_status' => null,
                'optimized_path' => null,
                'optimize_error' => null,
                'optimized_at' => null,
                'playback_type' => null,
                'hls_master_path' => null,
                'qualities_json' => null,
            ]);

            ImportRemoteMediaSourceJob::dispatch($source->id, $jobId)
                ->onQueue((string) config('cdn.import_queue', 'default'));

            return true;
        });

        if ($queued) {
            $this->refreshAssetStatus($source->asset);
        }
    }

    public function importRemoteNow(MediaSource $source): void
    {
        $jobId = (string) Str::uuid();

        $source->update([
            'status' => 'pending',
            'failure_reason' => null,
            'external_job_id' => $jobId,
            'storage_disk' => (string) config('nbx.work_storage', $this->storageDisk()),
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => null,
            'last_progress_at' => now(),
            'completed_at' => null,
            'is_faststart' => false,
            'compress_enabled' => $source->compress_enabled ?? true,
            'optimize_status' => null,
            'optimized_path' => null,
            'optimize_error' => null,
            'optimized_at' => null,
            'playback_type' => null,
            'hls_master_path' => null,
            'qualities_json' => null,
        ]);

        ImportRemoteMediaSourceJob::dispatchSync($source->id, $jobId);
        $source->refresh();
        $this->refreshAssetStatus($source->asset);
    }

    public function queuePythonWorkerImport(MediaSource $source): void
    {
        $jobId = (string) Str::uuid();
        $workerEnabled = (bool) config('cdn.python_worker_enabled', false);
        $workerUrl = trim((string) config('cdn.python_worker_queue_url', ''));
        $workerToken = trim((string) config('cdn.python_worker_auth_token', ''));

        if (! $workerEnabled || $workerUrl === '' || $workerToken === '') {
            throw new \RuntimeException('CDN python worker fallback is not configured.');
        }

        if (! is_string($source->source_url) || trim($source->source_url) === '') {
            throw new \RuntimeException('Source URL is missing for python worker import.');
        }

        $source->update([
            'status' => 'proxying',
            'failure_reason' => null,
            'last_error' => null,
            'external_job_id' => $jobId,
            'storage_disk' => $this->storageDisk(),
            'progress_percent' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
            'completed_at' => null,
            'is_faststart' => false,
            'compress_enabled' => $source->compress_enabled ?? true,
            'optimize_status' => null,
            'optimized_path' => null,
            'optimize_error' => null,
            'optimized_at' => null,
            'playback_type' => null,
            'hls_master_path' => null,
            'qualities_json' => null,
        ]);

        $response = Http::acceptJson()
            ->connectTimeout(20)
            ->timeout(60)
            ->withToken($workerToken)
            ->post($workerUrl, [
                'source_id' => $source->id,
                'asset_id' => (string) $source->media_asset_id,
                'source_url' => (string) $source->source_url,
                'filename' => basename((string) parse_url((string) $source->source_url, PHP_URL_PATH)) ?: ('source-' . $source->id . '.mp4'),
                'mime_type' => $source->mime_type,
                'size_bytes' => $source->bytes_total,
            ]);

        if (! $response->successful()) {
            $body = $response->json();
            $error = is_array($body) ? ($body['error'] ?? null) : null;
            throw new \RuntimeException((string) ($error ?: ('Python worker enqueue failed with status ' . $response->status())));
        }

        $payload = $response->json();
        $jobIdFromWorker = is_array($payload) ? ($payload['data']['id'] ?? null) : null;
        if (is_numeric($jobIdFromWorker)) {
            $source->update([
                'external_job_id' => 'pyw:' . (int) $jobIdFromWorker,
                'last_progress_at' => now(),
            ]);
        }

        $this->refreshAssetStatus($source->asset);
    }

    public function touchRemoteProgress(MediaSource $source, int $downloaded, ?int $total = null, ?string $status = null): void
    {
        $safeDownloaded = max(0, $downloaded);
        $safeTotal = $total !== null ? max(0, $total) : null;
        $percent = $safeTotal && $safeTotal > 0
            ? (int) min(99, floor(($safeDownloaded / $safeTotal) * 100))
            : (int) min(99, floor($safeDownloaded / (1024 * 1024)));

        // Always work with a fresh copy so we don't overwrite newer progress or state.
        $fresh = $source->fresh();
        if (! $fresh) {
            return;
        }

        // Do not overwrite terminal states.
        if (in_array($fresh->status, ['ready', 'failed', 'proxying'], true)) {
            return;
        }

        // Never move progress backwards.
        $currentPercent = (int) ($fresh->progress_percent ?? 0);
        if ($percent <= $currentPercent) {
            return;
        }

        $fresh->forceFill([
            'status' => $status ?? $fresh->status,
            'progress_percent' => $percent,
            'bytes_downloaded' => $safeDownloaded > 0 ? $safeDownloaded : null,
            'bytes_total' => $safeTotal > 0 ? $safeTotal : null,
            'last_progress_at' => now(),
        ])->save();
    }

    public function refreshAssetStatus(MediaAsset $asset): void
    {
        $asset->loadMissing('sources');

        $hasFailed = $asset->sources->contains(fn (MediaSource $source) => $source->status === 'failed');
        $hasProcessing = $asset->sources->contains(fn (MediaSource $source) => in_array($source->status, ['pending', 'downloading', 'processing', 'proxying', 'uploading'], true));
        $hasReady = $asset->sources->contains(fn (MediaSource $source) => $source->status === 'ready' && $source->is_active);

        if ($hasProcessing) {
            $status = 'importing';
        } elseif ($hasReady) {
            $status = 'ready';
        } elseif ($hasFailed) {
            $status = 'failed';
        } else {
            $status = 'draft';
        }

        $asset->update(['status' => $status]);
    }

    public function queuePlaybackProcessing(MediaSource $source): bool
    {
        return $this->withLock('media-source:queue-playback:' . $source->id, function () use ($source): bool {
            $source = $source->fresh() ?? $source;

            if ($source->status !== 'ready') {
                Log::warning('queuePlaybackProcessing: source not ready for optimization', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'status' => $source->status,
                ]);

                $source->update([
                    'optimize_status' => 'failed',
                    'optimize_error' => 'Cannot optimize because source status is not ready (current status: ' . $source->status . ').',
                ]);

                return false;
            }

            if (in_array((string) $source->optimize_status, ['pending', 'processing'], true)) {
                Log::info('queuePlaybackProcessing: skipped duplicate optimization dispatch', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'optimize_status' => $source->optimize_status,
                ]);

                return false;
            }

            if (! $source->storage_path) {
                Log::warning('queuePlaybackProcessing: source has no storage_path', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                ]);

                $source->update([
                    'optimize_status' => 'failed',
                    'optimize_error' => 'Cannot optimize because original media path is missing.',
                ]);

                return false;
            }

            $disk = $source->storage_disk ?: $this->storageDisk();
            if (! Storage::disk($disk)->exists($source->storage_path)) {
                Log::warning('queuePlaybackProcessing: original media file missing on disk', [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'disk' => $disk,
                    'path' => $source->storage_path,
                ]);

                $source->update([
                    'optimize_status' => 'failed',
                    'optimize_error' => 'Original media file was not found on CDN disk; cannot optimize.',
                ]);

                return false;
            }

            $updates = [
                'is_faststart' => false,
                'compress_enabled' => $source->compress_enabled ?? true,
                'optimize_status' => 'pending',
                'optimized_path' => null,
                'optimize_error' => null,
                'optimized_at' => null,
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'hls_worker_status' => null,
                'hls_worker_artifact_url' => null,
                'hls_worker_artifact_expires_at' => null,
                'hls_worker_last_error' => null,
            ];

            if (! $source->original_storage_path && $source->storage_path) {
                $updates['original_storage_path'] = $source->storage_path;
            }

            $source->update($updates);

            $queue = (string) config('cdn.optimization_queue', 'optimization');
            $enableHls = (bool) config('cdn.enable_hls', true);

            if ($enableHls) {
                Bus::chain([
                    new OptimizeMp4FaststartJob($source->id),
                    new \App\Jobs\ProcessHlsAfterFaststartJob($source->id),
                ])->onQueue($queue)->dispatch();
            } else {
                OptimizeMp4FaststartJob::dispatch($source->id)->onQueue($queue);
            }

            return true;
        });
    }

    /**
     * Re-dispatch HLS processing ONLY (skip faststart/compression) for sources where
     * faststart already succeeded but the worker HLS step failed.
     * This prevents the retry scheduler from re-running compression on an already-
     * compressed file, which would delete the working _play.mp4.
     */
    public function retryWorkerHlsOnly(MediaSource $source): void
    {
        if ($source->status !== 'ready') {
            return;
        }

        $disk = $source->storage_disk ?: $this->storageDisk();
        // Use optimized_path if available, otherwise storage_path.
        $inputPath = $source->optimized_path ?: $source->storage_path;
        if (! $inputPath || ! Storage::disk($disk)->exists($inputPath)) {
            Log::warning('retryWorkerHlsOnly: input file missing, cannot retry HLS', [
                'source_id' => $source->id,
                'asset_id'  => $source->media_asset_id,
                'input_path' => $inputPath,
            ]);
            return;
        }

        Log::info('retryWorkerHlsOnly: re-dispatching HLS step only (skipping compression)', [
            'source_id'       => $source->id,
            'asset_id'        => $source->media_asset_id,
            'input_path'      => $inputPath,
            'is_faststart'    => $source->is_faststart,
            'optimize_status' => $source->optimize_status,
            'worker_status'   => $source->hls_worker_status,
        ]);

        $source->update([
            'optimize_status'              => 'pending',
            'optimize_error'               => null,
            'hls_master_path'              => null,
            'qualities_json'               => null,
            'hls_worker_status'            => null,
            'hls_worker_artifact_url'      => null,
            'hls_worker_artifact_expires_at' => null,
            'hls_worker_last_error'        => null,
            'optimize_retry_count'         => ($source->optimize_retry_count ?? 0) + 1,
        ]);

        \App\Jobs\ProcessHlsAfterFaststartJob::dispatch($source->id)
            ->onQueue((string) config('cdn.optimization_queue', 'optimization'));
    }

    /**
     * Send playback processing (faststart + HLS) to the Laravel worker. Returns true if sent, false to fall back to local queue.
     */
    public function queueLaravelWorkerPlaybackProcessing(MediaSource $source): bool
    {
        $enabled = (bool) config('cdn.laravel_worker_enabled', false);
        $baseUrl = config('cdn.laravel_worker_api_url', '');
        $token = config('cdn.laravel_worker_api_token', '');
        if (! $enabled || $baseUrl === '' || $token === '') {
            return false;
        }

        $sourceUrl = $this->buildMp4PlayUrl($source) ?: $this->buildDownloadUrl($source);
        if (! $sourceUrl) {
            return false;
        }

        $response = Http::acceptJson()
            ->connectTimeout(30)
            ->timeout(60)
            ->withToken($token)
            ->post($baseUrl . '/api/v1/processing/submit', [
                'cdn_asset_id' => (string) $source->media_asset_id,
                'cdn_source_id' => $source->id,
                'source_url' => $sourceUrl,
                'original_filename' => basename((string) $source->storage_path),
                'payload' => [
                    'storage_path' => $source->storage_path,
                    'storage_disk' => $source->storage_disk,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('CDN Laravel worker submit failed', [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return false;
        }

        Log::info('CDN sent playback processing to Laravel worker', [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'external_id' => $response->json('data.external_id'),
        ]);
        return true;
    }

    private function sanitizeFileName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'media.bin';

        return ltrim($clean, '.');
    }

    private function buildDirectStorageUrl(string $disk, string $path): ?string
    {
        if (! (bool) config('cdn.use_direct_storage_urls', true) || $path === '') {
            return null;
        }

        try {
            $url = Storage::disk($disk)->url($path);
            if (is_string($url) && str_starts_with($url, '/')) {
                $baseUrl = rtrim((string) config('app.url', config('cdn.app_url')), '/');
                return $baseUrl !== '' ? ($baseUrl . $url) : $url;
            }

            return $url;
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldUseNbxFinalArtifacts(MediaSource $source): bool
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];

        return ($metadata['provider'] ?? null) === 'nbx_engine'
            && (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo')) === 'contabo';
    }

    private function nbxArtifactUrl(MediaSource $source, string $role): ?string
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $artifact = is_array($artifacts[$role] ?? null) ? $artifacts[$role] : [];
        $url = $artifact['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    private function nbxHlsVariantUrl(MediaSource $source, string $variantPath): ?string
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $qualities = is_array($artifacts['qualities'] ?? null) ? $artifacts['qualities'] : [];

        foreach ($qualities as $quality) {
            if (! is_array($quality)) {
                continue;
            }

            if (($quality['source_path'] ?? null) === $variantPath || ($quality['path'] ?? null) === $variantPath) {
                $url = $quality['url'] ?? null;
                return is_string($url) && trim($url) !== '' ? trim($url) : null;
            }
        }

        return null;
    }

    private function mp4OnlyUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.m3u8') ? null : $url;
    }

    private function absoluteRoute(string $name, array $parameters): string
    {
        $relative = route($name, $parameters, false);
        $baseUrl = rtrim((string) config('app.url', config('cdn.app_url')), '/');

        return $baseUrl !== '' ? ($baseUrl . $relative) : route($name, $parameters);
    }

    private function withLock(string $key, callable $callback): mixed
    {
        try {
            return Cache::lock($key, 10)->get($callback);
        } catch (\Throwable) {
            return $callback();
        }
    }
}

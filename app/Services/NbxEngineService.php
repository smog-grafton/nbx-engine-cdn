<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NbxEngineService
{
    public function createRemoteJob(array $data, MediaSourceService $mediaSourceService): MediaSource
    {
        $sourceUrl = SafeRemoteMediaUrl::assertAllowed((string) ($data['source_url'] ?? ''));
        $asset = MediaAsset::create([
            'type' => (string) ($data['asset_type'] ?? 'generic'),
            'title' => (string) ($data['title'] ?? basename((string) parse_url($sourceUrl, PHP_URL_PATH)) ?: 'NBX Media'),
            'description' => $data['description'] ?? null,
            'status' => 'importing',
            'visibility' => (string) ($data['visibility'] ?? 'public'),
        ]);

        $source = MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => $sourceUrl,
            'status' => 'pending',
            'is_active' => true,
            'compress_enabled' => (bool) ($data['compress_enabled'] ?? false),
            'source_metadata' => $this->initialMetadata($data, 'remote_fetch'),
        ]);

        $importMode = in_array(($data['import_mode'] ?? 'queue'), ['now', 'queue'], true)
            ? (string) $data['import_mode']
            : 'queue';

        if ($importMode === 'now') {
            $mediaSourceService->importRemoteNow($source);
        } else {
            $mediaSourceService->queueRemoteImport($source);
        }

        $source = $this->markNbxStatus($source->fresh() ?? $source, $importMode === 'now' ? 'probing' : 'pending');
        app(NbxWebhookDispatcher::class)->dispatch($source, 'job.created');

        return $source;
    }

    public function createUploadJob(array $data, UploadedFile $file, MediaSourceService $mediaSourceService): MediaSource
    {
        $asset = MediaAsset::create([
            'type' => (string) ($data['asset_type'] ?? 'generic'),
            'title' => (string) ($data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
            'description' => $data['description'] ?? null,
            'status' => 'importing',
            'visibility' => (string) ($data['visibility'] ?? 'public'),
        ]);

        $source = MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => (string) config('nbx.work_storage', config('cdn.disk', 'public')),
            'status' => 'uploading',
            'progress_percent' => 0,
            'external_job_id' => (string) Str::uuid(),
            'compress_enabled' => (bool) ($data['compress_enabled'] ?? false),
            'is_active' => true,
            'source_metadata' => $this->initialMetadata($data, 'upload'),
        ]);

        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Could not open NBX upload stream.',
                'completed_at' => now(),
            ]);

            return $this->markNbxStatus($source->fresh(), 'failed', 'Could not open NBX upload stream.');
        }

        try {
            $source = $mediaSourceService->storeStreamedSource(
                $asset,
                $stream,
                $file->getClientOriginalName(),
                $file->getSize() ?: null,
                $file->getClientMimeType(),
                null,
                $source,
            );
        } finally {
            fclose($stream);
        }

        $source = $this->markNbxStatus($source->fresh(), 'uploading');
        app(NbxWebhookDispatcher::class)->dispatch($source, 'job.created');

        return $source;
    }

    public function markNbxStatus(?MediaSource $source, string $status, ?string $message = null): MediaSource
    {
        if (! $source) {
            throw new \RuntimeException('NBX source was not found.');
        }

        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $previousStatus = $nbx['status'] ?? null;
        $nbx['status'] = $status;
        $nbx['job_id'] = $source->external_job_id ?: ($nbx['job_id'] ?? null);
        $nbx['updated_at'] = now()->toIso8601String();
        if ($message !== null) {
            $nbx['message'] = $message;
        }
        $metadata['nbx'] = $nbx;
        $metadata['provider'] = 'nbx_engine';

        $source->update(['source_metadata' => $metadata]);

        $fresh = $source->fresh() ?? $source;
        if ($previousStatus !== $status) {
            $this->dispatchStatusWebhook($fresh, $status, $message);
        }

        return $fresh;
    }

    public function finalizeStorageIfNeeded(MediaSource $source): MediaSource
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $target = (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $targetDisk = $target === 'contabo' ? 'contabo' : ($target === 'local' ? 'public' : $target);
        $currentDisk = $source->storage_disk ?: (string) config('cdn.disk', 'public');

        if ($targetDisk === '' || $targetDisk === $currentDisk || $source->status !== 'ready') {
            return $this->refreshOutputMetadata($source);
        }

        if ($targetDisk !== 'contabo' && ! (bool) config('nbx.allow_local_storage', true)) {
            return $this->refreshOutputMetadata($source);
        }

        $paths = array_values(array_unique(array_filter([
            $source->storage_path,
            $source->original_storage_path,
            $source->optimized_path,
            $source->hls_master_path,
            ...$this->qualityPaths($source),
            ...$this->hlsDirectoryFiles($source, $currentDisk),
        ])));

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! Storage::disk($currentDisk)->exists($path)) {
                continue;
            }

            $stream = Storage::disk($currentDisk)->readStream($path);
            if (! is_resource($stream)) {
                continue;
            }
            try {
                Storage::disk($targetDisk)->put($path, $stream, ['visibility' => 'public']);
            } finally {
                fclose($stream);
            }
        }

        $metadata['nbx'] = array_merge($nbx, [
            'storage_target' => $target,
            'work_storage_disk' => $currentDisk,
            'final_storage_disk' => $targetDisk,
            'finalized_at' => now()->toIso8601String(),
        ]);

        $source->update([
            'storage_disk' => $targetDisk,
            'source_metadata' => $metadata,
        ]);

        return $this->refreshOutputMetadata($source->fresh() ?? $source);
    }

    public function refreshOutputMetadata(MediaSource $source): MediaSource
    {
        $mediaSourceService = app(MediaSourceService::class);
        $playback = $mediaSourceService->buildPlaybackManifest($source);
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $probe = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : [];

        $metadata['provider'] = 'nbx_engine';
        $metadata['nbx'] = array_merge($nbx, [
            'status' => $source->status === 'failed'
                ? 'failed'
                : ($source->playback_type === 'hls' ? 'completed' : ($source->is_faststart ? 'partially_completed' : ($nbx['status'] ?? 'pending'))),
            'job_id' => $source->external_job_id,
            'outputs' => [
                'original_url' => $mediaSourceService->buildPublicUrl($source),
                'faststart_mp4_url' => $playback['mp4_play_url'] ?? null,
                'download_mp4_url' => $this->mp4OnlyUrl($playback['download_url'] ?? null),
                'hls_master_url' => $playback['hls_master_url'] ?? null,
                'qualities' => $playback['qualities'] ?? [],
            ],
            'probe' => $probe,
        ]);

        $source->update(['source_metadata' => $metadata]);

        return $source->fresh() ?? $source;
    }

    /**
     * @return array<string, mixed>
     */
    public function discoveryPayload(MediaSource $source, MediaSourceService $mediaSourceService): array
    {
        $source = $this->refreshOutputMetadata($source);
        $playback = $mediaSourceService->buildPlaybackManifest($source);
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $probe = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : [];
        $qualities = is_array($playback['qualities'] ?? null) ? $playback['qualities'] : [];

        $qualityUrl = static function (string $id) use ($qualities): ?string {
            foreach ($qualities as $quality) {
                if (is_array($quality) && strtolower((string) ($quality['id'] ?? '')) === $id) {
                    return is_string($quality['url'] ?? null) ? $quality['url'] : null;
                }
            }

            return null;
        };

        return [
            'nbx_job_id' => $source->external_job_id,
            'asset_id' => (string) $source->media_asset_id,
            'source_id' => $source->id,
            'status' => $nbx['status'] ?? $source->status,
            'source_status' => $source->status,
            'optimize_status' => $source->optimize_status,
            'failure_reason' => $source->failure_reason ?: $source->optimize_error,
            'storage_disk' => $source->storage_disk,
            'storage_target' => $nbx['storage_target'] ?? null,
            'original_url' => $mediaSourceService->buildPublicUrl($source),
            'faststart_mp4_url' => $playback['mp4_play_url'] ?? null,
            'download_mp4_url' => $this->mp4OnlyUrl($playback['download_url'] ?? null),
            'hls_master_url' => $playback['hls_master_url'] ?? null,
            'hls_480p_url' => $qualityUrl('480p'),
            'hls_720p_url' => $qualityUrl('720p'),
            'hls_1080p_url' => $qualityUrl('1080p'),
            'qualities' => $qualities,
            'file_size_bytes' => $source->file_size_bytes,
            'duration_seconds' => $source->duration_seconds ?: ($probe['duration_seconds'] ?? null),
            'width' => $probe['width'] ?? null,
            'height' => $probe['height'] ?? null,
            'video_codec' => $probe['video_codec'] ?? null,
            'audio_codec' => $probe['audio_codec'] ?? null,
            'bitrate' => $probe['bitrate'] ?? null,
            'probe' => $probe,
            'playback' => $playback,
            'metadata' => $metadata,
            'created_at' => $source->created_at?->toIso8601String(),
            'updated_at' => $source->updated_at?->toIso8601String(),
        ];
    }

    public function findForDiscovery(array $criteria): ?MediaSource
    {
        if (! empty($criteria['job_id'])) {
            return MediaSource::with('asset')->where('external_job_id', (string) $criteria['job_id'])->latest('id')->first();
        }

        if (! empty($criteria['source_id'])) {
            return MediaSource::with('asset')->find((int) $criteria['source_id']);
        }

        if (! empty($criteria['source_url'])) {
            $url = (string) $criteria['source_url'];
            if (preg_match('~/media(?:-hls)?/[^/]+/(\\d+)/~', $url, $matches) === 1) {
                return MediaSource::with('asset')->find((int) $matches[1]);
            }

            return MediaSource::with('asset')
                ->where('source_url', $url)
                ->orWhere('source_url', \App\Support\MediaUrl::normalize($url) ?? $url)
                ->latest('id')
                ->first();
        }

        if (! empty($criteria['video_ref_type']) && ! empty($criteria['video_ref_id'])) {
            return MediaSource::with('asset')
                ->where('source_metadata->video_ref_type', (string) $criteria['video_ref_type'])
                ->where('source_metadata->video_ref_id', (string) $criteria['video_ref_id'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function initialMetadata(array $data, string $inputType): array
    {
        $requested = [
            'faststart' => (bool) ($data['faststart'] ?? config('nbx.default_faststart', true)),
            'compression' => (bool) ($data['compress_enabled'] ?? false),
            'hls' => [
                '480p' => (bool) ($data['hls_480p'] ?? config('nbx.default_hls_480', true)),
                '720p' => (bool) ($data['hls_720p'] ?? config('nbx.default_hls_720', false)),
                '1080p' => (bool) ($data['hls_1080p'] ?? config('nbx.default_hls_1080', false)),
            ],
            'allow_downloads' => (bool) ($data['allow_downloads'] ?? true),
            'allow_hls_streaming' => (bool) ($data['allow_hls_streaming'] ?? true),
        ];

        if (! (bool) config('nbx.allow_1080p', false)) {
            $requested['hls']['1080p'] = false;
        }

        $storageTarget = (string) ($data['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        if ($storageTarget !== 'contabo' && ! (bool) config('nbx.allow_local_storage', true)) {
            $storageTarget = 'contabo';
        }

        return [
            'provider' => 'nbx_engine',
            'video_ref_type' => $data['video_ref_type'] ?? null,
            'video_ref_id' => isset($data['video_ref_id']) ? (string) $data['video_ref_id'] : null,
            'callback_url' => $data['callback_url'] ?? null,
            'object_disk' => $data['object_disk'] ?? null,
            'object_key' => $data['object_key'] ?? null,
            'object_url' => $data['object_url'] ?? null,
            'nbx' => [
                'input_type' => $inputType,
                'status' => 'pending',
                'storage_target' => $storageTarget,
                'callback_url' => $data['callback_url'] ?? null,
                'requested' => $requested,
                'submitted_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function dispatchStatusWebhook(MediaSource $source, string $status, ?string $message = null): void
    {
        $event = match ($status) {
            'fetching' => 'job.fetching',
            'probing' => 'job.probing',
            'failed' => 'job.failed',
            default => null,
        };

        if ($event === null) {
            return;
        }

        app(NbxWebhookDispatcher::class)->dispatch($source, $event, array_filter([
            'status' => $status,
            'message' => $message,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<int, string>
     */
    private function qualityPaths(MediaSource $source): array
    {
        $rows = is_array($source->qualities_json) ? $source->qualities_json : [];
        $paths = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['path']) && is_string($row['path'])) {
                $paths[] = $row['path'];
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function hlsDirectoryFiles(MediaSource $source, string $disk): array
    {
        if (! $source->hls_master_path) {
            return [];
        }

        try {
            return Storage::disk($disk)->allFiles(dirname((string) $source->hls_master_path));
        } catch (\Throwable) {
            return [];
        }
    }

    private function mp4OnlyUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (str_ends_with($path, '.m3u8')) {
            return null;
        }

        return $url;
    }
}

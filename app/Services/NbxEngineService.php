<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

        $source = $this->publishAvailableArtifacts($source->fresh() ?? $source, ['original']);
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

        if ($targetDisk === '' || $source->status !== 'ready') {
            return $this->refreshOutputMetadata($source);
        }

        if ($targetDisk !== 'contabo' && ! (bool) config('nbx.allow_local_storage', true)) {
            return $this->refreshOutputMetadata($source);
        }

        if ($targetDisk !== 'contabo') {
            return $this->refreshOutputMetadata($source);
        }

        $source = $this->publishAvailableArtifacts($source, ['original', 'faststart', 'hls']);

        if (! (bool) config('nbx.keep_local_work_files', false)) {
            $this->cleanupLocalWorkFiles($source, $currentDisk);
        }

        return $this->refreshOutputMetadata($source->fresh() ?? $source);
    }

    /**
     * @param array<int, string> $roles
     */
    public function publishAvailableArtifacts(MediaSource $source, array $roles = ['original', 'faststart', 'hls']): MediaSource
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $target = (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $targetDisk = $target === 'contabo' ? 'contabo' : ($target === 'local' ? 'public' : $target);
        $currentDisk = $source->storage_disk ?: (string) config('cdn.disk', 'public');

        if ($source->status !== 'ready' || $targetDisk !== 'contabo') {
            return $this->refreshOutputMetadata($source);
        }

        $contaboCredentials = app(ContaboStorageCredentialService::class);
        if (! $contaboCredentials->ensureRuntimeDiskCredentials()) {
            return $this->failFinalization($source, $contaboCredentials->configurationError(), $metadata, $nbx, $target, $currentDisk);
        }

        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $roles = array_values(array_unique($roles));
        $qualities = is_array($source->qualities_json) ? $source->qualities_json : [];

        try {
            if (in_array('original', $roles, true)) {
                $originalPath = $this->firstExistingPath($currentDisk, [
                    $source->original_storage_path,
                    $source->storage_path,
                ]);

                if ($originalPath) {
                    $artifacts['original'] = $this->copyFinalArtifact($currentDisk, $originalPath, 'contabo', $this->finalObjectKey($source, 'original', $originalPath), 'original');
                }
            }

            if (in_array('faststart', $roles, true)) {
                $faststartPath = $this->firstExistingPath($currentDisk, [
                    $source->optimized_path,
                    $source->storage_path,
                ]);

                if ($faststartPath) {
                    $artifacts['faststart'] = $this->copyFinalArtifact($currentDisk, $faststartPath, 'contabo', $this->finalObjectKey($source, 'faststart', $faststartPath), 'faststart');
                }
            }

            if (in_array('hls', $roles, true) && $source->hls_master_path && Storage::disk($currentDisk)->exists((string) $source->hls_master_path)) {
                $hlsBase = dirname((string) $source->hls_master_path);
                $hlsFiles = $this->hlsDirectoryFiles($source, $currentDisk);
                foreach ($hlsFiles as $path) {
                    $relative = ltrim(substr($path, strlen($hlsBase)), '/');
                    $artifact = $this->copyFinalArtifact($currentDisk, $path, 'contabo', $this->finalObjectKey($source, 'hls/' . $relative, $path), str_ends_with($path, '.m3u8') ? 'hls' : 'hls_segment');

                    if ($path === $source->hls_master_path) {
                        $artifacts['hls_master'] = $artifact;
                    }

                    foreach ($qualities as $index => $quality) {
                        if (! is_array($quality) || ($quality['path'] ?? null) !== $path) {
                            continue;
                        }

                        $qualities[$index]['source_path'] = $path;
                        $qualities[$index]['disk'] = 'contabo';
                        $qualities[$index]['key'] = $artifact['key'];
                        $qualities[$index]['url'] = $artifact['url'];
                    }
                }

                $artifacts['qualities'] = $qualities;
            }
        } catch (\Throwable $exception) {
            Log::error('NBX final storage upload failed', [
                'source_id' => $source->id,
                'target_disk' => $targetDisk,
                'current_disk' => $currentDisk,
                'error' => $exception->getMessage(),
            ]);

            return $this->failFinalization($source, $exception->getMessage(), $metadata, $nbx, $target, $currentDisk);
        }

        $nbx = array_merge($nbx, [
            'storage_target' => $target,
            'work_storage_disk' => $currentDisk,
            'final_storage_disk' => 'contabo',
            'final_artifacts' => $artifacts,
            'published_at' => now()->toIso8601String(),
        ]);
        $metadata['nbx'] = $nbx;
        $metadata['provider'] = 'nbx_engine';

        $source->update(['source_metadata' => $metadata]);

        return $source->fresh() ?? $source;
    }

    private function failFinalization(MediaSource $source, string $message, array $metadata, array $nbx, string $target, string $currentDisk): MediaSource
    {
        $metadata['nbx'] = array_merge($nbx, [
            'status' => 'failed',
            'storage_target' => $target,
            'work_storage_disk' => $currentDisk,
            'finalization_error' => $message,
            'failed_at' => now()->toIso8601String(),
        ]);

        $source->update([
            'optimize_status' => 'failed',
            'optimize_error' => 'Final storage upload failed: ' . $message,
            'source_metadata' => $metadata,
        ]);

        $fresh = $source->fresh() ?? $source;
        app(NbxWebhookDispatcher::class)->dispatch($fresh, 'job.failed', [
            'stage' => 'final_storage',
            'message' => $message,
        ]);

        return $fresh;
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
            'default_storage' => (string) config('nbx.default_storage', 'contabo'),
            'original_url' => $mediaSourceService->buildPublicUrl($source),
            'faststart_mp4_url' => $playback['mp4_play_url'] ?? null,
            'download_mp4_url' => $this->mp4OnlyUrl($playback['download_url'] ?? null),
            'hls_master_url' => $playback['hls_master_url'] ?? null,
            'hls_480p_url' => $qualityUrl('480p'),
            'hls_720p_url' => $qualityUrl('720p'),
            'hls_1080p_url' => $qualityUrl('1080p'),
            'qualities' => $qualities,
            'sources' => $this->sourceList($source, $nbx, $playback),
            'local_work_path' => $source->storage_disk === 'contabo' ? null : $source->storage_path,
            'local_public_url' => ($nbx['storage_target'] ?? null) === 'local' ? $mediaSourceService->buildPublicUrl($source) : null,
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

    private function firstExistingPath(string $disk, array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && Storage::disk($disk)->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function copyFinalArtifact(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath, string $role): array
    {
        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not open {$sourcePath} from {$sourceDisk} before final storage upload.");
        }

        try {
            $stored = Storage::disk($targetDisk)->put($targetPath, $stream, ['visibility' => 'public']);
        } finally {
            fclose($stream);
        }

        if (! $stored || ! Storage::disk($targetDisk)->exists($targetPath)) {
            throw new \RuntimeException("Could not store {$targetPath} on {$targetDisk}.");
        }

        return [
            'role' => $role,
            'disk' => $targetDisk,
            'key' => $targetPath,
            'source_path' => $sourcePath,
            'url' => Storage::disk($targetDisk)->url($targetPath),
            'published_at' => now()->toIso8601String(),
        ];
    }

    private function finalObjectKey(MediaSource $source, string $role, string $sourcePath): string
    {
        $prefix = trim((string) config('services.contabo_object_storage.path_prefix', 'videos'), '/');
        $job = $source->external_job_id ?: ('source-' . $source->id);
        $safeJob = Str::slug($job) ?: ('source-' . $source->id);
        $filename = basename($sourcePath);

        if (str_starts_with($role, 'hls/')) {
            return trim($prefix . '/nbx/' . $safeJob . '/' . $role, '/');
        }

        return trim($prefix . '/nbx/' . $safeJob . '/' . trim($role, '/') . '/' . $filename, '/');
    }

    private function cleanupLocalWorkFiles(MediaSource $source, string $disk): void
    {
        if ($disk === 'contabo') {
            return;
        }

        $paths = array_values(array_unique(array_filter([
            $source->storage_path,
            $source->original_storage_path,
            $source->optimized_path,
            $source->hls_master_path,
            ...$this->qualityPaths($source),
            ...$this->hlsDirectoryFiles($source, $disk),
        ])));

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    private function sourceList(MediaSource $source, array $nbx, array $playback): array
    {
        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $sources = [];

        foreach (['original' => 'source', 'faststart' => '480p', 'hls_master' => 'auto'] as $role => $quality) {
            $artifact = is_array($artifacts[$role] ?? null) ? $artifacts[$role] : [];
            if (! is_string($artifact['url'] ?? null) || $artifact['url'] === '') {
                continue;
            }

            $isHls = $role === 'hls_master';
            $sources[] = [
                'type' => $isHls ? 'hls' : 'mp4',
                'role' => $role === 'hls_master' ? 'hls' : $role,
                'quality' => $quality,
                'disk' => $artifact['disk'] ?? 'contabo',
                'key' => $artifact['key'] ?? null,
                'url' => $artifact['url'],
                'is_downloadable' => ! $isHls,
                'is_streamable' => true,
            ];
        }

        foreach ((array) ($artifacts['qualities'] ?? []) as $quality) {
            if (! is_array($quality) || ! is_string($quality['url'] ?? null) || $quality['url'] === '') {
                continue;
            }

            $sources[] = [
                'type' => 'hls',
                'role' => 'hls',
                'quality' => strtolower((string) ($quality['id'] ?? $quality['label'] ?? 'unknown')),
                'disk' => $quality['disk'] ?? 'contabo',
                'key' => $quality['key'] ?? null,
                'url' => $quality['url'],
                'is_downloadable' => false,
                'is_streamable' => true,
            ];
        }

        return $sources;
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

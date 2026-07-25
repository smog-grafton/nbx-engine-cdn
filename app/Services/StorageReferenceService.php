<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Models\StorageObjectReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageReferenceService
{
    public function register(array $data): StorageObjectReference
    {
        $disk = (string) ($data['storage_disk'] ?? 'contabo');
        $bucket = (string) ($data['storage_bucket'] ?? config("filesystems.disks.{$disk}.bucket", ''));
        $key = $this->normalizeObjectKey((string) ($data['object_key'] ?? ''));
        if ($key === '' || $bucket === '') {
            throw new \InvalidArgumentException('Storage bucket and object key are required.');
        }

        $url = $this->safePublicUrl($data['object_url'] ?? null, $disk, $key);
        $portalSourceId = isset($data['portal_source_id']) ? (int) $data['portal_source_id'] : null;
        $referenceKey = hash('sha256', implode('|', [
            $bucket,
            $key,
            (string) ($portalSourceId ?: 'nbx'),
        ]));

        return DB::transaction(function () use ($data, $disk, $bucket, $key, $url, $portalSourceId, $referenceKey): StorageObjectReference {
            $source = $this->findMediaSourceForObject($disk, $key);
            if (! $source && (bool) ($data['import_into_nbx'] ?? true)) {
                $asset = MediaAsset::create([
                    'type' => 'generic',
                    'title' => 'Portal source #'.($portalSourceId ?: 'direct').' · '.basename($key),
                    'description' => 'Direct Contabo object registered by Portal without re-uploading.',
                    'status' => 'ready',
                    'visibility' => 'unlisted',
                ]);
                $source = $asset->sources()->create([
                    'source_type' => 'url',
                    'source_url' => $url,
                    'storage_disk' => $disk,
                    'storage_path' => $key,
                    'mime_type' => $this->contentType($key),
                    'file_size_bytes' => $this->safeSize($disk, $key),
                    'status' => 'ready',
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'source_metadata' => [
                        'provider' => 'contabo_direct',
                        'registered_direct' => true,
                        'portal_source_id' => $portalSourceId,
                        'portal_sourceable_type' => $data['portal_sourceable_type'] ?? null,
                        'portal_sourceable_id' => $data['portal_sourceable_id'] ?? null,
                        'object_disk' => $disk,
                        'object_key' => $key,
                        'object_url' => $url,
                    ],
                ]);
            }

            return StorageObjectReference::updateOrCreate(
                ['reference_key' => $referenceKey],
                [
                    'media_asset_id' => $source?->media_asset_id,
                    'media_source_id' => $source?->id,
                    'portal_source_id' => $portalSourceId,
                    'portal_sourceable_type' => $data['portal_sourceable_type'] ?? null,
                    'portal_sourceable_id' => $data['portal_sourceable_id'] ?? null,
                    'storage_disk' => $disk,
                    'storage_bucket' => $bucket,
                    'object_key' => $key,
                    'object_url' => $url,
                    'media_role' => (string) ($data['media_role'] ?? $this->classifyRole($key)),
                    'is_external_direct' => (bool) ($data['is_external_direct'] ?? true),
                    'is_primary' => (bool) ($data['is_primary'] ?? false),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'health_status' => (string) ($data['health_status'] ?? 'unknown'),
                    'last_verified_at' => $this->safeExists($disk, $key) ? now() : null,
                    'deleted_at_storage' => null,
                    'metadata' => array_merge((array) ($data['metadata'] ?? []), [
                        'idempotency_key' => $data['idempotency_key'] ?? null,
                        'registered_without_upload' => true,
                    ]),
                ],
            );
        });
    }

    public function normalizeObjectKey(string $key): string
    {
        $key = rawurldecode(trim($key));
        $key = preg_replace('#/+#', '/', $key) ?: '';
        $key = ltrim($key, '/');
        if ($key === '' || str_contains($key, '../') || str_starts_with($key, '..')) {
            return '';
        }

        return $key;
    }

    public function classifyRole(string $key): string
    {
        $lower = strtolower($key);
        $extension = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));

        if (str_contains($lower, '/original/')) {
            return 'source_original';
        }
        if (str_contains($lower, '/faststart/') || str_contains($lower, '_play.mp4')) {
            return 'faststart_mp4';
        }
        if (str_contains($lower, '/hls/')) {
            if (str_ends_with($lower, '/master.m3u8') || str_ends_with($lower, 'master.m3u8')) {
                return 'hls_master';
            }

            return $extension === 'm3u8' ? 'hls_variant' : (in_array($extension, ['ts', 'm4s', 'mp4'], true) ? 'hls_segment' : 'hls_asset');
        }
        if (str_contains($lower, '/tmp/') || str_contains($lower, '/temp/')) {
            return 'temporary';
        }
        if (in_array($extension, ['vtt', 'srt', 'ass', 'ssa'], true)) {
            return 'subtitle';
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return 'thumbnail';
        }
        if (in_array($extension, ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi'], true)) {
            return 'download_asset';
        }

        return 'unknown';
    }

    public function hlsPackagePrefix(string $key): ?string
    {
        $position = strpos($key, '/hls/');

        return $position === false ? null : substr($key, 0, $position + 5);
    }

    private function findMediaSourceForObject(string $disk, string $key): ?MediaSource
    {
        $direct = MediaSource::query()
            ->where('storage_disk', $disk)
            ->where(function ($query) use ($key): void {
                $query->where('storage_path', $key)
                    ->orWhere('original_storage_path', $key)
                    ->orWhere('optimized_path', $key)
                    ->orWhere('hls_master_path', $key)
                    ->orWhere('source_metadata->object_key', $key);
            })
            ->latest('id')
            ->first();
        if ($direct) {
            return $direct;
        }

        return MediaSource::query()->latest('id')->limit(500)->get()->first(function (MediaSource $source) use ($key): bool {
            $nbx = (array) (($source->source_metadata ?? [])['nbx'] ?? []);
            foreach ((array) ($nbx['final_artifacts'] ?? []) as $artifact) {
                if (is_array($artifact) && ($artifact['key'] ?? null) === $key) {
                    return true;
                }
            }

            return false;
        });
    }

    private function safePublicUrl(mixed $url, string $disk, string $key): string
    {
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            $parts = parse_url($url);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .($parts['path'] ?? '');
        }

        return Storage::disk($disk)->url($key);
    }

    private function safeExists(string $disk, string $key): bool
    {
        try {
            return Storage::disk($disk)->exists($key);
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeSize(string $disk, string $key): ?int
    {
        try {
            return Storage::disk($disk)->exists($key) ? (int) Storage::disk($disk)->size($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function contentType(string $key): string
    {
        return match (strtolower((string) pathinfo($key, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'vtt' => 'text/vtt',
            default => 'application/octet-stream',
        };
    }
}

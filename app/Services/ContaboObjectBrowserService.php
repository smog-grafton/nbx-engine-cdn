<?php

namespace App\Services;

use App\Models\MediaSource;
use App\Models\StorageObjectReference;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ContaboObjectBrowserService
{
    /**
     * @return array{objects:array<int,array<string,mixed>>,next_cursor:?string,is_truncated:bool}
     */
    public function list(
        string $prefix = '',
        ?string $cursor = null,
        int $limit = 50,
        ?string $search = null,
        ?string $role = null,
        ?string $extension = null,
        ?string $association = null,
    ): array {
        $disk = (string) config('services.contabo_object_storage.disk', 'contabo');
        $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
        $prefix = $this->safePrefix($prefix);
        $limit = max(1, min(100, $limit));

        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            return $this->listLocal($disk, $bucket, $prefix, $cursor, $limit, $search, $role, $extension, $association);
        }

        if ($disk === 'contabo') {
            $credentials = app(ContaboStorageCredentialService::class);
            if (! $credentials->ensureRuntimeDiskCredentials()) {
                throw new RuntimeException($credentials->configurationError());
            }

            // Runtime discovery forgets the original disk so the new credentials are
            // applied. Always reacquire it before obtaining the S3 client.
            $storage = Storage::disk($disk);
            $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
            $endpoint = (string) config("filesystems.disks.{$disk}.endpoint", '');
            if ($bucket === '' || $endpoint === '') {
                throw new RuntimeException('Contabo storage requires both CONTABO_BUCKET (or CONTABO_OBJECT_STORAGE_BUCKET) and CONTABO_ENDPOINT.');
            }
        }

        $params = [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'MaxKeys' => $limit,
        ];
        if ($cursor) {
            $params['ContinuationToken'] = $cursor;
        }
        try {
            $result = $storage->getClient()->listObjectsV2($params);
        } catch (CredentialsException $exception) {
            throw new RuntimeException(
                'Contabo S3 credentials are unavailable. Configure CONTABO_ACCESS_KEY_ID and CONTABO_SECRET_ACCESS_KEY (or their CONTABO_OBJECT_STORAGE_* aliases).',
                previous: $exception,
            );
        } catch (AwsException $exception) {
            $code = $exception->getAwsErrorCode();
            throw new RuntimeException(
                'Contabo object listing failed'.($code ? " ({$code})" : '').'. Verify the bucket, region, endpoint, and S3 key permissions.',
                previous: $exception,
            );
        }
        $rows = [];
        foreach ((array) ($result['Contents'] ?? []) as $object) {
            $key = (string) ($object['Key'] ?? '');
            if (! $this->matches($key, $search, $role, $extension)) {
                continue;
            }
            $row = $this->row(
                $disk,
                $bucket,
                $key,
                (int) ($object['Size'] ?? 0),
                $object['LastModified'] ?? null,
                isset($object['ETag']) ? trim((string) $object['ETag'], '"') : null,
            );
            if ($this->matchesAssociation($row, $association)) {
                $rows[] = $row;
            }
        }

        return [
            'objects' => $rows,
            'next_cursor' => isset($result['NextContinuationToken']) ? (string) $result['NextContinuationToken'] : null,
            'is_truncated' => (bool) ($result['IsTruncated'] ?? false),
        ];
    }

    private function listLocal(
        string $disk,
        string $bucket,
        string $prefix,
        ?string $cursor,
        int $limit,
        ?string $search,
        ?string $role,
        ?string $extension,
        ?string $association,
    ): array {
        $offset = max(0, (int) ($cursor ?: 0));
        $files = Storage::disk($disk)->allFiles($prefix);
        sort($files);
        $page = array_slice($files, $offset, $limit);

        $rows = [];
        foreach ($page as $key) {
            if (! $this->matches($key, $search, $role, $extension)) {
                continue;
            }
            $row = $this->row(
                $disk,
                $bucket,
                $key,
                (int) Storage::disk($disk)->size($key),
                Storage::disk($disk)->lastModified($key),
                null,
            );
            if ($this->matchesAssociation($row, $association)) {
                $rows[] = $row;
            }
        }

        return [
            'objects' => $rows,
            'next_cursor' => $offset + $limit < count($files) ? (string) ($offset + $limit) : null,
            'is_truncated' => $offset + $limit < count($files),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(
        string $disk,
        string $bucket,
        string $key,
        int $size,
        mixed $lastModified,
        ?string $etag,
    ): array {
        $references = StorageObjectReference::query()
            ->with(['mediaSource', 'asset'])
            ->where('storage_bucket', $bucket)
            ->where('object_key', $key)
            ->whereNull('deleted_at_storage')
            ->get();
        $role = app(StorageReferenceService::class)->classifyRole($key);
        $hlsPrefix = app(StorageReferenceService::class)->hlsPackagePrefix($key);
        $sources = MediaSource::query()
            ->where('storage_disk', $disk)
            ->where(function ($query) use ($key, $hlsPrefix): void {
                $query->where('storage_path', $key)
                    ->orWhere('original_storage_path', $key)
                    ->orWhere('optimized_path', $key)
                    ->orWhere('hls_master_path', $key);
                if ($hlsPrefix) {
                    $query->orWhere('hls_master_path', 'like', $hlsPrefix.'%');
                }
            })
            ->get();
        $allSourceIds = $references->pluck('media_source_id')
            ->merge($sources->pluck('id'))
            ->filter()
            ->unique()
            ->values();
        $allAssetIds = $references->pluck('media_asset_id')
            ->merge($sources->pluck('media_asset_id'))
            ->filter()
            ->unique()
            ->values();
        $jobs = $references->pluck('mediaSource.external_job_id')
            ->merge($sources->pluck('external_job_id'))
            ->filter()
            ->unique()
            ->values();

        return [
            'key' => $key,
            'filename' => basename($key),
            'prefix' => trim((string) dirname($key), '.'),
            'size' => $size,
            'content_type' => $this->contentType($key),
            'last_modified' => $lastModified instanceof \DateTimeInterface
                ? $lastModified->format(DATE_ATOM)
                : (is_numeric($lastModified) ? date(DATE_ATOM, (int) $lastModified) : (string) $lastModified),
            'etag' => $etag,
            'media_role' => $role,
            'hls_package_prefix' => $hlsPrefix,
            'reference_ids' => $references->pluck('id')->values()->all(),
            'direct_reference_ids' => $references->where('is_external_direct', true)->pluck('id')->values()->all(),
            'associated_nbx_asset_ids' => $allAssetIds->all(),
            'associated_media_source_ids' => $allSourceIds->all(),
            'associated_portal_source_ids' => $references->pluck('portal_source_id')->filter()->unique()->values()->all(),
            'portal_reference_count' => $references->whereNotNull('portal_source_id')->count(),
            'processing_jobs' => $jobs->all(),
            'orphaned' => $references->isEmpty() && $sources->isEmpty(),
            'disk' => $disk,
            'bucket' => $bucket,
        ];
    }

    private function safePrefix(string $prefix): string
    {
        $prefix = app(StorageReferenceService::class)->normalizeObjectKey($prefix);
        $allowed = trim((string) config('services.contabo_object_storage.path_prefix', 'videos'), '/');

        return $prefix === '' ? $allowed : (str_starts_with($prefix, $allowed) ? $prefix : $allowed);
    }

    private function matches(string $key, ?string $search, ?string $role, ?string $extension): bool
    {
        if ($search && ! str_contains(strtolower($key), strtolower(trim($search)))) {
            return false;
        }
        if ($extension && $extension !== 'all'
            && strtolower((string) pathinfo($key, PATHINFO_EXTENSION)) !== strtolower(ltrim($extension, '.'))
        ) {
            return false;
        }

        return ! $role || $role === 'all' || app(StorageReferenceService::class)->classifyRole($key) === $role;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function matchesAssociation(array $row, ?string $association): bool
    {
        return match ($association) {
            'portal' => ! empty($row['associated_portal_source_ids']),
            'nbx' => ! empty($row['associated_media_source_ids']),
            'orphan' => (bool) ($row['orphaned'] ?? false),
            default => true,
        };
    }

    private function contentType(string $key): string
    {
        return match (strtolower((string) pathinfo($key, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'm4s' => 'video/iso.segment',
            'vtt' => 'text/vtt',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}

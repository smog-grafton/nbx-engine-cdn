<?php

namespace App\Services;

use App\Jobs\ScanContaboStorageInventoryJob;
use App\Models\MediaSource;
use App\Models\StorageCleanupPlan;
use App\Models\StorageInventoryObject;
use App\Models\StorageInventoryRun;
use App\Models\StorageObjectReference;
use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StorageInventoryService
{
    public function queue(string $prefix = ''): StorageInventoryRun
    {
        $run = $this->createRun($prefix);
        ScanContaboStorageInventoryJob::dispatch($run->id);

        return $run;
    }

    public function createRun(string $prefix = ''): StorageInventoryRun
    {
        $disk = (string) config('services.contabo_object_storage.disk', 'contabo');

        return StorageInventoryRun::query()->create([
            'storage_disk' => $disk,
            'storage_bucket' => (string) config("filesystems.disks.{$disk}.bucket", ''),
            'prefix' => app(StorageReferenceService::class)->normalizeObjectKey($prefix),
            'status' => 'queued',
        ]);
    }

    public function scan(StorageInventoryRun $run): StorageInventoryRun
    {
        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
            'failure_reason' => null,
        ]);

        try {
            $maps = $this->associationMaps();
            $count = 0;
            $bytes = 0;
            $pages = 0;

            foreach ($this->objectPages($run) as $objects) {
                $pages++;
                $this->storePage($run, $objects, $maps);
                foreach ($objects as $object) {
                    if (empty($object['Key'])) {
                        continue;
                    }
                    $count++;
                    $bytes += (int) ($object['Size'] ?? 0);
                }
                $run->update([
                    'object_count' => $count,
                    'total_bytes' => $bytes,
                    'pages_scanned' => $pages,
                ]);
            }

            StorageInventoryObject::query()
                ->where('storage_disk', $run->storage_disk)
                ->where('storage_bucket', $run->storage_bucket)
                ->when($run->prefix !== '', fn ($query) => $query->where('object_key', 'like', $run->prefix.'%'))
                ->where('last_seen_run_id', '!=', $run->id)
                ->whereNull('missing_since')
                ->update(['missing_since' => now()]);
            $this->recomputeDuplicateCandidates($run->storage_disk, $run->storage_bucket);

            $run->update([
                'status' => 'completed',
                'object_count' => $count,
                'total_bytes' => $bytes,
                'pages_scanned' => $pages,
                'continuation_token' => null,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            throw $exception;
        }

        return $run->fresh();
    }

    public function createCleanupReview(string $logicalAssetKey, ?int $userId): StorageCleanupPlan
    {
        return DB::transaction(function () use ($logicalAssetKey, $userId): StorageCleanupPlan {
            $objects = StorageInventoryObject::query()
                ->where('logical_asset_key', $logicalAssetKey)
                ->whereNull('missing_since')
                ->get();
            if ($objects->isEmpty()) {
                throw new RuntimeException('No current inventory objects exist for this package.');
            }

            $classifications = $objects->pluck('classification')->unique();
            $risk = $classifications->contains('managed') || $classifications->contains('portal_candidate')
                ? 'critical'
                : 'high';
            $plan = StorageCleanupPlan::query()->create([
                'user_id' => $userId,
                'status' => 'draft',
                'logical_asset_key' => $logicalAssetKey,
                'object_count' => $objects->count(),
                'total_bytes' => (int) $objects->sum('size_bytes'),
                'risk_level' => $risk,
                'reason' => 'Review associations, playback replacements, manifests, and duplicate evidence before confirmation.',
                'grace_expires_at' => now()->addDays(7),
            ]);
            foreach ($objects as $object) {
                $plan->items()->create([
                    'storage_inventory_object_id' => $object->id,
                    'proposed_action' => 'review_only',
                    'status' => 'pending',
                ]);
            }

            return $plan;
        });
    }

    public function verifyDuplicateGroup(string $groupHash): int
    {
        $objects = StorageInventoryObject::query()
            ->where('duplicate_group_hash', $groupHash)
            ->where('is_duplicate_candidate', true)
            ->whereNull('missing_since')
            ->get();
        if ($objects->count() < 2) {
            throw new RuntimeException('This duplicate signature group no longer contains at least two current objects.');
        }

        $verified = 0;
        foreach ($objects as $object) {
            if ($object->storage_disk === 'contabo') {
                $credentials = app(ContaboStorageCredentialService::class);
                if (! $credentials->ensureRuntimeDiskCredentials()) {
                    throw new RuntimeException($credentials->configurationError());
                }
            }
            $stream = Storage::disk($object->storage_disk)->readStream($object->object_key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Could not open '.$object->object_key.' for checksum verification.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8 * 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Checksum read failed for '.$object->object_key.'.');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
            if ($bytes !== (int) $object->size_bytes) {
                throw new RuntimeException(sprintf(
                    'Checksum verification read %d bytes for %s, but inventory expected %d.',
                    $bytes,
                    $object->object_key,
                    $object->size_bytes,
                ));
            }
            $object->update([
                'content_sha256' => hash_final($hash),
                'checksum_verified_at' => now(),
            ]);
            $verified++;
        }

        $objects = $objects->map->fresh();
        foreach ($objects->groupBy('content_sha256') as $checksum => $matches) {
            if ($checksum === '' || $matches->count() < 2) {
                continue;
            }
            StorageInventoryObject::query()
                ->whereIn('id', $matches->pluck('id'))
                ->update(['duplicate_evidence' => 'sha256']);
        }

        return $verified;
    }

    /**
     * @return \Generator<int,array<int,array<string,mixed>>>
     */
    private function objectPages(StorageInventoryRun $run): \Generator
    {
        $storage = Storage::disk($run->storage_disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            $files = $storage->allFiles($run->prefix);
            foreach (array_chunk($files, 1000) as $chunk) {
                yield array_map(fn (string $key): array => [
                    'Key' => $key,
                    'Size' => (int) $storage->size($key),
                    'LastModified' => Carbon::createFromTimestamp((int) $storage->lastModified($key)),
                    'ETag' => null,
                ], $chunk);
            }

            return;
        }

        if ($run->storage_disk === 'contabo') {
            $credentials = app(ContaboStorageCredentialService::class);
            if (! $credentials->ensureRuntimeDiskCredentials()) {
                throw new RuntimeException($credentials->configurationError());
            }
            $storage = Storage::disk($run->storage_disk);
        }
        $client = $storage->getClient();
        $token = null;
        do {
            $params = [
                'Bucket' => $run->storage_bucket,
                'Prefix' => $run->prefix,
                'MaxKeys' => 1000,
            ];
            if ($token) {
                $params['ContinuationToken'] = $token;
            }
            try {
                $result = $client->listObjectsV2($params);
            } catch (AwsException $exception) {
                throw new RuntimeException(
                    'Contabo inventory listing failed'.($exception->getAwsErrorCode() ? ' ('.$exception->getAwsErrorCode().')' : '').'.',
                    previous: $exception,
                );
            }
            $token = isset($result['NextContinuationToken']) ? (string) $result['NextContinuationToken'] : null;
            $run->update(['continuation_token' => $token]);
            yield (array) ($result['Contents'] ?? []);
        } while ((bool) ($result['IsTruncated'] ?? false) && $token);
    }

    /**
     * @return array<string,mixed>
     */
    private function associationMaps(): array
    {
        $sourcesByKey = [];
        $sourcesById = [];
        $sourcesByJob = [];
        $sourcesByAsset = [];
        foreach (MediaSource::query()->get() as $source) {
            $sourcesById[$source->id] = $source;
            if ($source->external_job_id) {
                $sourcesByJob[(string) $source->external_job_id] = $source;
            }
            $sourcesByAsset[(string) $source->media_asset_id] = $source;
            foreach (array_filter([
                $source->storage_path,
                $source->original_storage_path,
                $source->optimized_path,
                $source->hls_master_path,
            ]) as $key) {
                $sourcesByKey[ltrim((string) $key, '/')] = $source;
            }
            foreach ((array) data_get($source->source_metadata, 'nbx.final_artifacts', []) as $artifact) {
                if (is_array($artifact) && is_string($artifact['key'] ?? null)) {
                    $sourcesByKey[ltrim($artifact['key'], '/')] = $source;
                }
            }
        }

        return [
            'sources_by_key' => $sourcesByKey,
            'sources_by_id' => $sourcesById,
            'sources_by_job' => $sourcesByJob,
            'sources_by_asset' => $sourcesByAsset,
            'references_by_key' => StorageObjectReference::query()
                ->whereNull('deleted_at_storage')
                ->get()
                ->groupBy(fn (StorageObjectReference $reference): string => ltrim($reference->object_key, '/')),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $objects
     * @param  array<string,mixed>  $maps
     */
    private function storePage(StorageInventoryRun $run, array $objects, array $maps): void
    {
        $rows = array_values(array_filter(array_map(
            fn (array $object): ?array => $this->objectRow($run, $object, $maps),
            $objects,
        )));
        if ($rows === []) {
            return;
        }
        $firstSeen = StorageInventoryObject::query()
            ->whereIn('object_hash', array_column($rows, 'object_hash'))
            ->pluck('first_seen_at', 'object_hash');
        foreach ($rows as &$row) {
            $row['first_seen_at'] = $firstSeen[$row['object_hash']] ?? $row['last_seen_at'];
        }
        unset($row);

        foreach (array_chunk($rows, 500) as $chunk) {
            StorageInventoryObject::query()->upsert(
                $chunk,
                ['object_hash'],
                [
                    'storage_disk',
                    'storage_bucket',
                    'object_key',
                    'object_prefix',
                    'filename',
                    'extension',
                    'size_bytes',
                    'etag',
                    'content_type',
                    'object_last_modified_at',
                    'storage_layout',
                    'logical_asset_key',
                    'media_role',
                    'media_asset_id',
                    'media_source_id',
                    'portal_source_id',
                    'portal_sourceable_type',
                    'portal_sourceable_id',
                    'is_manifest_member',
                    'classification',
                    'confidence',
                    'classification_reason',
                    'metadata',
                    'last_seen_run_id',
                    'last_seen_at',
                    'missing_since',
                ],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $object
     * @param  array<string,mixed>  $maps
     * @return array<string,mixed>|null
     */
    private function objectRow(StorageInventoryRun $run, array $object, array $maps): ?array
    {
        $key = ltrim((string) ($object['Key'] ?? ''), '/');
        if ($key === '') {
            return null;
        }
        $shape = app(StorageObjectClassifier::class)->classify($key);
        $references = $maps['references_by_key']->get($key, collect());
        $source = $maps['sources_by_key'][$key]
            ?? $maps['sources_by_id'][$shape['source_id_hint'] ?? 0]
            ?? $maps['sources_by_job'][$shape['job_id_hint'] ?? '']
            ?? $maps['sources_by_asset'][$shape['asset_id_hint'] ?? '']
            ?? null;
        if (! $source && $references->isNotEmpty()) {
            $source = $maps['sources_by_id'][$references->first()->media_source_id ?? 0] ?? null;
        }

        [$classification, $confidence, $reason] = $this->classification($shape, $source, $references->isNotEmpty());
        $portalReference = $references->first(fn (StorageObjectReference $reference): bool => $reference->portal_source_id !== null);
        $lastModified = $object['LastModified'] ?? null;
        $lastModified = $lastModified instanceof \DateTimeInterface
            ? Carbon::instance($lastModified)
            : ($lastModified ? Carbon::parse((string) $lastModified) : null);
        $now = now();
        $etag = isset($object['ETag']) ? trim((string) $object['ETag'], '"') : null;

        return [
            'object_hash' => hash('sha256', $run->storage_disk.'|'.$run->storage_bucket.'|'.$key),
            'storage_disk' => $run->storage_disk,
            'storage_bucket' => $run->storage_bucket,
            'object_key' => $key,
            'object_prefix' => substr(trim((string) dirname($key), '.'), 0, 512),
            'filename' => substr(basename($key), 0, 512),
            'extension' => strtolower((string) pathinfo($key, PATHINFO_EXTENSION)) ?: null,
            'size_bytes' => (int) ($object['Size'] ?? 0),
            'etag' => $etag,
            'content_type' => $this->contentType($key),
            'object_last_modified_at' => $lastModified,
            'storage_layout' => $shape['storage_layout'],
            'logical_asset_key' => substr((string) $shape['logical_asset_key'], 0, 191),
            'media_role' => $shape['media_role'],
            'media_asset_id' => $source?->media_asset_id ?? $references->first()?->media_asset_id ?? $shape['asset_id_hint'],
            'media_source_id' => $source?->id ?? $references->first()?->media_source_id,
            'portal_source_id' => $portalReference?->portal_source_id,
            'portal_sourceable_type' => $portalReference?->portal_sourceable_type ?? $shape['portal_sourceable_type'],
            'portal_sourceable_id' => $portalReference?->portal_sourceable_id ?? $shape['portal_sourceable_id'],
            'is_manifest_member' => (bool) $shape['is_manifest_member'],
            'classification' => $classification,
            'confidence' => $confidence,
            'classification_reason' => $reason,
            'metadata' => json_encode([
                'reference_ids' => $references->pluck('id')->values()->all(),
                'multipart_etag' => is_string($etag) && str_contains($etag, '-'),
                'source_status' => $source?->status,
                'processing_job' => $source?->external_job_id,
            ], JSON_UNESCAPED_SLASHES),
            'last_seen_run_id' => $run->id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'missing_since' => null,
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function classification(array $shape, ?MediaSource $source, bool $hasReference): array
    {
        if ($hasReference || $source) {
            if ($source && in_array($source->status, ['pending', 'fetching', 'processing'], true)) {
                return ['active_processing', 'high', 'The object belongs to an active NBX processing source.'];
            }
            if ($source && $source->status === 'failed'
                && in_array($shape['media_role'], ['source_original', 'temporary', 'unknown'], true)
            ) {
                return ['failed_residue_candidate', 'medium', 'The object belongs to a failed source and needs lifecycle review.'];
            }

            return ['managed', 'high', 'Matched to an NBX source or an explicit storage reference.'];
        }
        if ($shape['storage_layout'] === 'portal_legacy') {
            return ['portal_candidate', 'medium', 'The key contains a legacy Portal content identity; Portal reconciliation is required.'];
        }
        if (in_array($shape['storage_layout'], ['nbx_work', 'nbx_final', 'nbx_legacy'], true)) {
            return ['nbx_unresolved', 'medium', 'The key follows an NBX layout but no local database row matched this scan.'];
        }

        return ['unresolved', 'low', 'No authoritative association has been found; this is not proof that the object is orphaned.'];
    }

    private function recomputeDuplicateCandidates(string $disk, string $bucket): void
    {
        $base = StorageInventoryObject::query()
            ->where('storage_disk', $disk)
            ->where('storage_bucket', $bucket)
            ->whereNull('missing_since');
        (clone $base)->update([
            'is_duplicate_candidate' => false,
            'duplicate_group_hash' => null,
            'duplicate_evidence' => null,
        ]);

        $groups = (clone $base)
            ->whereNotNull('etag')
            ->where('etag', '!=', '')
            ->where('size_bytes', '>', 0)
            ->select(['etag', 'size_bytes'])
            ->selectRaw('COUNT(*) AS matching_objects')
            ->groupBy('etag', 'size_bytes')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($groups as $group) {
            $etag = (string) $group->etag;
            (clone $base)
                ->where('etag', $etag)
                ->where('size_bytes', (int) $group->size_bytes)
                ->update([
                    'is_duplicate_candidate' => true,
                    'duplicate_group_hash' => hash('sha256', $etag.'|'.$group->size_bytes),
                    // Multipart ETags are not content MD5s. Even a single-part
                    // ETag is evidence for review, not delete authorization.
                    'duplicate_evidence' => str_contains($etag, '-')
                        ? 'multipart_etag_and_size'
                        : 'etag_and_size',
                ]);
        }

        $checksumGroups = (clone $base)
            ->whereNotNull('content_sha256')
            ->where('content_sha256', '!=', '')
            ->select(['content_sha256', 'size_bytes'])
            ->selectRaw('COUNT(*) AS matching_objects')
            ->groupBy('content_sha256', 'size_bytes')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($checksumGroups as $group) {
            (clone $base)
                ->where('content_sha256', (string) $group->content_sha256)
                ->where('size_bytes', (int) $group->size_bytes)
                ->update([
                    'is_duplicate_candidate' => true,
                    'duplicate_group_hash' => hash('sha256', 'sha256|'.$group->content_sha256.'|'.$group->size_bytes),
                    'duplicate_evidence' => 'sha256',
                ]);
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
            'm4s' => 'video/iso.segment',
            'vtt' => 'text/vtt',
            'srt' => 'application/x-subrip',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}

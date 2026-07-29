<?php

namespace App\Services;

use App\Models\MediaSource;
use App\Models\StorageActionAudit;
use App\Models\StorageCleanupPlanItem;
use App\Models\StorageInventoryObject;
use App\Models\StorageObjectReference;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class StorageDeletionService
{
    /**
     * @return array<string,mixed>
     */
    public function deleteSourceArtifact(
        MediaSource $source,
        string $role,
        array $options = [],
        array $actor = [],
    ): array {
        $role = match ($role) {
            'source_original' => 'original',
            'playback_progressive', 'faststart_mp4' => 'faststart',
            'hls_master', 'hls_package' => 'hls',
            default => $role,
        };
        if (! in_array($role, ['original', 'faststart', 'hls', 'asset'], true)) {
            throw new \InvalidArgumentException('Unsupported storage deletion role.');
        }

        $source = $source->fresh() ?? $source;
        $this->assertNotProcessing($source);
        $idempotencyKey = $this->idempotencyKey($options['idempotency_key'] ?? null, $source, $role);
        $existing = StorageActionAudit::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->status === 'completed') {
            return (array) ($existing->after_state ?? []);
        }

        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $artifacts = (array) ($nbx['final_artifacts'] ?? []);
        $audit = $existing ?: StorageActionAudit::create([
            'user_id' => $actor['user_id'] ?? null,
            'media_api_token_id' => $actor['media_api_token_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'action' => 'delete_'.$role,
            'target_type' => 'media_source',
            'target_id' => (string) $source->id,
            'storage_disk' => $source->storage_disk,
            'storage_bucket' => config('filesystems.disks.contabo.bucket'),
            'status' => 'pending',
            'before_state' => $this->safeAuditState($source, $artifacts),
            'confirmed_at' => now(),
        ]);

        try {
            $this->assertSourceDeletionAllowed($source, $artifacts, $role, $options);
            $plannedKeys = $this->artifactKeysForRole($source, $artifacts, $role);
            if ($plannedKeys !== []) {
                $this->notifyPortal($source, $plannedKeys, $role, 'storage.deletion_planned', 'planned');
            }

            $summary = match ($role) {
                'original' => $this->deleteOriginal($source, $idempotencyKey),
                'faststart' => $this->deleteFaststart($source, $artifacts, $options),
                'hls' => $this->deleteHls($source, $artifacts, $options),
                'asset' => $this->deleteAsset($source, $artifacts, $options),
            };
            $audit->update([
                'bytes_freed' => (int) ($summary['bytes_freed'] ?? 0),
                'status' => 'completed',
                'after_state' => $summary,
                'completed_at' => now(),
            ]);

            return $summary;
        } catch (\Throwable $exception) {
            $audit->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteConfirmedOrphan(string $disk, string $key, array $actor = [], ?string $idempotencyKey = null): array
    {
        $key = app(StorageReferenceService::class)->normalizeObjectKey($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Invalid object key.');
        }
        if (StorageObjectReference::query()->where('storage_disk', $disk)->where('object_key', $key)->where('is_active', true)->exists()) {
            throw new \RuntimeException('Object deletion refused: an active Portal or NBX reference exists.');
        }
        if (MediaSource::query()->where('storage_disk', $disk)->where(function ($query) use ($key): void {
            $query->where('storage_path', $key)
                ->orWhere('original_storage_path', $key)
                ->orWhere('optimized_path', $key)
                ->orWhere('hls_master_path', $key);
        })->exists()) {
            throw new \RuntimeException('Object deletion refused: NBX source metadata still references it.');
        }
        if (! Schema::hasTable('storage_inventory_objects') || ! Schema::hasTable('storage_cleanup_plan_items')) {
            throw new \RuntimeException('Object deletion refused: the reconciled storage inventory is not installed.');
        }
        $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
        $inventoryObject = StorageInventoryObject::query()
            ->where('object_hash', hash('sha256', $disk.'|'.$bucket.'|'.$key))
            ->where('classification', 'orphan_confirmed')
            ->whereNull('missing_since')
            ->first();
        if (! $inventoryObject) {
            throw new \RuntimeException('Object deletion refused: this object has not been explicitly confirmed as an orphan.');
        }
        if ($inventoryObject->duplicate_evidence === 'sha256') {
            $retainedDuplicate = StorageInventoryObject::query()
                ->whereKeyNot($inventoryObject->id)
                ->where('content_sha256', $inventoryObject->content_sha256)
                ->where('size_bytes', $inventoryObject->size_bytes)
                ->where('duplicate_evidence', 'sha256')
                ->where('classification', '!=', 'orphan_confirmed')
                ->whereNull('missing_since')
                ->exists();
            if (! $retainedDuplicate) {
                throw new \RuntimeException('Object deletion refused: no retained SHA-256-identical replacement remains.');
            }
        }
        $approvedItem = StorageCleanupPlanItem::query()
            ->where('storage_inventory_object_id', $inventoryObject->id)
            ->where('status', 'approved')
            ->whereHas('plan', fn ($query) => $query
                ->whereIn('status', ['confirmed', 'queued'])
                ->whereNotNull('confirmed_at')
                ->where('grace_expires_at', '<=', now()))
            ->exists();
        if (! $approvedItem) {
            throw new \RuntimeException('Object deletion refused: an approved cleanup plan and completed grace period are required.');
        }

        $idempotencyKey = $idempotencyKey ?: hash('sha256', 'delete_orphan|'.$disk.'|'.$key);
        $existing = StorageActionAudit::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing?->status === 'completed') {
            return (array) $existing->after_state;
        }

        $bytes = $this->size($disk, $key);
        $audit = $existing ?: StorageActionAudit::create([
            'user_id' => $actor['user_id'] ?? null,
            'media_api_token_id' => $actor['media_api_token_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'action' => 'delete_orphan',
            'target_type' => 'storage_object',
            'target_id' => hash('sha256', $disk.'|'.$key),
            'storage_disk' => $disk,
            'storage_bucket' => config("filesystems.disks.{$disk}.bucket"),
            'object_key' => $key,
            'status' => 'pending',
            'before_state' => ['bytes' => $bytes, 'orphaned' => true],
            'confirmed_at' => now(),
        ]);

        Storage::disk($disk)->delete($key);
        if ($this->exists($disk, $key)) {
            $audit->update(['status' => 'failed', 'failure_reason' => 'Object still exists after deletion.', 'completed_at' => now()]);
            throw new \RuntimeException('Object deletion could not be verified.');
        }

        $summary = ['deleted' => [$key], 'bytes_freed' => $bytes, 'role' => 'orphan'];
        $audit->update(['status' => 'completed', 'bytes_freed' => $bytes, 'after_state' => $summary, 'completed_at' => now()]);

        return $summary;
    }

    /**
     * Delete a directly registered Portal/Contabo object without uploading it to
     * NBX first. A primary direct source needs a verified fallback unless the
     * caller explicitly confirms that playback may be disabled.
     *
     * @return array<string,mixed>
     */
    public function deleteReference(
        StorageObjectReference $reference,
        array $options = [],
        array $actor = [],
    ): array {
        $reference = $reference->fresh() ?? $reference;
        if (! $reference->is_active || $reference->deleted_at_storage) {
            return [
                'deleted' => [],
                'bytes_freed' => 0,
                'role' => $reference->media_role,
                'already_missing' => true,
            ];
        }

        $source = $reference->mediaSource;
        if ($source) {
            $this->assertNotProcessing($source);
        }
        if ($reference->is_primary && ! ($options['disable_playback'] ?? false)) {
            $replacement = StorageObjectReference::query()
                ->where('portal_sourceable_type', $reference->portal_sourceable_type)
                ->where('portal_sourceable_id', $reference->portal_sourceable_id)
                ->whereKeyNot($reference->id)
                ->where('is_active', true)
                ->where('health_status', 'healthy')
                ->whereNull('deleted_at_storage')
                ->first();
            if (! $replacement || ! $this->exists($replacement->storage_disk, $replacement->object_key)) {
                throw new \RuntimeException('Direct source deletion refused: no verified playback fallback exists.');
            }
        }

        $keys = [$reference->object_key];
        if (in_array($reference->media_role, ['hls_master', 'hls_package'], true)) {
            $prefix = app(StorageReferenceService::class)->hlsPackagePrefix($reference->object_key);
            if (! $prefix) {
                throw new \RuntimeException('HLS deletion refused: the registered master has no exact package prefix.');
            }
            $keys = $this->keysUnderPrefix($reference->storage_disk, $prefix);
            if (! in_array($reference->object_key, $keys, true)) {
                $keys[] = $reference->object_key;
            }
        }
        $keys = array_values(array_unique(array_filter($keys)));
        $idempotencyKey = is_string($options['idempotency_key'] ?? null)
            ? substr(trim((string) $options['idempotency_key']), 0, 128)
            : hash('sha256', 'storage-reference-delete|'.$reference->id.'|'.implode('|', $keys));
        $existing = StorageActionAudit::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing?->status === 'completed') {
            return (array) $existing->after_state;
        }

        $bytes = array_sum(array_map(
            fn (string $key): int => $this->size($reference->storage_disk, $key),
            $keys,
        ));
        $audit = $existing ?: StorageActionAudit::create([
            'user_id' => $actor['user_id'] ?? null,
            'media_api_token_id' => $actor['media_api_token_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'action' => 'delete_direct_reference',
            'target_type' => 'storage_object_reference',
            'target_id' => (string) $reference->id,
            'storage_disk' => $reference->storage_disk,
            'storage_bucket' => $reference->storage_bucket,
            'object_key' => $reference->object_key,
            'status' => 'pending',
            'before_state' => [
                'media_role' => $reference->media_role,
                'is_primary' => (bool) $reference->is_primary,
                'is_active' => (bool) $reference->is_active,
                'keys' => $keys,
            ],
            'confirmed_at' => now(),
        ]);

        try {
            $this->notifyReferencePortal($reference, $keys, 'storage.deletion_planned', 'planned');
            $this->deleteAndVerify($reference->storage_disk, $keys);

            DB::transaction(function () use ($reference, $source): void {
                $metadata = (array) ($reference->metadata ?? []);
                $metadata['deleted_role'] = $reference->media_role;
                $reference->forceFill([
                    'is_active' => false,
                    'is_primary' => false,
                    'health_status' => 'unreachable',
                    'deleted_at_storage' => now(),
                    'metadata' => $metadata,
                ])->save();

                if ($source) {
                    $source->forceFill([
                        'is_active' => false,
                        'status' => 'failed',
                        'failure_reason' => 'Direct object intentionally deleted from storage.',
                    ])->save();
                }
            });

            $this->notifyReferencePortal($reference->fresh() ?? $reference, $keys, 'storage.object_deleted', 'deleted');
            $summary = [
                'deleted' => $keys,
                'bytes_freed' => $bytes,
                'role' => $reference->media_role,
                'reference_id' => $reference->id,
            ];
            $audit->update([
                'status' => 'completed',
                'bytes_freed' => $bytes,
                'after_state' => $summary,
                'completed_at' => now(),
            ]);

            return $summary;
        } catch (\Throwable $exception) {
            $audit->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function deleteOriginal(MediaSource $source, string $idempotencyKey): array
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $artifact = (array) ($metadata['nbx']['final_artifacts']['original'] ?? []);
        $bytes = (int) ($artifact['bytes'] ?? 0);
        $key = (string) ($artifact['key'] ?? '');
        app(NbxEngineService::class)->deleteOriginal($source, $idempotencyKey);
        $this->markReferencesDeleted($source, [$key], 'source_original');
        $this->notifyPortal($source, [$key], 'source_original', 'storage.original_deleted', 'deleted');

        return ['deleted' => array_values(array_filter([$key])), 'bytes_freed' => $bytes, 'role' => 'original'];
    }

    private function deleteFaststart(MediaSource $source, array $artifacts, array $options): array
    {
        $faststart = (array) ($artifacts['faststart'] ?? []);
        $hls = (array) ($artifacts['hls_master'] ?? []);
        $this->assertVerifiedReplacement($hls, 'Fast Start deletion refused: verified HLS is not available.');
        $requested = (array) (($source->source_metadata ?? [])['nbx']['requested'] ?? []);
        if (($requested['allow_downloads'] ?? false) && ! ($options['disable_downloads'] ?? false)) {
            throw new \RuntimeException('Fast Start deletion refused: it is still the active download asset. Confirm disabling downloads.');
        }

        $disk = (string) ($faststart['disk'] ?? '');
        $key = (string) ($faststart['key'] ?? '');
        if ($disk === '' || $key === '') {
            return ['deleted' => [], 'bytes_freed' => 0, 'role' => 'faststart', 'already_missing' => true];
        }
        $bytes = (int) ($faststart['bytes'] ?? $this->size($disk, $key));
        $this->deleteAndVerify($disk, [$key]);

        $this->updateArtifacts($source, function (array &$nbx) use ($options): void {
            unset($nbx['final_artifacts']['faststart']);
            if ($options['disable_downloads'] ?? false) {
                $nbx['requested']['allow_downloads'] = false;
            }
            $nbx['status'] = 'completed';
        }, ['playback_type' => 'hls']);
        $this->markReferencesDeleted($source, [$key], 'faststart_mp4');
        $this->notifyPortal($source, [$key], 'faststart_mp4', 'storage.faststart_deleted', 'deleted');

        return ['deleted' => [$key], 'bytes_freed' => $bytes, 'role' => 'faststart'];
    }

    private function deleteHls(MediaSource $source, array $artifacts, array $options): array
    {
        $faststart = (array) ($artifacts['faststart'] ?? []);
        if (! ($options['disable_playback'] ?? false)) {
            $this->assertVerifiedReplacement($faststart, 'HLS deletion refused: verified Fast Start MP4 is not available.');
        }
        $master = (array) ($artifacts['hls_master'] ?? []);
        $disk = (string) ($master['disk'] ?? '');
        $masterKey = (string) ($master['key'] ?? '');
        if ($disk === '' || $masterKey === '') {
            return ['deleted' => [], 'bytes_freed' => 0, 'role' => 'hls', 'already_missing' => true];
        }
        $prefix = app(StorageReferenceService::class)->hlsPackagePrefix($masterKey);
        if (! $prefix) {
            throw new \RuntimeException('HLS deletion refused: the persisted master key has no exact HLS package prefix.');
        }

        $keys = $this->keysUnderPrefix($disk, $prefix);
        if (! in_array($masterKey, $keys, true)) {
            $keys[] = $masterKey;
        }
        $bytes = array_sum(array_map(fn (string $key): int => $this->size($disk, $key), $keys));
        $this->deleteAndVerify($disk, $keys);

        $this->updateArtifacts($source, function (array &$nbx): void {
            unset($nbx['final_artifacts']['hls_master'], $nbx['final_artifacts']['qualities']);
            $nbx['requested']['allow_hls_streaming'] = false;
            $nbx['requested']['hls'] = ['480p' => false, '720p' => false, '1080p' => false];
            $nbx['status'] = isset($nbx['final_artifacts']['faststart']) ? 'partially_completed' : 'destroyed';
        }, [
            'playback_type' => 'mp4',
            'hls_master_path' => null,
            'qualities_json' => [],
            'is_active' => ! ($options['disable_playback'] ?? false),
        ]);
        $this->markReferencesDeleted($source, $keys, 'hls_master');
        $this->notifyPortal($source, $keys, 'hls_master', 'storage.hls_deleted', 'deleted');

        return ['deleted' => $keys, 'bytes_freed' => $bytes, 'role' => 'hls', 'package_prefix' => $prefix];
    }

    private function deleteAsset(MediaSource $source, array $artifacts, array $options): array
    {
        if (! ($options['disable_playback'] ?? false)) {
            throw new \RuntimeException('Complete asset deletion requires explicit playback disable confirmation.');
        }

        $targets = [];
        foreach (['original', 'faststart'] as $role) {
            $artifact = (array) ($artifacts[$role] ?? []);
            if (! empty($artifact['disk']) && ! empty($artifact['key'])) {
                $targets[(string) $artifact['disk']][] = (string) $artifact['key'];
            }
        }
        $hls = (array) ($artifacts['hls_master'] ?? []);
        if (! empty($hls['disk']) && ! empty($hls['key'])) {
            $prefix = app(StorageReferenceService::class)->hlsPackagePrefix((string) $hls['key']);
            if ($prefix) {
                $targets[(string) $hls['disk']] = array_merge(
                    $targets[(string) $hls['disk']] ?? [],
                    $this->keysUnderPrefix((string) $hls['disk'], $prefix),
                );
            }
        }

        $bytes = 0;
        $deleted = [];
        foreach ($targets as $disk => $keys) {
            $keys = array_values(array_unique($keys));
            $bytes += array_sum(array_map(fn (string $key): int => $this->size($disk, $key), $keys));
            $this->deleteAndVerify($disk, $keys);
            array_push($deleted, ...$keys);
        }

        $this->updateArtifacts($source, function (array &$nbx): void {
            $nbx['final_artifacts'] = [];
            $nbx['status'] = 'destroyed';
        }, [
            'status' => 'failed',
            'is_active' => false,
            'playback_type' => 'mp4',
            'hls_master_path' => null,
            'qualities_json' => [],
            'failure_reason' => 'Asset intentionally deleted from object storage.',
        ]);
        $this->markReferencesDeleted($source, $deleted, 'asset');
        $this->notifyPortal($source, $deleted, 'asset', 'storage.asset_deleted', 'deleted');

        return ['deleted' => $deleted, 'bytes_freed' => $bytes, 'role' => 'asset'];
    }

    private function assertNotProcessing(MediaSource $source): void
    {
        if (in_array($source->status, ['pending', 'downloading', 'processing'], true)
            || in_array($source->optimize_status, ['pending', 'processing'], true)
        ) {
            throw new \RuntimeException('Deletion refused: this source has an active processing job.');
        }
    }

    private function assertSourceDeletionAllowed(
        MediaSource $source,
        array $artifacts,
        string $role,
        array $options,
    ): void {
        if ($role === 'original') {
            $original = (array) ($artifacts['original'] ?? []);
            if ($original === []) {
                return;
            }
            $faststart = (array) ($artifacts['faststart'] ?? []);
            $this->assertVerifiedReplacement(
                $faststart,
                'Original deletion refused: a verified optimized MP4 is not available.',
            );
            if (($original['disk'] ?? null) === ($faststart['disk'] ?? null)
                && ($original['key'] ?? null) === ($faststart['key'] ?? null)
            ) {
                throw new \RuntimeException('Original deletion refused: original and optimized keys are identical.');
            }

            return;
        }

        if ($role === 'faststart') {
            if ((array) ($artifacts['faststart'] ?? []) === []) {
                return;
            }
            $this->assertVerifiedReplacement(
                (array) ($artifacts['hls_master'] ?? []),
                'Fast Start deletion refused: verified HLS is not available.',
            );
            $requested = (array) (($source->source_metadata ?? [])['nbx']['requested'] ?? []);
            if (($requested['allow_downloads'] ?? false) && ! ($options['disable_downloads'] ?? false)) {
                throw new \RuntimeException('Fast Start deletion refused: it is still the active download asset. Confirm disabling downloads.');
            }

            return;
        }

        if ($role === 'hls') {
            $master = (array) ($artifacts['hls_master'] ?? []);
            if ($master === []) {
                return;
            }
            if (! ($options['disable_playback'] ?? false)) {
                $this->assertVerifiedReplacement(
                    (array) ($artifacts['faststart'] ?? []),
                    'HLS deletion refused: verified Fast Start MP4 is not available.',
                );
            }
            if (! app(StorageReferenceService::class)->hlsPackagePrefix((string) ($master['key'] ?? ''))) {
                throw new \RuntimeException('HLS deletion refused: the persisted master key has no exact HLS package prefix.');
            }

            return;
        }

        if ($role === 'asset' && ! ($options['disable_playback'] ?? false)) {
            throw new \RuntimeException('Complete asset deletion requires explicit playback disable confirmation.');
        }
    }

    private function assertVerifiedReplacement(array $artifact, string $message): void
    {
        if (! ($artifact['verified'] ?? false)
            || empty($artifact['disk'])
            || empty($artifact['key'])
            || ! app(VerifiedObjectStorageService::class)->verify(
                (string) $artifact['disk'],
                (string) $artifact['key'],
                (int) ($artifact['bytes'] ?? 0),
            )
        ) {
            throw new \RuntimeException($message);
        }
    }

    private function updateArtifacts(MediaSource $source, callable $mutateNbx, array $sourceChanges = []): void
    {
        DB::transaction(function () use ($source, $mutateNbx, $sourceChanges): void {
            $fresh = $source->fresh() ?? $source;
            $metadata = (array) ($fresh->source_metadata ?? []);
            $nbx = (array) ($metadata['nbx'] ?? []);
            $mutateNbx($nbx);
            $metadata['nbx'] = $nbx;
            $fresh->update(array_merge($sourceChanges, ['source_metadata' => $metadata]));
        });
    }

    private function notifyPortal(
        MediaSource $source,
        array $keys,
        string $role,
        string $event,
        string $phase,
    ): void {
        $referenceQuery = StorageObjectReference::query()
            ->where('media_source_id', $source->id)
            ->whereNotNull('portal_source_id');
        if ($keys !== []) {
            $referenceQuery->whereIn('object_key', $keys);
        }
        $references = $referenceQuery->get();
        if ($references->isEmpty()) {
            if ($phase === 'deleted') {
                app(NbxWebhookDispatcher::class)->dispatch($source->fresh() ?? $source, $event, [
                    'media_role' => $role,
                    'deleted_keys' => $keys,
                ]);
            }

            return;
        }
        if (! app(NbxPortalStorageNotifier::class)->isConfigured()) {
            throw new \RuntimeException('Deletion refused: Portal references exist but the storage reconciliation callback is not configured.');
        }

        foreach ($references as $reference) {
            $this->notifyReferencePortal($reference, $keys, $event, $phase);
        }
    }

    private function markReferencesDeleted(MediaSource $source, array $keys, string $role): void
    {
        $references = StorageObjectReference::query()
            ->where(function ($query) use ($source, $keys): void {
                $query->where('media_source_id', $source->id);
                if ($keys !== []) {
                    $query->orWhereIn('object_key', $keys);
                }
            })
            ->get();
        foreach ($references as $reference) {
            $metadata = (array) ($reference->metadata ?? []);
            $metadata['deleted_role'] = $role;
            $reference->forceFill([
                'is_active' => false,
                'is_primary' => false,
                'health_status' => 'unreachable',
                'deleted_at_storage' => now(),
                'metadata' => $metadata,
            ])->save();
        }
    }

    private function notifyReferencePortal(
        StorageObjectReference $reference,
        array $keys,
        string $event,
        string $phase,
    ): void {
        if (! $reference->portal_source_id) {
            return;
        }
        if (! app(NbxPortalStorageNotifier::class)->isConfigured()) {
            throw new \RuntimeException('Deletion refused: Portal references exist but the storage reconciliation callback is not configured.');
        }

        app(NbxPortalStorageNotifier::class)->send([
            'event' => $event,
            'phase' => $phase,
            'portal_source_id' => $reference->portal_source_id,
            'portal_sourceable_type' => $reference->portal_sourceable_type,
            'portal_sourceable_id' => $reference->portal_sourceable_id,
            'nbx_asset_id' => $reference->media_asset_id,
            'nbx_source_id' => $reference->media_source_id,
            'media_role' => $reference->media_role,
            'deleted_object_keys' => $keys,
            'deleted_at' => $phase === 'deleted' ? now()->toIso8601String() : null,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function artifactKeysForRole(MediaSource $source, array $artifacts, string $role): array
    {
        if ($role === 'original') {
            return array_values(array_filter([
                $artifacts['original']['key'] ?? $source->original_storage_path,
            ]));
        }
        if ($role === 'faststart') {
            return array_values(array_filter([
                $artifacts['faststart']['key'] ?? $source->optimized_path ?? $source->storage_path,
            ]));
        }
        if ($role === 'hls') {
            $master = (string) ($artifacts['hls_master']['key'] ?? $source->hls_master_path ?? '');
            $disk = (string) ($artifacts['hls_master']['disk'] ?? $source->storage_disk ?? '');
            $prefix = app(StorageReferenceService::class)->hlsPackagePrefix($master);

            return $disk !== '' && $prefix ? $this->keysUnderPrefix($disk, $prefix) : array_values(array_filter([$master]));
        }

        $keys = [];
        foreach (['original', 'faststart', 'hls_master'] as $artifactRole) {
            if (is_array($artifacts[$artifactRole] ?? null) && ! empty($artifacts[$artifactRole]['key'])) {
                $keys[] = (string) $artifacts[$artifactRole]['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    private function deleteAndVerify(string $disk, array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if ($keys === []) {
            return;
        }
        Storage::disk($disk)->delete($keys);
        foreach ($keys as $key) {
            if ($this->exists($disk, $key)) {
                throw new \RuntimeException('Storage deletion could not be verified for '.basename($key).'.');
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function keysUnderPrefix(string $disk, string $prefix): array
    {
        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            return Storage::disk($disk)->allFiles($prefix);
        }

        $client = $storage->getClient();
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");
        $keys = [];
        $cursor = null;
        do {
            $params = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
            if ($cursor) {
                $params['ContinuationToken'] = $cursor;
            }
            $result = $client->listObjectsV2($params);
            foreach ((array) ($result['Contents'] ?? []) as $object) {
                if (isset($object['Key'])) {
                    $keys[] = (string) $object['Key'];
                }
            }
            $cursor = isset($result['NextContinuationToken']) ? (string) $result['NextContinuationToken'] : null;
        } while ($cursor);

        return $keys;
    }

    private function exists(string $disk, string $key): bool
    {
        try {
            return Storage::disk($disk)->exists($key);
        } catch (\Throwable) {
            return true;
        }
    }

    private function size(string $disk, string $key): int
    {
        try {
            return Storage::disk($disk)->exists($key) ? (int) Storage::disk($disk)->size($key) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function idempotencyKey(mixed $provided, MediaSource $source, string $role): string
    {
        return is_string($provided) && trim($provided) !== ''
            ? substr(trim($provided), 0, 128)
            : hash('sha256', "storage-delete|{$source->id}|{$role}|{$source->processing_revision}");
    }

    private function safeAuditState(MediaSource $source, array $artifacts): array
    {
        return [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'status' => $source->status,
            'is_active' => (bool) $source->is_active,
            'artifacts' => collect($artifacts)->map(function (mixed $artifact): mixed {
                if (! is_array($artifact)) {
                    return $artifact;
                }
                unset($artifact['url']);

                return $artifact;
            })->all(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Models\StorageInventoryObject;
use App\Services\Storage\StorageTargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconstructs media_assets/media_sources rows for NBX-final storage objects
 * that survive in Contabo but have no matching database row (i.e. the NBX
 * database was lost and redeployed empty). This never touches storage — it
 * only reads what nbx:storage-inventory-all-targets already recorded in
 * storage_inventory_objects, then reuses NbxEngineService's own trusted
 * artifact-verification logic (reconcilePublishedArtifacts()) to populate
 * each recreated source, instead of re-deriving that logic here.
 *
 * The object key scheme (NbxEngineService::finalObjectKey()) carries no
 * asset/source identity, only a job id — so every recreated row gets a
 * freshly minted media_asset_id, and external_job_id is the one field that
 * lets Portal's existing sync()/discover flow reconnect to it afterwards.
 */
class CatalogRebuildService
{
    public function __construct(
        private readonly NbxEngineService $nbxEngine,
        private readonly StorageTargetRegistry $targets,
    ) {
    }

    /**
     * @return array{created:int,skipped:int,needs_review:int,groups:array<int,array<string,mixed>>}
     */
    public function rebuild(bool $dryRun = false): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'needs_review' => 0, 'groups' => []];

        foreach ($this->finalArtifactGroups() as $group) {
            $existing = MediaSource::query()->where('external_job_id', $group['job_id'])->first();
            if ($existing) {
                $summary['skipped']++;
                $summary['groups'][] = array_merge($group, [
                    'outcome' => 'already_exists',
                    'media_source_id' => $existing->id,
                    'media_asset_id' => $existing->media_asset_id,
                ]);

                continue;
            }

            if ($dryRun) {
                $summary['groups'][] = array_merge($group, ['outcome' => 'would_create']);

                continue;
            }

            try {
                [$source, $outcome] = $this->materialize($group);
                $summary[$outcome === 'needs_review' ? 'needs_review' : 'created']++;
                $summary['groups'][] = array_merge($group, [
                    'outcome' => $outcome,
                    'media_source_id' => $source->id,
                    'media_asset_id' => $source->media_asset_id,
                ]);
            } catch (\Throwable $exception) {
                // One bad job folder (a storage read error, a malformed
                // artifact, etc.) must never abort the other 200+ groups in
                // the same run — this is a disaster-recovery batch, not an
                // all-or-nothing transaction.
                $summary['needs_review']++;
                $summary['groups'][] = array_merge($group, [
                    'outcome' => 'error',
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $group
     * @return array{0:MediaSource,1:string}
     */
    private function materialize(array $group): array
    {
        return DB::transaction(function () use ($group): array {
            $asset = new MediaAsset();
            // Set before fill()/save() so HasUuids' "already set?" check on
            // the creating event leaves this explicit id alone. `id` is
            // intentionally not in MediaAsset::$fillable, so this must be a
            // direct property assignment rather than passed to create()/fill().
            $asset->id = (string) Str::uuid7($group['earliest_last_modified'] ?? now());
            $asset->fill([
                'type' => 'generic',
                'title' => 'Recovered NBX job '.$group['job_id'],
                'description' => 'Reconstructed from Contabo storage after the NBX database was lost. '
                    .'The original title, description, and movie/episode linkage are not recoverable from '
                    .'the storage object key alone and must be re-attached manually (or via Portal resync).',
                'status' => 'importing',
                'visibility' => 'unlisted',
            ]);
            $asset->save();

            /** @var MediaSource $source */
            $source = $asset->sources()->create([
                'source_type' => 'remote_fetch',
                'external_job_id' => $group['job_id'],
                'storage_target_key' => $group['target_key'],
                'storage_disk' => $group['storage_disk'],
                'status' => 'processing',
                'is_active' => false,
                'source_metadata' => [
                    'nbx' => ['storage_target' => $group['target_key']],
                    'recovered_from_storage' => true,
                    'recovered_at' => now()->toIso8601String(),
                ],
            ]);

            $reconciled = $this->nbxEngine->reconcilePublishedArtifacts($source);
            $hasPlayable = (bool) data_get($reconciled->source_metadata, 'nbx.storage_verified', false);

            $asset->update(['status' => $hasPlayable ? 'ready' : 'failed']);

            StorageInventoryObject::query()
                ->where('logical_asset_key', $group['logical_asset_key'])
                ->update(['media_asset_id' => $asset->id, 'media_source_id' => $reconciled->id]);

            return [$reconciled, $hasPlayable ? 'created' : 'needs_review'];
        });
    }

    /**
     * Groups orphaned nbx_final inventory rows by job-id folder
     * (logical_asset_key = "{prefix}/nbx/{job}").
     *
     * @return array<int,array<string,mixed>>
     */
    private function finalArtifactGroups(): array
    {
        $rows = StorageInventoryObject::query()
            ->where('storage_layout', 'nbx_final')
            ->where('classification', 'nbx_unresolved')
            ->whereNull('media_source_id')
            ->orderBy('object_last_modified_at')
            ->get(['logical_asset_key', 'storage_disk', 'storage_bucket', 'object_last_modified_at']);

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row->logical_asset_key;
            $jobId = Str::afterLast($key, '/');
            if ($jobId === '' || $jobId === $key) {
                // Malformed/unexpected key shape — skip rather than guess.
                continue;
            }

            $groups[$key] ??= [
                'logical_asset_key' => $key,
                'job_id' => $jobId,
                'storage_disk' => (string) $row->storage_disk,
                'storage_bucket' => (string) $row->storage_bucket,
                'target_key' => $this->targetKeyForDisk((string) $row->storage_disk),
                'earliest_last_modified' => $row->object_last_modified_at,
            ];
        }

        return array_values($groups);
    }

    private function targetKeyForDisk(string $disk): string
    {
        foreach ($this->targets->all() as $target) {
            if ($target->disk === $disk) {
                return $target->key;
            }
        }

        return $this->targets->legacyKey();
    }
}

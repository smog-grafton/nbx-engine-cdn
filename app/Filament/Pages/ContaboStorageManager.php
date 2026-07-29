<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StorageCleanupPlanResource;
use App\Models\StorageCleanupPlan;
use App\Models\StorageCleanupPlanItem;
use App\Models\StorageInventoryObject;
use App\Models\StorageInventoryRun;
use App\Models\User;
use App\Services\StorageInventoryService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ContaboStorageManager extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Storage';

    protected static ?string $navigationLabel = 'Contabo inventory';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.contabo-storage-manager';

    public string $prefix = '';

    public string $search = '';

    public string $role = 'all';

    public string $extension = 'all';

    public string $association = 'all';

    public int $limit = 50;

    public int $page = 1;

    /** @var array<int,array<string,mixed>> */
    public array $objects = [];

    public int $totalGroups = 0;

    public int $totalPages = 1;

    public ?string $loadError = null;

    /** @var array<string,mixed> */
    public array $summary = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $breakdowns = [];

    /** @var array<string,mixed>|null */
    public ?array $latestRun = null;

    /** @var array<int,array<string,mixed>> */
    public array $plans = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageStorage('storage.view');
    }

    public function mount(): void
    {
        $this->loadObjects();
    }

    public function applyFilters(): void
    {
        $this->page = 1;
        $this->loadObjects();
    }

    public function refreshObjects(): void
    {
        $this->loadObjects();
    }

    public function startInventory(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canManageStorage('storage.reconcile'), 403);
        try {
            $run = app(StorageInventoryService::class)->queue($this->prefix);
            Notification::make()
                ->success()
                ->title('Read-only inventory queued')
                ->body("Run #{$run->id} will index object metadata without changing Contabo.")
                ->send();
            $this->loadObjects();
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Inventory could not be queued')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }

    public function nextPage(): void
    {
        if ($this->page >= $this->totalPages) {
            return;
        }
        $this->page++;
        $this->loadObjects();
    }

    public function previousPage(): void
    {
        if ($this->page <= 1) {
            return;
        }
        $this->page--;
        $this->loadObjects();
    }

    public function requestCleanupReview(string $logicalAssetKey): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canManageStorage('storage.delete.orphan'), 403);
        try {
            $plan = app(StorageInventoryService::class)->createCleanupReview($logicalAssetKey, $user->id);
            Notification::make()
                ->success()
                ->title('Cleanup review created — nothing deleted')
                ->body("Plan #{$plan->id} contains {$plan->object_count} objects and has a seven-day review window.")
                ->send();
            $this->loadObjects();
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Review plan could not be created')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function loadObjects(): void
    {
        if (! Schema::hasTable('storage_inventory_objects')) {
            $this->objects = [];
            $this->loadError = 'Storage inventory tables are not installed. Run the pending NBX migrations, then start a read-only inventory.';

            return;
        }

        try {
            $query = $this->filteredQuery();
            $this->totalGroups = (clone $query)->distinct()->count('logical_asset_key');
            $this->totalPages = max(1, (int) ceil($this->totalGroups / $this->limit));
            $this->page = max(1, min($this->page, $this->totalPages));
            $rows = $query
                ->selectRaw('logical_asset_key')
                ->selectRaw('MIN(storage_layout) AS storage_layout')
                ->selectRaw('MIN(classification) AS classification')
                ->selectRaw('MIN(confidence) AS confidence')
                ->selectRaw('COUNT(*) AS object_count')
                ->selectRaw('SUM(size_bytes) AS size_bytes')
                ->selectRaw('GROUP_CONCAT(DISTINCT media_role) AS media_roles')
                ->selectRaw('MAX(media_asset_id) AS media_asset_id')
                ->selectRaw('MAX(media_source_id) AS media_source_id')
                ->selectRaw('MAX(portal_sourceable_type) AS portal_sourceable_type')
                ->selectRaw('MAX(portal_sourceable_id) AS portal_sourceable_id')
                ->selectRaw('MAX(object_last_modified_at) AS last_modified')
                ->selectRaw("SUM(CASE WHEN media_role LIKE 'hls_%' THEN 1 ELSE 0 END) AS hls_object_count")
                ->groupBy('logical_asset_key')
                ->orderByDesc('last_modified')
                ->offset(($this->page - 1) * $this->limit)
                ->limit($this->limit)
                ->get();
            $this->objects = $rows->map(fn ($row): array => [
                'logical_asset_key' => $row->logical_asset_key,
                'storage_layout' => $row->storage_layout,
                'classification' => $row->classification,
                'confidence' => $row->confidence,
                'object_count' => (int) $row->object_count,
                'hls_object_count' => (int) $row->hls_object_count,
                'size_bytes' => (int) $row->size_bytes,
                'media_roles' => array_values(array_filter(explode(',', (string) $row->media_roles))),
                'media_asset_id' => $row->media_asset_id,
                'media_source_id' => $row->media_source_id,
                'portal_sourceable_type' => $row->portal_sourceable_type,
                'portal_sourceable_id' => $row->portal_sourceable_id,
                'last_modified' => $row->last_modified,
            ])->all();

            $all = StorageInventoryObject::query()->whereNull('missing_since');
            $verifiedDuplicateGroups = (clone $all)
                ->where('duplicate_evidence', 'sha256')
                ->whereNotNull('content_sha256')
                ->select(['content_sha256', 'size_bytes'])
                ->selectRaw('COUNT(*) AS matching_objects')
                ->groupBy('content_sha256', 'size_bytes')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            $this->summary = [
                'objects' => (clone $all)->count(),
                'packages' => (clone $all)->distinct()->count('logical_asset_key'),
                'bytes' => (int) (clone $all)->sum('size_bytes'),
                'hls_objects' => (clone $all)->where('media_role', 'like', 'hls_%')->count(),
                'hls_bytes' => (int) (clone $all)->where('media_role', 'like', 'hls_%')->sum('size_bytes'),
                'unresolved_objects' => (clone $all)->whereIn('classification', ['unresolved', 'nbx_unresolved'])->count(),
                'unresolved_bytes' => (int) (clone $all)->whereIn('classification', ['unresolved', 'nbx_unresolved'])->sum('size_bytes'),
                'duplicate_candidates' => (clone $all)->where('is_duplicate_candidate', true)->count(),
                'duplicate_candidate_bytes' => (int) (clone $all)->where('is_duplicate_candidate', true)->sum('size_bytes'),
                'verified_duplicates' => (clone $all)->where('duplicate_evidence', 'sha256')->count(),
                'verified_duplicate_bytes' => (int) (clone $all)->where('duplicate_evidence', 'sha256')->sum('size_bytes'),
                'verified_redundant_bytes' => (int) $verifiedDuplicateGroups->sum(
                    fn ($group): int => (max(0, (int) $group->matching_objects - 1) * (int) $group->size_bytes)
                ),
                'approved_reclaim_bytes' => (int) StorageCleanupPlanItem::query()
                    ->join('storage_cleanup_plans', 'storage_cleanup_plans.id', '=', 'storage_cleanup_plan_items.storage_cleanup_plan_id')
                    ->join('storage_inventory_objects', 'storage_inventory_objects.id', '=', 'storage_cleanup_plan_items.storage_inventory_object_id')
                    ->where('storage_cleanup_plan_items.status', 'approved')
                    ->where('storage_cleanup_plans.status', 'confirmed')
                    ->where('storage_cleanup_plans.grace_expires_at', '<=', now())
                    ->where('storage_inventory_objects.classification', 'orphan_confirmed')
                    ->sum('storage_inventory_objects.size_bytes'),
            ];
            $this->breakdowns = [
                'role' => $this->breakdown('media_role'),
                'layout' => $this->breakdown('storage_layout'),
                'lifecycle' => $this->breakdown('classification'),
            ];
            $latest = StorageInventoryRun::query()->latest('id')->first();
            $this->latestRun = $latest ? [
                'id' => $latest->id,
                'prefix' => $latest->prefix,
                'status' => $latest->status,
                'object_count' => $latest->object_count,
                'total_bytes' => $latest->total_bytes,
                'pages_scanned' => $latest->pages_scanned,
                'failure_reason' => $latest->failure_reason,
                'started_at' => $latest->started_at?->toIso8601String(),
                'completed_at' => $latest->completed_at?->toIso8601String(),
            ] : null;
            $this->plans = StorageCleanupPlan::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (StorageCleanupPlan $plan): array => [
                    'id' => $plan->id,
                    'status' => $plan->status,
                    'logical_asset_key' => $plan->logical_asset_key,
                    'object_count' => $plan->object_count,
                    'total_bytes' => $plan->total_bytes,
                    'risk_level' => $plan->risk_level,
                    'grace_expires_at' => $plan->grace_expires_at?->toIso8601String(),
                    'url' => StorageCleanupPlanResource::getUrl('view', ['record' => $plan]),
                ])->all();
            $this->loadError = null;
        } catch (\Throwable $exception) {
            report($exception);
            $this->objects = [];
            $this->loadError = $exception->getMessage();
        }
    }

    private function filteredQuery(): Builder
    {
        return StorageInventoryObject::query()
            ->whereNull('missing_since')
            ->when($this->prefix !== '', fn (Builder $query) => $query->where('object_key', 'like', trim($this->prefix, '/').'%'))
            ->when($this->search !== '', fn (Builder $query) => $query->where('object_key', 'like', '%'.trim($this->search).'%'))
            ->when($this->role !== 'all', function (Builder $query): void {
                $this->role === 'hls_package'
                    ? $query->where('media_role', 'like', 'hls_%')
                    : $query->where('media_role', $this->role);
            })
            ->when($this->extension !== 'all', fn (Builder $query) => $query->where('extension', $this->extension))
            ->when($this->association !== 'all', function (Builder $query): void {
                match ($this->association) {
                    'managed' => $query->where('classification', 'managed'),
                    'portal' => $query->where(function (Builder $nested): void {
                        $nested->whereNotNull('portal_source_id')
                            ->orWhere('classification', 'portal_candidate');
                    }),
                    'processing' => $query->where('classification', 'active_processing'),
                    'failed_residue' => $query->where('classification', 'failed_residue_candidate'),
                    'duplicates' => $query->where('is_duplicate_candidate', true),
                    'unresolved' => $query->whereIn('classification', ['unresolved', 'nbx_unresolved']),
                    default => null,
                };
            });
    }

    /**
     * @return array<int,array{label:string,objects:int,bytes:int}>
     */
    private function breakdown(string $column): array
    {
        $allowed = ['media_role', 'storage_layout', 'classification'];
        if (! in_array($column, $allowed, true)) {
            return [];
        }

        return StorageInventoryObject::query()
            ->whereNull('missing_since')
            ->selectRaw($column.' AS label')
            ->selectRaw('COUNT(*) AS objects')
            ->selectRaw('SUM(size_bytes) AS bytes')
            ->groupBy($column)
            ->orderByDesc('bytes')
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->label,
                'objects' => (int) $row->objects,
                'bytes' => (int) $row->bytes,
            ])
            ->all();
    }
}

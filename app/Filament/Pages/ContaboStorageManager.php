<?php

namespace App\Filament\Pages;

use App\Models\MediaSource;
use App\Models\StorageActionAudit;
use App\Models\StorageObjectReference;
use App\Models\User;
use App\Services\ContaboObjectBrowserService;
use App\Services\StorageDeletionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ContaboStorageManager extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Storage';

    protected static ?string $navigationLabel = 'Contabo objects';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.contabo-storage-manager';

    public string $prefix = '';

    public string $search = '';

    public string $role = 'all';

    public string $extension = 'all';

    public string $association = 'all';

    public int $limit = 50;

    public ?string $cursor = null;

    /** @var array<int,string|null> */
    public array $cursorHistory = [];

    /** @var array<int,array<string,mixed>> */
    public array $objects = [];

    public ?string $nextCursor = null;

    public bool $isTruncated = false;

    public ?string $loadError = null;

    /** @var array<int,array<string,mixed>> */
    public array $audits = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageStorage('storage.view');
    }

    public function mount(): void
    {
        $this->prefix = trim((string) config('services.contabo_object_storage.path_prefix', 'videos'), '/');
        $this->loadObjects();
    }

    public function applyFilters(): void
    {
        $this->cursor = null;
        $this->cursorHistory = [];
        $this->loadObjects();
    }

    public function refreshObjects(): void
    {
        $this->loadObjects();
    }

    public function nextPage(): void
    {
        if (! $this->nextCursor) {
            return;
        }
        $this->cursorHistory[] = $this->cursor;
        $this->cursor = $this->nextCursor;
        $this->loadObjects();
    }

    public function previousPage(): void
    {
        if ($this->cursorHistory === []) {
            return;
        }
        $this->cursor = array_pop($this->cursorHistory);
        $this->loadObjects();
    }

    public function deleteObject(string $key): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $object = collect($this->objects)->firstWhere('key', $key);
        if (! is_array($object)) {
            Notification::make()->danger()->title('Object is no longer on this page')->send();

            return;
        }

        try {
            $actor = ['user_id' => $user->id, 'media_api_token_id' => null];
            $directReferenceId = (int) (($object['direct_reference_ids'][0] ?? 0));
            if ($directReferenceId > 0) {
                abort_unless($user->canManageStorage('storage.manage.direct'), 403);
                $reference = StorageObjectReference::query()->findOrFail($directReferenceId);
                app(StorageDeletionService::class)->deleteReference(
                    $reference,
                    ['disable_playback' => false],
                    $actor,
                );
            } elseif (! empty($object['associated_media_source_ids'])) {
                $source = MediaSource::query()->findOrFail((int) $object['associated_media_source_ids'][0]);
                $artifactRole = $this->artifactRole((string) $object['media_role']);
                if ($artifactRole === null) {
                    throw new \RuntimeException('This object role cannot be mapped to a safe artifact deletion.');
                }
                abort_unless($user->canManageStorage('storage.delete.'.$artifactRole), 403);
                app(StorageDeletionService::class)->deleteSourceArtifact(
                    $source,
                    $artifactRole,
                    [
                        'disable_downloads' => $artifactRole === 'faststart',
                        'disable_playback' => false,
                    ],
                    $actor,
                );
            } elseif ((bool) ($object['orphaned'] ?? false)) {
                abort_unless($user->canManageStorage('storage.delete.orphan'), 403);
                app(StorageDeletionService::class)->deleteConfirmedOrphan(
                    (string) $object['disk'],
                    (string) $object['key'],
                    $actor,
                );
            } else {
                throw new \RuntimeException('This object has unresolved associations and cannot be safely deleted.');
            }

            Notification::make()
                ->success()
                ->title('Storage deletion verified')
                ->body(basename($key).' was removed and the action was audited.')
                ->send();
            $this->loadObjects();
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Deletion refused')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function loadObjects(): void
    {
        try {
            $result = app(ContaboObjectBrowserService::class)->list(
                $this->prefix,
                $this->cursor,
                $this->limit,
                $this->search !== '' ? $this->search : null,
                $this->role,
                $this->extension,
                $this->association,
            );
            $this->objects = $result['objects'];
            $this->nextCursor = $result['next_cursor'];
            $this->isTruncated = $result['is_truncated'];
            $this->loadError = null;
        } catch (\Throwable $exception) {
            report($exception);
            $this->objects = [];
            $this->nextCursor = null;
            $this->isTruncated = false;
            $this->loadError = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'Contabo storage could not be loaded. Check the NBX logs and storage configuration.';
        }

        $this->audits = StorageActionAudit::query()
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (StorageActionAudit $audit): array => [
                'action' => $audit->action,
                'status' => $audit->status,
                'bytes_freed' => $audit->bytes_freed,
                'failure_reason' => $audit->failure_reason,
                'completed_at' => $audit->completed_at?->toIso8601String(),
            ])
            ->all();
    }

    private function artifactRole(string $role): ?string
    {
        return match ($role) {
            'source_original' => 'original',
            'faststart_mp4', 'playback_progressive', 'download_asset' => 'faststart',
            'hls_master', 'hls_variant', 'hls_segment', 'hls_asset', 'hls_package' => 'hls',
            default => null,
        };
    }
}

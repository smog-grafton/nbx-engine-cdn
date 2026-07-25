<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaSource;
use App\Models\StorageActionAudit;
use App\Models\StorageObjectReference;
use App\Services\ContaboObjectBrowserService;
use App\Services\StorageDeletionService;
use App\Services\StorageReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageObjectController extends Controller
{
    public function index(Request $request, ContaboObjectBrowserService $browser): JsonResponse
    {
        $validated = $request->validate([
            'prefix' => ['nullable', 'string', 'max:1024'],
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:191'],
            'role' => ['nullable', 'string', 'max:48'],
            'extension' => ['nullable', 'string', 'max:16'],
            'association' => ['nullable', 'in:all,portal,nbx,orphan'],
        ]);

        return $this->success($browser->list(
            (string) ($validated['prefix'] ?? ''),
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? config('nbx.storage_browser_page_size', 50)),
            $validated['search'] ?? null,
            $validated['role'] ?? null,
            $validated['extension'] ?? null,
            $validated['association'] ?? null,
        ));
    }

    public function register(Request $request, StorageReferenceService $references): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'portal_source_id' => ['required', 'integer', 'min:1'],
            'portal_sourceable_type' => ['required', 'string', 'max:191'],
            'portal_sourceable_id' => ['required', 'integer', 'min:1'],
            'storage_disk' => ['nullable', 'string', 'max:64'],
            'storage_bucket' => ['nullable', 'string', 'max:191'],
            'object_key' => ['required', 'string', 'max:2048'],
            'object_url' => ['required', 'url:http,https', 'max:4096'],
            'media_role' => ['nullable', 'string', 'max:48'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'health_status' => ['nullable', 'in:unknown,healthy,degraded,unreachable,invalid'],
            'import_into_nbx' => ['sometimes', 'boolean'],
        ]);

        try {
            $reference = $references->register($validated);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->failure('The storage reference could not be registered.', 503);
        }

        return $this->success([
            'id' => $reference->id,
            'media_asset_id' => $reference->media_asset_id,
            'media_source_id' => $reference->media_source_id,
            'portal_source_id' => $reference->portal_source_id,
            'object_key' => $reference->object_key,
            'media_role' => $reference->media_role,
            'registered_without_upload' => true,
        ], 201);
    }

    public function destroyArtifact(
        Request $request,
        MediaSource $source,
        string $role,
        StorageDeletionService $deletions,
    ): JsonResponse {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:96'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'disable_downloads' => ['sometimes', 'boolean'],
            'disable_playback' => ['sometimes', 'boolean'],
        ]);
        $normalizedRole = strtolower(trim($role));
        $apiToken = $request->attributes->get('media_api_token');
        if ($apiToken
            && ! $apiToken->can('storage.delete')
            && ! $apiToken->can('storage.delete.'.$normalizedRole)
        ) {
            return $this->failure('The API token cannot delete this artifact role.', 403);
        }
        if (! hash_equals('DELETE '.strtoupper($normalizedRole), trim($validated['confirmation']))) {
            return $this->failure('The deletion confirmation phrase does not match.', 422);
        }

        try {
            $summary = $deletions->deleteSourceArtifact(
                $source,
                $normalizedRole,
                $validated,
                $this->actor($request),
            );

            return $this->success($summary);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->failure($exception->getMessage(), 409);
        }
    }

    public function destroyReference(
        Request $request,
        StorageObjectReference $reference,
        StorageDeletionService $deletions,
    ): JsonResponse {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:96'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'disable_playback' => ['sometimes', 'boolean'],
        ]);
        if (! hash_equals('DELETE REFERENCE '.$reference->id, trim($validated['confirmation']))) {
            return $this->failure('The deletion confirmation phrase does not match.', 422);
        }

        try {
            return $this->success($deletions->deleteReference(
                $reference,
                $validated,
                $this->actor($request),
            ));
        } catch (\RuntimeException $exception) {
            return $this->failure($exception->getMessage(), 409);
        }
    }

    public function destroyOrphan(Request $request, StorageDeletionService $deletions): JsonResponse
    {
        $validated = $request->validate([
            'storage_disk' => ['nullable', 'string', 'max:64'],
            'object_key' => ['required', 'string', 'max:2048'],
            'confirmation' => ['required', 'string', 'max:128'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $expected = 'DELETE ORPHAN '.substr(hash('sha256', $validated['object_key']), 0, 12);
        if (! hash_equals($expected, trim($validated['confirmation']))) {
            return $this->failure('The orphan deletion confirmation phrase does not match.', 422);
        }

        try {
            return $this->success($deletions->deleteConfirmedOrphan(
                (string) ($validated['storage_disk'] ?? config('services.contabo_object_storage.disk', 'contabo')),
                $validated['object_key'],
                $this->actor($request),
                $validated['idempotency_key'] ?? null,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->failure($exception->getMessage(), 409);
        }
    }

    public function audits(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->integer('limit', 50)));

        return $this->success(StorageActionAudit::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (StorageActionAudit $audit): array => [
                'id' => $audit->id,
                'action' => $audit->action,
                'target_type' => $audit->target_type,
                'target_id' => $audit->target_id,
                'object_key' => $audit->object_key,
                'bytes_freed' => $audit->bytes_freed,
                'status' => $audit->status,
                'failure_reason' => $audit->failure_reason,
                'confirmed_at' => $audit->confirmed_at?->toIso8601String(),
                'completed_at' => $audit->completed_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return array{user_id:null,media_api_token_id:int|null}
     */
    private function actor(Request $request): array
    {
        return [
            'user_id' => null,
            'media_api_token_id' => $request->attributes->get('media_api_token')?->id,
        ];
    }

    private function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'error' => null], $status);
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'error' => $message], $status);
    }
}

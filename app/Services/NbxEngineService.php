<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\Storage\AutomaticStorageSelector;
use App\Services\Storage\StorageTargetRegistry;
use App\Support\SafeRemoteMediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NbxEngineService
{
    /**
     * Resolve the raw "storage_target" a caller sent (which may be "auto",
     * a legacy "contabo" literal, a logical bucket key, or "local"/"public")
     * into a CONCRETE key at job-creation time. This is deliberately done
     * once, up front — resolving "auto" again later (at publish time) could
     * pick a different bucket mid-job and split one job's outputs across
     * two buckets, which is not allowed.
     *
     * Returns 'local'/'public' verbatim (non-Contabo storage), or a
     * concrete logical bucket key (e.g. "contabo_nbx", "contabo_nb_nbx").
     */
    private function resolveInitialStorageTarget(array $data): string
    {
        $raw = (string) ($data['storage_target'] ?? config('nbx.default_storage', 'contabo'));

        if (in_array($raw, ['local', 'public'], true)) {
            return (bool) config('nbx.allow_local_storage', true) ? $raw : 'contabo';
        }

        $registry = app(StorageTargetRegistry::class);
        $requestedKey = $registry->isAutoKey($raw) ? null : $registry->normalizeStoredKey($raw);

        $inputBytes = isset($data['size_bytes']) ? (int) $data['size_bytes']
            : (isset($data['file_size_bytes']) ? (int) $data['file_size_bytes'] : 0);
        $selector = app(AutomaticStorageSelector::class);
        $expectedBytes = $inputBytes > 0 ? $selector->estimateTranscodeOutputBytes($inputBytes) : 0;

        return $selector->resolve($requestedKey, $expectedBytes)['target']->key;
    }

    private function storageTargetColumnValue(string $resolvedStorageTarget): ?string
    {
        return in_array($resolvedStorageTarget, ['local', 'public'], true) ? null : $resolvedStorageTarget;
    }

    /**
     * Resolve an already-concrete storage_target (set once at job creation
     * by resolveInitialStorageTarget) to its Laravel filesystem disk name.
     *
     * @return array{disk: string, is_contabo: bool, target_key: ?string}
     */
    private function resolveDiskForTarget(string $target): array
    {
        if (in_array($target, ['local', 'public'], true)) {
            return ['disk' => 'public', 'is_contabo' => false, 'target_key' => null];
        }

        $registry = app(StorageTargetRegistry::class);

        // Normally "auto" is resolved once at job creation (see
        // resolveInitialStorageTarget) so a whole job's outputs land on one
        // target. This is a defensive fallback for callers that bypassed
        // that (e.g. an admin explicitly re-picking "Automatic" on retry).
        if ($registry->isAutoKey($target)) {
            $storageTarget = app(AutomaticStorageSelector::class)->resolveAutomatic(0)['target'];

            return ['disk' => $storageTarget->disk, 'is_contabo' => true, 'target_key' => $storageTarget->key];
        }

        $storageTarget = $registry->findOrFail($registry->normalizeStoredKey($target));

        return ['disk' => $storageTarget->disk, 'is_contabo' => true, 'target_key' => $storageTarget->key];
    }

    public function createRemoteJob(array $data, MediaSourceService $mediaSourceService): MediaSource
    {
        $sourceUrl = SafeRemoteMediaUrl::assertAllowed((string) ($data['source_url'] ?? ''));
        if ($existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null)) {
            return $existing;
        }

        $inputType = ($data['input_type'] ?? null) === 'object_storage' ? 'object_storage' : 'remote_fetch';
        // Resolve "auto"/legacy storage_target to a concrete key ONCE, up
        // front, so this job's outputs all land on one target regardless of
        // how long processing takes or how usage changes meanwhile.
        $data['storage_target'] = $this->resolveInitialStorageTarget($data);
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
            'storage_target_key' => $this->storageTargetColumnValue($data['storage_target']),
            'status' => 'pending',
            'is_active' => true,
            'compress_enabled' => (bool) ($data['compress_enabled'] ?? false),
            'idempotency_key' => $this->normalizedIdempotencyKey($data['idempotency_key'] ?? null),
            'processing_revision' => max(1, (int) ($data['processing_revision'] ?? 1)),
            'source_metadata' => $this->initialMetadata($data, $inputType),
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

    public function createTelegramJob(array $data): MediaSource
    {
        $telegramUrl = trim((string) ($data['telegram_url'] ?? $data['source_url'] ?? ''));
        if (! preg_match('~^https://t\.me/~i', $telegramUrl)) {
            throw new \RuntimeException('A valid https://t.me/ Telegram message URL is required.');
        }
        if ($existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null)) {
            return $existing;
        }

        $source = $this->createTelegramSource($data, $telegramUrl, 'Waiting to dispatch to Teletyde.');

        $dispatched = app(TelegramImportDispatchService::class)->dispatch($source);
        $source = $this->markNbxStatus(
            $source->fresh() ?? $source,
            $dispatched ? 'telegram_fetching' : 'waiting_for_capacity',
        );
        app(NbxWebhookDispatcher::class)->dispatch($source, 'job.created');

        return $source;
    }

    private function createTelegramSource(array $data, string $telegramUrl, string $initialTelebotMessage): MediaSource
    {
        $data['storage_target'] = $this->resolveInitialStorageTarget($data);
        $asset = MediaAsset::create([
            'type' => (string) ($data['asset_type'] ?? 'generic'),
            'title' => (string) ($data['title'] ?? 'Telegram media'),
            'description' => $data['description'] ?? null,
            'status' => 'importing',
            'visibility' => (string) ($data['visibility'] ?? 'public'),
        ]);
        $metadata = $this->initialMetadata($data, 'telegram');
        $metadata['source'] = 'telegram';
        $metadata['telegram_url'] = $telegramUrl;
        $metadata['handoff_mode'] = 'source_url';
        $metadata['telebot_status'] = 'waiting_for_capacity';
        $metadata['telebot_message'] = $initialTelebotMessage;

        return MediaSource::create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => $telegramUrl,
            'storage_disk' => (string) config('nbx.work_storage', config('cdn.disk', 'public')),
            'storage_target_key' => $this->storageTargetColumnValue($data['storage_target']),
            'status' => 'pending',
            'external_job_id' => (string) Str::uuid(),
            'idempotency_key' => $this->normalizedIdempotencyKey($data['idempotency_key'] ?? null),
            'processing_revision' => max(1, (int) ($data['processing_revision'] ?? 1)),
            'compress_enabled' => (bool) ($data['compress_enabled'] ?? false),
            'is_active' => true,
            'source_metadata' => $metadata,
        ]);
    }

    /**
     * Locate an existing Telegram-origin source for this exact message, or
     * create one, when a Telebot handoff arrives with no NBX asset_id/
     * source_id attached — e.g. a link submitted directly from Telebot's
     * own dashboard rather than dispatched by NBX first. Never re-dispatches
     * to Telebot: the caller already has the fetched file in hand.
     */
    public function findOrCreateTelegramSourceForHandoff(array $incoming, string $originalFilename): MediaSource
    {
        $chatId = $incoming['telegram_chat_id'] ?? null;
        $messageId = $incoming['telegram_message_id'] ?? null;
        if ($chatId !== null && $messageId !== null) {
            $existing = MediaSource::query()
                ->where('source_metadata->telegram_chat_id', $chatId)
                ->where('source_metadata->telegram_message_id', $messageId)
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $telegramUrl = trim((string) ($incoming['telegram_url'] ?? ''));
        if (! preg_match('~^https://t\.me/~i', $telegramUrl)) {
            throw new \RuntimeException('No existing media source matched this Telegram message, and metadata.telegram_url was missing/invalid, so a new one could not be created.');
        }

        return $this->createTelegramSource(
            [
                'telegram_url' => $telegramUrl,
                'title' => $incoming['title_guess'] ?: pathinfo($originalFilename, PATHINFO_FILENAME),
            ],
            $telegramUrl,
            'Received directly from Telebot; NBX created a new media record for it.',
        );
    }

    public function createUploadJob(array $data, UploadedFile $file, MediaSourceService $mediaSourceService): MediaSource
    {
        if ($existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null)) {
            return $existing;
        }

        $data['size_bytes'] = $data['size_bytes'] ?? $file->getSize() ?: null;
        $data['storage_target'] = $this->resolveInitialStorageTarget($data);
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
            'storage_target_key' => $this->storageTargetColumnValue($data['storage_target']),
            'status' => 'uploading',
            'progress_percent' => 0,
            'external_job_id' => (string) Str::uuid(),
            'idempotency_key' => $this->normalizedIdempotencyKey($data['idempotency_key'] ?? null),
            'processing_revision' => max(1, (int) ($data['processing_revision'] ?? 1)),
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

        if ($this->shouldRetainOriginal($source)) {
            $source = $this->publishAvailableArtifacts($source->fresh() ?? $source, ['original']);
        }
        $source = $this->markNbxStatus($source->fresh(), 'uploading');
        app(NbxWebhookDispatcher::class)->dispatch($source, 'job.created');

        return $source;
    }

    public function performAction(
        MediaSource $source,
        string $operation,
        array $options,
        MediaSourceService $mediaSourceService
    ): MediaSource {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $actionKey = $this->normalizedIdempotencyKey($options['idempotency_key'] ?? null)
            ?: hash('sha256', $source->id.'|'.$operation.'|'.$source->processing_revision);
        $actions = (array) ($nbx['actions'] ?? []);

        if (isset($actions[$actionKey]) && ($actions[$actionKey]['status'] ?? null) !== 'failed') {
            return $source->fresh() ?? $source;
        }
        $wasFinalStorageFailure = str_contains(strtolower((string) $source->failure_reason), 'final storage')
            || ! empty($nbx['finalization_error']);

        $requested = (array) ($nbx['requested'] ?? []);
        foreach ([
            'faststart', 'allow_downloads', 'allow_hls_streaming', 'max_resolution',
            'processing_preset', 'crf', 'encoder_preset', 'audio_bitrate',
        ] as $key) {
            if (array_key_exists($key, $options)) {
                $requested[$key] = $options[$key];
            }
        }
        if (array_key_exists('compress_enabled', $options)) {
            $requested['compression'] = (bool) $options['compress_enabled'];
            $source->compress_enabled = (bool) $options['compress_enabled'];
        }
        if (isset($options['retention_policy'])) {
            $requested['retention_policy'] = (string) $options['retention_policy'];
        }
        if (isset($options['hls']) && is_array($options['hls'])) {
            $requested['hls'] = array_merge((array) ($requested['hls'] ?? []), $options['hls']);
        }

        $actions[$actionKey] = [
            'operation' => $operation,
            'status' => 'accepted',
            'requested_at' => now()->toIso8601String(),
            'processing_revision' => $source->processing_revision,
        ];
        $nbx['requested'] = $requested;
        $nbx['actions'] = $actions;
        $nbx['operation'] = $operation;
        $metadata['nbx'] = $nbx;

        $updates = [
            'source_metadata' => $metadata,
            'failure_reason' => null,
            'last_error' => null,
            'optimize_error' => null,
            'is_active' => true,
        ];
        if (in_array($operation, ['reprocess', 'retry', 'force_reprocess'], true)) {
            $updates['processing_revision'] = $source->processing_revision + 1;
        }
        $source->fill($updates)->save();
        $source = $source->fresh() ?? $source;

        $sourceMetadata = (array) ($source->source_metadata ?? []);
        $hasLocalOptimized = $source->optimized_path
            && $this->safeExists(
                (string) ($source->storage_disk ?: config('nbx.work_storage', config('cdn.disk', 'public'))),
                (string) $source->optimized_path,
            );

        try {
            $hasRemoteOptimized = $this->validFinalArtifact(
                (array) data_get($sourceMetadata, 'nbx.final_artifacts.faststart', []),
                'faststart',
            );
            if (in_array($operation, ['retry', 'retry_storage'], true)
                && $wasFinalStorageFailure
                && ($hasLocalOptimized || $hasRemoteOptimized)
            ) {
                $source->update(['status' => 'ready', 'optimize_status' => 'ready']);
                $source = $this->finalizeStorageIfNeeded($source->fresh() ?? $source);
            } elseif ($operation === 'reconcile') {
                $source = $this->reconcilePublishedArtifacts($source->fresh() ?? $source);
            } elseif ($operation === 'retry_portal_sync') {
                $source = $this->refreshOutputMetadata($source->fresh() ?? $source);
                app(NbxWebhookDispatcher::class)->dispatch($source, 'job.completed', [
                    'stage' => 'portal_sync',
                    'sync_only' => true,
                ]);
            } elseif ($operation === 'retry' && ($sourceMetadata['nbx']['input_type'] ?? null) === 'telegram') {
                $telegramUrl = (string) ($sourceMetadata['telegram_url'] ?? '');
                if ($telegramUrl === '') {
                    throw new \RuntimeException('Telegram retry is unavailable because the original Telegram URL is missing.');
                }
                $source->update([
                    'source_type' => 'remote_fetch',
                    'source_url' => $telegramUrl,
                    'status' => 'pending',
                    'optimize_status' => null,
                ]);
                $dispatched = app(TelegramImportDispatchService::class)->dispatch($source->fresh() ?? $source);
                $source = $this->markNbxStatus(
                    $source->fresh() ?? $source,
                    $dispatched ? 'telegram_fetching' : 'waiting_for_capacity',
                );
            } elseif ($operation === 'retry' && in_array($source->source_type, ['remote_fetch', 'object_storage'], true)) {
                $source->update(['status' => 'pending', 'optimize_status' => null]);
                $mediaSourceService->queueRemoteImport($source->fresh() ?? $source, true);
            } elseif ($operation === 'force_reimport' && ($sourceMetadata['nbx']['input_type'] ?? null) === 'telegram') {
                // Unlike plain 'retry' (which can end up just replaying
                // Telebot's cached result for this message — correct when
                // NBX's copy is merely out of sync, but not when the
                // underlying file is genuinely gone, e.g. a redeploy wiped
                // local storage), this forces Telebot to bypass its own
                // dedup and actually refetch from Telegram.
                $telegramUrl = (string) ($sourceMetadata['telegram_url'] ?? '');
                if ($telegramUrl === '') {
                    throw new \RuntimeException('Force reimport is unavailable because the original Telegram URL is missing.');
                }
                $source->update([
                    'source_type' => 'remote_fetch',
                    'source_url' => $telegramUrl,
                    'status' => 'pending',
                    'optimize_status' => null,
                ]);
                $dispatched = app(TelegramImportDispatchService::class)->dispatch($source->fresh() ?? $source, force: true);
                $source = $this->markNbxStatus(
                    $source->fresh() ?? $source,
                    $dispatched ? 'telegram_fetching' : 'waiting_for_capacity',
                );
            } elseif ($operation === 'force_reimport' && in_array($source->source_type, ['remote_fetch', 'object_storage'], true)) {
                $source->update(['status' => 'pending', 'optimize_status' => null]);
                $mediaSourceService->queueRemoteImport($source->fresh() ?? $source, true);
            } elseif (in_array($operation, ['retry', 'reprocess', 'generate_faststart', 'compress'], true)) {
                $source->update(['status' => 'ready', 'optimize_status' => null]);
                if (! $mediaSourceService->queuePlaybackProcessing($source->fresh() ?? $source)) {
                    throw new \RuntimeException('NBX could not queue playback processing because no verified work input is available.');
                }
            } elseif ($operation === 'force_reprocess') {
                // Deliberately destructive, unlike plain Reprocess: discards
                // derived artifacts and probe cache, then restarts from the
                // true original — ignoring any already-faststarted/compressed
                // local work file. Not the default action; gate this to
                // admin-only confirmation in Portal's UI.
                $forceMetadata = (array) ($source->source_metadata ?? []);
                unset($forceMetadata['probe'], $forceMetadata['probe_output']);
                if (isset($forceMetadata['nbx']) && is_array($forceMetadata['nbx'])) {
                    unset($forceMetadata['nbx']['processing_result']);
                }
                $source->update([
                    'status' => 'ready',
                    'optimize_status' => null,
                    'optimized_path' => null,
                    'is_faststart' => false,
                    'hls_master_path' => null,
                    'qualities_json' => null,
                    'source_metadata' => $forceMetadata,
                ]);

                $restored = $mediaSourceService->ensureLocalWorkFileForProcessing($source->fresh() ?? $source, forceOriginal: true);
                if (! $restored || ! $restored->storage_path) {
                    throw new \RuntimeException('Force reprocess could not locate the original source file to restart from.');
                }
                if (! $mediaSourceService->queuePlaybackProcessing($restored)) {
                    throw new \RuntimeException('NBX could not queue playback processing because no verified work input is available.');
                }
            } elseif ($operation === 'generate_hls') {
                $source->update(['status' => 'ready']);
                $mediaSourceService->retryWorkerHlsOnly($source->fresh() ?? $source);
            } else {
                throw new \InvalidArgumentException('Unsupported NBX operation.');
            }
        } catch (\Throwable $exception) {
            $source = $source->fresh() ?? $source;
            $failureMetadata = (array) ($source->source_metadata ?? []);
            $failureActions = (array) ($failureMetadata['nbx']['actions'] ?? []);
            $failureActions[$actionKey] = array_merge((array) ($failureActions[$actionKey] ?? []), [
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);
            $failureMetadata['nbx']['actions'] = $failureActions;
            $source->update(['source_metadata' => $failureMetadata]);

            throw $exception;
        }

        app(NbxWebhookDispatcher::class)->dispatch($source->fresh() ?? $source, 'job.action_accepted', [
            'operation' => $operation,
            'idempotency_key' => $actionKey,
        ]);

        return $this->refreshOutputMetadata($source->fresh() ?? $source);
    }

    public function deleteOriginal(MediaSource $source, ?string $idempotencyKey = null): MediaSource
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = (array) ($metadata['nbx'] ?? []);
        $artifacts = (array) ($nbx['final_artifacts'] ?? []);
        $faststart = is_array($artifacts['faststart'] ?? null) ? $artifacts['faststart'] : [];
        $original = is_array($artifacts['original'] ?? null) ? $artifacts['original'] : [];
        $actionKey = $this->normalizedIdempotencyKey($idempotencyKey)
            ?: hash('sha256', $source->id.'|delete_original|'.$source->processing_revision);
        $actions = (array) ($nbx['actions'] ?? []);

        if (isset($actions[$actionKey]) || $original === []) {
            return $this->refreshOutputMetadata($source);
        }
        if (! ($faststart['verified'] ?? false) || empty($faststart['disk']) || empty($faststart['key'])) {
            throw new \RuntimeException('Original deletion refused: a verified optimized MP4 is not available.');
        }
        if (! app(VerifiedObjectStorageService::class)->verify(
            (string) $faststart['disk'],
            (string) $faststart['key'],
            (int) ($faststart['bytes'] ?? 0),
        )) {
            throw new \RuntimeException('Original deletion refused: optimized MP4 storage verification failed.');
        }

        $originalDisk = (string) ($original['disk'] ?? '');
        $originalKey = (string) ($original['key'] ?? '');
        if ($originalDisk === '' || $originalKey === '') {
            throw new \RuntimeException('Original deletion refused: original object metadata is incomplete.');
        }
        if ($originalDisk === (string) $faststart['disk'] && $originalKey === (string) $faststart['key']) {
            throw new \RuntimeException('Original deletion refused: original and optimized object keys are identical.');
        }

        Storage::disk($originalDisk)->delete($originalKey);
        if ($this->safeExists($originalDisk, $originalKey)) {
            throw new \RuntimeException('Original deletion could not be verified.');
        }

        unset($artifacts['original']);
        $actions[$actionKey] = [
            'operation' => 'delete_original',
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
            'deleted_disk' => $originalDisk,
            'deleted_key' => $originalKey,
            'replacement_key' => $faststart['key'],
        ];
        $nbx['final_artifacts'] = $artifacts;
        $nbx['actions'] = $actions;
        $nbx['original_deleted_at'] = now()->toIso8601String();
        $nbx['requested']['retention_policy'] = 'optimized_only';
        $metadata['nbx'] = $nbx;
        $source->update(['source_metadata' => $metadata]);

        app(NbxWebhookDispatcher::class)->dispatch($source->fresh() ?? $source, 'job.original_deleted', [
            'replacement_key' => $faststart['key'],
        ]);

        return $this->refreshOutputMetadata($source->fresh() ?? $source);
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
        if (($metadata['provider'] ?? null) !== 'nbx_engine' && ! isset($metadata['nbx'])) {
            return $source;
        }
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $target = (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $resolvedTarget = $this->resolveDiskForTarget($target);
        $targetDisk = $resolvedTarget['disk'];
        $currentDisk = $source->storage_disk ?: (string) config('cdn.disk', 'public');

        if ($targetDisk === '' || $source->status !== 'ready') {
            return $this->refreshOutputMetadata($source);
        }

        if (! $resolvedTarget['is_contabo'] && ! (bool) config('nbx.allow_local_storage', true)) {
            return $this->refreshOutputMetadata($source);
        }

        if (! $resolvedTarget['is_contabo']) {
            return $this->refreshOutputMetadata($source);
        }

        $source->update([
            'processing_stage' => 'final_storage_upload',
            'processing_stage_progress' => 0,
            'processing_heartbeat_at' => now(),
            'progress_percent' => max(85, min(95, (int) ($source->progress_percent ?? 85))),
            'last_progress_at' => now(),
        ]);
        $source = $source->fresh() ?? $source;
        $roles = ['faststart', 'hls'];
        if ($this->shouldRetainOriginal($source)) {
            array_unshift($roles, 'original');
        }
        $source = $this->publishAvailableArtifacts($source, $roles);

        if ($source->status !== 'ready') {
            return $source;
        }
        $publishedMetadata = (array) ($source->source_metadata ?? []);
        if (($publishedMetadata['nbx']['status'] ?? null) === 'partially_completed'
            && ! empty($publishedMetadata['nbx']['finalization_error'])) {
            return $this->refreshOutputMetadata($source);
        }
        $publishedNbx = (array) ($publishedMetadata['nbx'] ?? []);
        $missingArtifact = $this->requiredArtifactError($publishedNbx);
        if ($missingArtifact !== null) {
            return $this->failFinalization(
                $source,
                $missingArtifact,
                $publishedMetadata,
                $publishedNbx,
                $target,
                $currentDisk,
            );
        }

        $source->update([
            'processing_stage' => 'remote_verification',
            'processing_stage_progress' => 100,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 98,
            'last_progress_at' => now(),
        ]);
        $source = $source->fresh() ?? $source;

        if (! (bool) config('nbx.keep_local_work_files', false)) {
            $this->cleanupLocalWorkFiles($source, $currentDisk);
        }

        $source->update([
            'processing_stage' => 'ready',
            'processing_stage_progress' => 100,
            'processing_heartbeat_at' => now(),
            'progress_percent' => 100,
            'last_progress_at' => now(),
        ]);

        return $this->refreshOutputMetadata($source->fresh() ?? $source);
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function publishAvailableArtifacts(MediaSource $source, array $roles = ['original', 'faststart', 'hls']): MediaSource
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $target = (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $resolvedTarget = $this->resolveDiskForTarget($target);
        $targetDisk = $resolvedTarget['disk'];
        $finalDisk = $resolvedTarget['disk'];
        $currentDisk = $source->storage_disk ?: (string) config('cdn.disk', 'public');

        if ($source->status !== 'ready' || ! $resolvedTarget['is_contabo']) {
            return $this->refreshOutputMetadata($source);
        }

        $contaboCredentials = app(ContaboStorageCredentialService::class);
        if (! $contaboCredentials->ensureRuntimeDiskCredentials($finalDisk)) {
            return $this->failFinalization($source, $contaboCredentials->configurationError(), $metadata, $nbx, $target, $currentDisk);
        }

        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $roles = array_values(array_unique($roles));
        $qualities = is_array($source->qualities_json) ? $source->qualities_json : [];

        try {
            if (in_array('original', $roles, true) && $this->shouldRetainOriginal($source)) {
                $originalPath = $this->firstExistingPath($currentDisk, [
                    $source->original_storage_path,
                    $source->storage_path,
                ]);

                if ($originalPath) {
                    $artifacts['original'] = $this->copyFinalArtifact($currentDisk, $originalPath, $finalDisk, $this->finalObjectKey($source, 'original', $originalPath), 'original');
                }
            }

            if (in_array('faststart', $roles, true)) {
                $faststartPath = $source->is_faststart
                    && $source->optimize_status === 'ready'
                    && is_string($source->optimized_path)
                    && strtolower((string) pathinfo($source->optimized_path, PATHINFO_EXTENSION)) === 'mp4'
                    ? $this->firstExistingPath($currentDisk, [$source->optimized_path])
                    : null;

                if ($faststartPath) {
                    $artifacts['faststart'] = $this->copyFinalArtifact($currentDisk, $faststartPath, $finalDisk, $this->finalObjectKey($source, 'faststart', $faststartPath), 'faststart');
                } elseif (isset($artifacts['faststart']) && ! $this->validFinalArtifact((array) $artifacts['faststart'], 'faststart')) {
                    // A previous release could publish storage_path here before FFmpeg
                    // finished, which mislabeled MOV/MKV originals as optimized files.
                    unset($artifacts['faststart']);
                }
            }

            if (in_array('hls', $roles, true) && $source->hls_master_path && $this->safeExists($currentDisk, (string) $source->hls_master_path)) {
                $hlsBase = dirname((string) $source->hls_master_path);
                $hlsFiles = $this->hlsDirectoryFiles($source, $currentDisk);
                foreach ($hlsFiles as $path) {
                    $relative = ltrim(substr($path, strlen($hlsBase)), '/');
                    $artifact = $this->copyFinalArtifact($currentDisk, $path, $finalDisk, $this->finalObjectKey($source, 'hls/'.$relative, $path), str_ends_with($path, '.m3u8') ? 'hls' : 'hls_segment');

                    if ($path === $source->hls_master_path) {
                        $artifacts['hls_master'] = $artifact;
                    }

                    foreach ($qualities as $index => $quality) {
                        if (! is_array($quality) || ($quality['path'] ?? null) !== $path) {
                            continue;
                        }

                        $qualities[$index]['source_path'] = $path;
                        $qualities[$index]['disk'] = $finalDisk;
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

            $nbx['final_artifacts'] = $artifacts;
            $metadata['nbx'] = $nbx;

            return $this->failFinalization($source, $exception->getMessage(), $metadata, $nbx, $target, $currentDisk);
        }

        unset($nbx['finalization_error'], $nbx['failed_at']);
        $nbx = array_merge($nbx, [
            'storage_target' => $target,
            'work_storage_disk' => $currentDisk,
            'final_storage_disk' => $finalDisk,
            'final_artifacts' => $artifacts,
            'published_at' => now()->toIso8601String(),
        ]);
        $metadata['nbx'] = $nbx;
        $metadata['provider'] = 'nbx_engine';

        $source->update([
            'failure_reason' => null,
            'last_error' => null,
            'is_active' => true,
            'storage_target_key' => $resolvedTarget['target_key'],
            'source_metadata' => $metadata,
        ]);

        return $source->fresh() ?? $source;
    }

    /**
     * Rebuild final-artifact metadata from objects which already exist. This
     * method never copies, encodes, deletes, or renames media.
     */
    public function reconcilePublishedArtifacts(MediaSource $source): MediaSource
    {
        $source = $source->fresh() ?? $source;
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $target = (string) ($nbx['storage_target'] ?? config('nbx.default_storage', 'contabo'));
        $resolvedTarget = $this->resolveDiskForTarget($target);
        $finalDisk = $resolvedTarget['disk'];
        if (! $resolvedTarget['is_contabo']) {
            return $this->refreshOutputMetadata($source);
        }

        $credentials = app(ContaboStorageCredentialService::class);
        if (! $credentials->ensureRuntimeDiskCredentials($finalDisk)) {
            throw new \RuntimeException($credentials->configurationError($finalDisk));
        }

        $existing = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $prefix = $this->finalStoragePrefix($source);
        $candidates = $this->artifactCandidateKeys($prefix, $existing, $finalDisk);
        $artifacts = $existing;
        $proof = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : [];
        $verifiedRoles = [];

        foreach (['original', 'faststart', 'hls_master'] as $role) {
            foreach ($candidates[$role] as $key) {
                $previous = is_array($existing[$role] ?? null) && ($existing[$role]['key'] ?? null) === $key
                    ? $existing[$role]
                    : [];
                $artifact = $this->verifiedExistingArtifact($finalDisk, $key, $role, $previous, $proof);
                if ($artifact === null) {
                    continue;
                }

                $artifacts[$role] = $artifact;
                $verifiedRoles[] = $role;
                if ($role === 'hls_master' && ! $source->hls_master_path) {
                    $source->hls_master_path = $key;
                }
                break;
            }
        }

        $nbx['final_artifacts'] = $artifacts;
        $missingArtifact = $this->requiredArtifactError($nbx);
        $hasFaststart = $this->artifactIsRemotelyVerified((array) ($artifacts['faststart'] ?? []), 'faststart');
        $hasHls = $this->artifactIsRemotelyVerified((array) ($artifacts['hls_master'] ?? []), 'hls_master');
        $original = (array) ($artifacts['original'] ?? []);
        $hasPlayableOriginal = $this->artifactIsRemotelyVerified($original, 'original')
            && in_array(strtolower((string) pathinfo((string) ($original['key'] ?? ''), PATHINFO_EXTENSION)), ['mp4', 'm4v'], true)
            && $this->probeProvesPlayable((array) ($original['probe'] ?? $proof));
        $hasPlayableOutput = $hasFaststart || $hasHls || $hasPlayableOriginal;
        $processingComplete = (bool) ($nbx['processing_complete'] ?? false)
            || $source->status === 'ready'
            || ($source->is_faststart && $source->optimize_status === 'ready')
            || $this->hasStoredArtifact($nbx);
        $publicationStatus = $missingArtifact === null
            ? 'complete'
            : ($hasPlayableOutput ? 'partial' : ($verifiedRoles === [] ? 'missing' : 'attention_required'));

        $nbx = array_merge($nbx, [
            'final_artifacts' => $artifacts,
            'final_storage_disk' => $finalDisk,
            'processing_complete' => $processingComplete,
            'storage_verified' => $verifiedRoles !== [],
            'publication_status' => $publicationStatus,
            'reconciled_at' => now()->toIso8601String(),
            'reconciled_roles' => array_values(array_unique($verifiedRoles)),
        ]);
        if ($missingArtifact === null) {
            unset($nbx['finalization_error'], $nbx['failed_at'], $nbx['error_code']);
        } else {
            $nbx['finalization_error'] = $missingArtifact;
            $nbx['error_code'] = $hasPlayableOutput ? 'PUBLICATION_PARTIAL' : 'PLAYBACK_OUTPUT_MISSING';
        }
        $metadata['provider'] = 'nbx_engine';
        $metadata['nbx'] = $nbx;

        $source->fill([
            'status' => $processingComplete ? 'ready' : $source->status,
            'is_active' => $hasPlayableOutput,
            'storage_target_key' => $resolvedTarget['target_key'],
            'failure_reason' => $missingArtifact === null
                ? null
                : ($hasPlayableOutput
                    ? 'A playable source is available, but another requested format still needs publication.'
                    : 'Stored media was found, but no verified playback output is published yet.'),
            'last_error' => $missingArtifact,
            'processing_stage' => $missingArtifact === null ? 'ready' : 'publication_attention',
            'processing_stage_progress' => 100,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => $missingArtifact,
            'progress_percent' => $processingComplete ? 100 : $source->progress_percent,
            'last_progress_at' => now(),
            'source_metadata' => $metadata,
        ])->save();

        $fresh = $this->refreshOutputMetadata($source->fresh() ?? $source);
        app(NbxWebhookDispatcher::class)->dispatch($fresh, 'job.reconciled', [
            'publication_status' => $publicationStatus,
            'verified_roles' => array_values(array_unique($verifiedRoles)),
            'processing_complete' => $processingComplete,
        ]);

        return $fresh;
    }

    private function failFinalization(MediaSource $source, string $message, array $metadata, array $nbx, string $target, string $currentDisk): MediaSource
    {
        $faststart = (array) ($nbx['final_artifacts']['faststart'] ?? []);
        $hasVerifiedProgressive = $this->validFinalArtifact($faststart, 'faststart')
            && ! empty($faststart['bytes']);
        $processingComplete = $this->hasCompletedLocalOutput($source, $nbx)
            || $this->hasStoredArtifact($nbx);
        $status = $hasVerifiedProgressive
            ? 'partially_completed'
            : ($processingComplete ? 'publication_pending' : 'failed');
        $metadata['nbx'] = array_merge($nbx, [
            'status' => $status,
            'storage_target' => $target,
            'work_storage_disk' => $currentDisk,
            'finalization_error' => $message,
            'processing_complete' => $processingComplete,
            'storage_verified' => $hasVerifiedProgressive,
            'publication_status' => $hasVerifiedProgressive ? 'partial' : 'pending',
            'error_code' => $processingComplete ? 'PUBLICATION_PENDING' : 'STORAGE_UPLOAD_FAILED',
            'failed_at' => now()->toIso8601String(),
        ]);

        $source->update([
            'status' => ($hasVerifiedProgressive || $processingComplete) ? 'ready' : 'failed',
            'failure_reason' => $processingComplete
                ? 'Media processing completed, but final publication needs attention: '.$message
                : 'Final storage upload failed: '.$message,
            'last_error' => $message,
            'is_active' => $hasVerifiedProgressive,
            'optimize_status' => $source->is_faststart ? 'ready' : $source->optimize_status,
            'processing_stage' => $processingComplete ? 'publication_pending' : 'failed',
            'processing_stage_progress' => $processingComplete ? 100 : null,
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => ($processingComplete ? 'Publication pending: ' : 'Final storage upload failed: ').$message,
            'progress_percent' => min(95, (int) ($source->progress_percent ?? 90)),
            'last_progress_at' => now(),
            'source_metadata' => $metadata,
        ]);

        $fresh = $source->fresh() ?? $source;
        app(NbxWebhookDispatcher::class)->dispatch($fresh, $hasVerifiedProgressive
            ? 'job.partially_completed'
            : ($processingComplete ? 'job.publication_pending' : 'job.failed'), [
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
        $hlsUrl = $playback['hls_master_url'] ?? null;
        $mp4Url = $playback['mp4_play_url'] ?? $playback['download_url'] ?? null;
        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $originalArtifact = is_array($artifacts['original'] ?? null) ? $artifacts['original'] : [];

        $metadata['provider'] = 'nbx_engine';
        $metadata['nbx'] = array_merge($nbx, [
            'status' => $source->status === 'failed'
                ? 'failed'
                : ($hlsUrl ? 'completed' : ($mp4Url ? 'partially_completed' : ($nbx['status'] ?? 'pending'))),
            'job_id' => $source->external_job_id,
            'outputs' => [
                'original_url' => $originalArtifact['url'] ?? null,
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
        $failure = app(ProcessingFailurePresenter::class)->forSource($source);
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
            'processing_stage' => $source->processing_stage,
            'processing_stage_progress' => $source->processing_stage_progress,
            'processing_heartbeat_at' => $source->processing_heartbeat_at?->toIso8601String(),
            // Full commands and filesystem paths stay in NBX logs/admin only.
            // Portal and creator clients receive the safe failure contract below.
            'processing_diagnostics' => null,
            'diagnostics_available' => $source->processing_stage === 'failed'
                && filled($source->processing_diagnostics),
            'progress_percent' => $source->progress_percent,
            'failure_reason' => $failure['message'] ?? null,
            'error_code' => $failure['code'] ?? null,
            'safe_message' => $failure['message'] ?? null,
            'support_reference' => $failure['support_reference'] ?? null,
            'retryable' => $failure['retryable'] ?? false,
            'action_required' => $failure['action_required'] ?? false,
            'storage_disk' => $source->storage_disk,
            'storage_target' => $nbx['storage_target'] ?? null,
            'compress_enabled' => (bool) $source->compress_enabled,
            'compression_outcome' => $source->compressionOutcomeLabel(),
            'estimated_seconds_remaining' => $source->estimatedSecondsRemaining(),
            'processing_complete' => (bool) ($nbx['processing_complete'] ?? false),
            'storage_verified' => (bool) ($nbx['storage_verified'] ?? false),
            'publication_status' => $nbx['publication_status'] ?? null,
            'publication_error' => $nbx['finalization_error'] ?? null,
            'default_storage' => (string) config('nbx.default_storage', 'contabo'),
            'original_url' => $this->artifactUrl($nbx, 'original'),
            'faststart_mp4_url' => $playback['mp4_play_url'] ?? null,
            'download_mp4_url' => $this->mp4OnlyUrl($playback['download_url'] ?? null),
            'hls_master_url' => $playback['hls_master_url'] ?? null,
            'hls_480p_url' => $qualityUrl('480p'),
            'hls_720p_url' => $qualityUrl('720p'),
            'hls_1080p_url' => $qualityUrl('1080p'),
            'qualities' => $qualities,
            'sources' => $this->sourceList($source, $nbx, $playback),
            'outputs' => $this->structuredOutputs($source, $nbx),
            'original_retained' => $this->artifactUrl($nbx, 'original') !== null,
            'processing_revision' => $source->processing_revision,
            'unavailable' => $this->unavailableOutputs($nbx, $playback),
            'local_work_path' => app(\App\Services\Storage\StorageTargetRegistry::class)->isContaboFamily($source->storage_disk) ? null : $source->storage_path,
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

        if (! empty($criteria['asset_id'])) {
            return MediaSource::with('asset')
                ->where('media_asset_id', (string) $criteria['asset_id'])
                ->latest('id')
                ->first();
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
                '480p' => (bool) ($data['hls_480p'] ?? config('nbx.default_hls_480', false)),
                '720p' => (bool) ($data['hls_720p'] ?? config('nbx.default_hls_720', false)),
                '1080p' => (bool) ($data['hls_1080p'] ?? config('nbx.default_hls_1080', false)),
            ],
            'allow_downloads' => (bool) ($data['allow_downloads'] ?? true),
            'allow_hls_streaming' => (bool) ($data['allow_hls_streaming'] ?? true),
            'retention_policy' => (string) ($data['retention_policy'] ?? 'optimized_only'),
            'keep_original' => (bool) ($data['keep_original'] ?? false),
            'delete_original_after_optimization' => (bool) ($data['delete_original_after_optimization'] ?? true),
            'max_resolution' => isset($data['max_resolution']) ? (int) $data['max_resolution'] : null,
            'processing_preset' => (string) ($data['processing_preset'] ?? 'automatic'),
            'crf' => isset($data['crf']) ? (int) $data['crf'] : null,
            'encoder_preset' => $data['encoder_preset'] ?? null,
            'audio_bitrate' => $data['audio_bitrate'] ?? null,
        ];

        if (! (bool) config('nbx.allow_1080p', false)) {
            $requested['hls']['1080p'] = false;
        }

        $storageTarget = $this->resolveInitialStorageTarget($data);

        return [
            'provider' => 'nbx_engine',
            'source_url' => $data['source_url'] ?? null,
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
                'processing_revision' => max(1, (int) ($data['processing_revision'] ?? 1)),
                'operation' => (string) ($data['operation'] ?? 'ingest'),
                'idempotency_key' => $this->normalizedIdempotencyKey($data['idempotency_key'] ?? null),
                'submitted_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function dispatchStatusWebhook(MediaSource $source, string $status, ?string $message = null): void
    {
        $event = match ($status) {
            'fetching' => 'job.fetching',
            'probing' => 'job.probing',
            'compressing', 'faststarting' => 'job.processing',
            'failed' => 'job.failed',
            default => str_starts_with($status, 'encoding_') ? 'job.processing' : null,
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
            if (is_string($path) && $path !== '' && $this->safeExists($disk, $path)) {
                return $path;
            }
        }

        return null;
    }

    private function copyFinalArtifact(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath, string $role): array
    {
        $verified = app(VerifiedObjectStorageService::class)
            ->copy($sourceDisk, $sourcePath, $targetDisk, $targetPath);

        return [
            'role' => $role,
            'disk' => $targetDisk,
            'key' => $targetPath,
            'source_path' => $sourcePath,
            'url' => Storage::disk($targetDisk)->url($targetPath),
            'bytes' => $verified['bytes'],
            'mime_type' => $verified['content_type'],
            'etag' => $verified['etag'],
            'multipart' => $verified['multipart'],
            'verified' => true,
            'verified_at' => now()->toIso8601String(),
            'published_at' => now()->toIso8601String(),
        ];
    }

    private function finalObjectKey(MediaSource $source, string $role, string $sourcePath): string
    {
        $prefix = trim((string) config('services.contabo_object_storage.path_prefix', 'videos'), '/');
        $job = $source->external_job_id ?: ('source-'.$source->id);
        $safeJob = Str::slug($job) ?: ('source-'.$source->id);
        $filename = basename($sourcePath);

        if (str_starts_with($role, 'hls/')) {
            return trim($prefix.'/nbx/'.$safeJob.'/'.$role, '/');
        }

        return trim($prefix.'/nbx/'.$safeJob.'/'.trim($role, '/').'/'.$filename, '/');
    }

    private function cleanupLocalWorkFiles(MediaSource $source, string $disk): void
    {
        // Guard against deleting objects that live directly on a Contabo
        // target rather than the local work disk (e.g. object_storage-type
        // sources). An exact `=== 'contabo'` check stopped matching once
        // per-target disks were named "contabo_nbx"/"contabo_nb_nbx".
        if (app(\App\Services\Storage\StorageTargetRegistry::class)->isContaboFamily($disk)) {
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

    private function requiredArtifactError(array $nbx): ?string
    {
        $requested = (array) ($nbx['requested'] ?? []);
        $artifacts = (array) ($nbx['final_artifacts'] ?? []);

        $faststart = (array) ($artifacts['faststart'] ?? []);
        if (($requested['faststart'] ?? true) && ! $this->artifactIsRemotelyVerified($faststart, 'faststart')) {
            return 'Verified Fast Start output is missing; local cleanup was not started.';
        }

        $requestedHls = (array) ($requested['hls'] ?? []);
        $hlsExpected = (bool) ($requested['allow_hls_streaming'] ?? true)
            && collect($requestedHls)->contains(static fn (mixed $enabled): bool => (bool) $enabled);
        $hlsMaster = (array) ($artifacts['hls_master'] ?? []);
        if ($hlsExpected && ! $this->artifactIsRemotelyVerified($hlsMaster, 'hls_master')) {
            return 'Requested HLS package is not verified in final storage; local retry artifacts were preserved.';
        }

        return null;
    }

    private function artifactIsRemotelyVerified(array $artifact, string $role): bool
    {
        return $this->validFinalArtifact($artifact, $role)
            && ! empty($artifact['disk'])
            && ! empty($artifact['key'])
            && app(VerifiedObjectStorageService::class)->verify(
                (string) $artifact['disk'],
                (string) $artifact['key'],
                (int) ($artifact['bytes'] ?? 0),
            );
    }

    private function sourceList(MediaSource $source, array $nbx, array $playback): array
    {
        $artifacts = is_array($nbx['final_artifacts'] ?? null) ? $nbx['final_artifacts'] : [];
        $sources = [];

        foreach (['original' => 'source', 'faststart' => '480p', 'hls_master' => 'auto'] as $role => $quality) {
            $artifact = is_array($artifacts[$role] ?? null) ? $artifacts[$role] : [];
            if (! $this->validFinalArtifact($artifact, $role)) {
                continue;
            }

            $isHls = $role === 'hls_master';
            $sources[] = [
                'type' => $isHls ? 'hls' : 'mp4',
                'role' => match ($role) {
                    'original' => 'source_original',
                    'faststart' => 'playback_progressive',
                    default => 'hls_master',
                },
                'quality' => $quality,
                'disk' => $artifact['disk'] ?? 'contabo',
                'key' => $artifact['key'] ?? null,
                'url' => $artifact['url'],
                'container' => $isHls ? 'hls' : 'mp4',
                'mime_type' => $artifact['mime_type'] ?? ($isHls ? 'application/vnd.apple.mpegurl' : 'video/mp4'),
                'bytes' => $artifact['bytes'] ?? null,
                'verified' => (bool) ($artifact['verified'] ?? false),
                'fast_start' => $role === 'faststart',
                'is_downloadable' => $role === 'faststart'
                    && (bool) ($nbx['requested']['allow_downloads'] ?? true),
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

    private function unavailableOutputs(array $nbx, array $playback): array
    {
        $unavailable = [];
        $requested = is_array($nbx['requested']['hls'] ?? null) ? $nbx['requested']['hls'] : [];
        $skipped = is_array($nbx['skipped_profiles'] ?? null) ? $nbx['skipped_profiles'] : [];

        foreach ($requested as $quality => $enabled) {
            if (! $enabled) {
                continue;
            }

            $quality = strtolower((string) $quality);
            $available = false;
            foreach ((array) ($playback['qualities'] ?? []) as $row) {
                if (is_array($row) && strtolower((string) ($row['id'] ?? '')) === $quality && ! empty($row['url'])) {
                    $available = true;
                    break;
                }
            }

            if (! $available) {
                $unavailable[] = [
                    'role' => 'hls',
                    'quality' => $quality,
                    'reason' => $skipped[$quality] ?? $skipped[strtoupper($quality)] ?? 'HLS playlist not generated yet',
                ];
            }
        }

        return $unavailable;
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

    private function safeExists(string $disk, ?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    public function shouldRetainOriginal(MediaSource $source): bool
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $requested = (array) ($metadata['nbx']['requested'] ?? []);
        $policy = (string) ($requested['retention_policy'] ?? '');

        if ($policy !== '') {
            return in_array($policy, ['keep_original', 'retain_original'], true);
        }

        return (bool) ($requested['keep_original'] ?? false);
    }

    private function findByIdempotencyKey(mixed $key): ?MediaSource
    {
        $key = $this->normalizedIdempotencyKey($key);

        return $key ? MediaSource::with('asset')->where('idempotency_key', $key)->first() : null;
    }

    private function normalizedIdempotencyKey(mixed $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return substr(trim($key), 0, 128);
    }

    private function artifactUrl(array $nbx, string $role): ?string
    {
        $artifact = $nbx['final_artifacts'][$role] ?? null;

        return is_array($artifact) && $this->validFinalArtifact($artifact, $role)
            ? $artifact['url']
            : null;
    }

    private function structuredOutputs(MediaSource $source, array $nbx): array
    {
        $outputs = [];
        $roles = [
            'original' => 'source_original',
            'faststart' => 'playback_progressive',
            'hls_master' => 'hls_master',
        ];

        foreach ($roles as $key => $role) {
            $artifact = $nbx['final_artifacts'][$key] ?? null;
            if (! is_array($artifact) || ! $this->validFinalArtifact($artifact, $key)) {
                continue;
            }

            $isHls = $key === 'hls_master';
            $outputs[] = [
                'role' => $role,
                'container' => $isHls ? 'hls' : 'mp4',
                'mime_type' => $artifact['mime_type'] ?? ($isHls ? 'application/vnd.apple.mpegurl' : 'video/mp4'),
                'url' => $artifact['url'],
                'object_key' => $artifact['key'] ?? null,
                'storage_disk' => $artifact['disk'] ?? null,
                'bytes' => $artifact['bytes'] ?? null,
                'fast_start' => $key === 'faststart',
                'downloadable' => $key === 'faststart'
                    && (bool) ($nbx['requested']['allow_downloads'] ?? true),
                'verified' => (bool) ($artifact['verified'] ?? false),
                'created_by_job' => $source->external_job_id,
            ];
        }

        return $outputs;
    }

    private function hasCompletedLocalOutput(MediaSource $source, array $nbx): bool
    {
        $disk = (string) ($source->storage_disk ?: config('nbx.work_storage', config('cdn.disk', 'public')));
        $requested = (array) ($nbx['requested'] ?? []);

        if ($source->is_faststart
            && $source->optimize_status === 'ready'
            && is_string($source->optimized_path)
            && strtolower((string) pathinfo($source->optimized_path, PATHINFO_EXTENSION)) === 'mp4'
            && $this->safeExists($disk, $source->optimized_path)
        ) {
            return true;
        }
        if ($source->hls_master_path && $this->safeExists($disk, (string) $source->hls_master_path)) {
            return true;
        }

        return ! (bool) ($requested['faststart'] ?? true)
            && is_string($source->storage_path)
            && $this->safeExists($disk, $source->storage_path);
    }

    private function hasStoredArtifact(array $nbx): bool
    {
        foreach (['original', 'faststart', 'hls_master'] as $role) {
            $artifact = (array) data_get($nbx, 'final_artifacts.'.$role, []);
            $disk = (string) ($artifact['disk'] ?? '');
            $key = (string) ($artifact['key'] ?? '');
            if ($disk !== '' && $key !== '' && app(VerifiedObjectStorageService::class)->head($disk, $key)) {
                return true;
            }
        }

        return false;
    }

    private function finalStoragePrefix(MediaSource $source): string
    {
        $prefix = trim((string) config('services.contabo_object_storage.path_prefix', 'videos'), '/');
        $job = $source->external_job_id ?: ('source-'.$source->id);
        $safeJob = Str::slug($job) ?: ('source-'.$source->id);

        return trim($prefix.'/nbx/'.$safeJob, '/');
    }

    /**
     * @return array{original:array<int,string>,faststart:array<int,string>,hls_master:array<int,string>}
     */
    private function artifactCandidateKeys(string $prefix, array $existing, string $disk = 'contabo'): array
    {
        $candidates = ['original' => [], 'faststart' => [], 'hls_master' => []];
        foreach (array_keys($candidates) as $role) {
            $key = data_get($existing, $role.'.key');
            if (is_string($key) && trim($key) !== '') {
                $candidates[$role][] = ltrim($key, '/');
            }
        }

        try {
            foreach (Storage::disk($disk)->files($prefix.'/original') as $key) {
                $candidates['original'][] = ltrim((string) $key, '/');
            }
            foreach (Storage::disk($disk)->files($prefix.'/faststart') as $key) {
                $candidates['faststart'][] = ltrim((string) $key, '/');
            }
            foreach (Storage::disk($disk)->allFiles($prefix.'/hls') as $key) {
                if (str_ends_with(strtolower((string) $key), '.m3u8')
                    && str_contains(strtolower(basename((string) $key)), 'master')) {
                    $candidates['hls_master'][] = ltrim((string) $key, '/');
                }
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Final storage could not be inspected. Retry after the storage connection is restored.', 0, $exception);
        }

        foreach ($candidates as $role => $keys) {
            $candidates[$role] = array_values(array_unique(array_filter($keys)));
        }

        return $candidates;
    }

    private function verifiedExistingArtifact(
        string $disk,
        string $key,
        string $role,
        array $previous,
        array $sourceProbe
    ): ?array {
        $head = app(VerifiedObjectStorageService::class)->head($disk, $key);
        if (! is_array($head) || (int) ($head['bytes'] ?? 0) <= 0) {
            return null;
        }
        $previousBytes = (int) ($previous['bytes'] ?? 0);
        if ($previousBytes > 0 && $previousBytes !== (int) $head['bytes']) {
            return null;
        }

        $extension = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));
        $inspection = [];
        if ($role === 'faststart') {
            if ($extension !== 'mp4') {
                return null;
            }
            $inspection = $this->inspectProgressiveObject($disk, $key);
            if (! ($inspection['is_mp4'] ?? false) || ! ($inspection['fast_start'] ?? false)) {
                return null;
            }
        } elseif ($role === 'hls_master') {
            if ($extension !== 'm3u8' || ! $this->isHlsManifest($disk, $key)) {
                return null;
            }
        } elseif (! in_array($extension, ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi'], true)) {
            return null;
        }

        try {
            $url = Storage::disk($disk)->url($key);
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $probe = $sourceProbe;
        if ($role === 'faststart' && ! $this->probeProvesPlayable($probe)) {
            $probe = app(VideoProbeService::class)->probeRemote($url);
        }
        if ($role === 'faststart' && ! $this->probeProvesPlayable($probe)) {
            return null;
        }

        return array_merge($previous, [
            'role' => $role,
            'disk' => $disk,
            'key' => $key,
            'url' => trim($url),
            'bytes' => (int) $head['bytes'],
            'mime_type' => $this->artifactMimeType($key),
            'etag' => $head['etag'] ?? null,
            'multipart' => (bool) ($head['multipart'] ?? false),
            'verified' => true,
            'verified_at' => now()->toIso8601String(),
            'reconciled_at' => now()->toIso8601String(),
            'inspection' => $inspection,
            'probe' => $probe,
        ]);
    }

    /**
     * Inspect enough of the object to prove MP4 identity and that the moov atom
     * precedes media data. Closing the stream after eight MiB avoids fetching a
     * complete movie during reconciliation.
     *
     * @return array{is_mp4:bool,fast_start:bool,bytes_inspected:int}
     */
    private function inspectProgressiveObject(string $disk, string $key): array
    {
        $stream = null;
        try {
            $stream = Storage::disk($disk)->readStream($key);
            if (! is_resource($stream)) {
                return ['is_mp4' => false, 'fast_start' => false, 'bytes_inspected' => 0];
            }
            $buffer = stream_get_contents($stream, 8 * 1024 * 1024);
            if (! is_string($buffer)) {
                return ['is_mp4' => false, 'fast_start' => false, 'bytes_inspected' => 0];
            }
            $ftyp = strpos($buffer, 'ftyp');
            $moov = strpos($buffer, 'moov');
            $mdat = strpos($buffer, 'mdat');
            $isMp4 = $ftyp !== false && $ftyp <= 32;

            return [
                'is_mp4' => $isMp4,
                'fast_start' => $isMp4 && $moov !== false && ($mdat === false || $moov < $mdat),
                'bytes_inspected' => strlen($buffer),
            ];
        } catch (\Throwable) {
            return ['is_mp4' => false, 'fast_start' => false, 'bytes_inspected' => 0];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function isHlsManifest(string $disk, string $key): bool
    {
        $stream = null;
        try {
            $stream = Storage::disk($disk)->readStream($key);
            $header = is_resource($stream) ? stream_get_contents($stream, 4096) : false;

            return is_string($header) && str_starts_with(ltrim($header), '#EXTM3U');
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function probeProvesPlayable(array $probe): bool
    {
        return (bool) ($probe['has_video'] ?? false)
            && (float) ($probe['duration'] ?? $probe['duration_seconds'] ?? 0) > 0;
    }

    private function artifactMimeType(string $key): string
    {
        return match (strtolower((string) pathinfo($key, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'm3u8' => 'application/vnd.apple.mpegurl',
            default => 'application/octet-stream',
        };
    }

    private function validFinalArtifact(array $artifact, string $role): bool
    {
        if (! ($artifact['verified'] ?? false) || ! is_string($artifact['url'] ?? null) || trim($artifact['url']) === '') {
            return false;
        }

        $path = strtolower((string) parse_url((string) ($artifact['key'] ?? $artifact['url']), PHP_URL_PATH));
        if ($role === 'faststart') {
            return str_ends_with($path, '.mp4')
                && in_array(strtolower((string) ($artifact['mime_type'] ?? 'video/mp4')), ['video/mp4', 'application/mp4'], true);
        }
        if ($role === 'hls_master') {
            return str_ends_with($path, '.m3u8');
        }

        return true;
    }
}

<?php

namespace App\Services;

use App\Models\MediaSource;

class TelegramImportDispatchService
{
    public function __construct(private readonly TelebotClientService $telebot)
    {
    }

    public function dispatch(MediaSource $source): bool
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $telegramUrl = trim((string) ($metadata['telegram_url'] ?? $source->source_url ?? ''));

        if ($telegramUrl === '') {
            $this->mergeMetadata($source, [
                'telebot_status' => 'failed',
                'telebot_message' => 'Telegram URL is missing.',
            ]);
            return false;
        }

        try {
            $capacity = $this->telebot->capacity();
        } catch (\Throwable $exception) {
            $this->mergeMetadata($source, [
                'telebot_status' => 'waiting_for_capacity',
                'telebot_message' => 'Telebot is unreachable: ' . $exception->getMessage(),
            ]);
            return false;
        }

        if (! (bool) ($capacity['available'] ?? false)) {
            $this->mergeMetadata($source, [
                'telebot_status' => 'waiting_for_capacity',
                'telebot_message' => 'Waiting for a free Telebot slot.',
                'telebot_capacity' => $capacity,
            ]);
            return false;
        }

        $asset = $source->asset;
        $telebotMetadata = array_merge($metadata, [
            'source' => 'telegram',
            'handoff_mode' => 'stream',
            'cdn_asset_id' => (string) $source->media_asset_id,
            'cdn_source_id' => $source->id,
            'asset_type' => $asset?->type,
            'title_guess' => $asset?->title,
        ]);

        try {
            $response = $this->telebot->createJob($telegramUrl, $telebotMetadata);
        } catch (\Throwable $exception) {
            $this->mergeMetadata($source, [
                'telebot_status' => 'waiting_for_capacity',
                'telebot_message' => 'Telebot dispatch failed: ' . $exception->getMessage(),
            ]);
            return false;
        }

        $jobId = $response['job_id'] ?? ($response['jobs'][0]['job_id'] ?? null);
        $status = $response['status'] ?? ($response['jobs'][0]['status'] ?? 'queued');

        $this->mergeMetadata($source, [
            'telebot_status' => (string) $status,
            'telebot_job_id' => $jobId,
            'telebot_message' => 'Telebot accepted the Telegram import.',
            'telebot_response' => $response,
        ]);

        $source->update([
            'status' => 'downloading',
            'progress_percent' => 0,
            'started_at' => $source->started_at ?? now(),
            'last_progress_at' => now(),
            'failure_reason' => null,
            'last_error' => null,
        ]);

        return true;
    }

    public function refreshJobStatus(MediaSource $source): ?array
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $jobId = trim((string) ($metadata['telebot_job_id'] ?? ''));

        if ($jobId === '') {
            return null;
        }

        $status = $this->telebot->jobStatus($jobId);
        $telebotStatus = (string) ($status['status'] ?? $metadata['telebot_status'] ?? 'queued');
        $progress = isset($status['progress_pct']) ? (int) $status['progress_pct'] : null;

        $this->mergeMetadata($source, [
            'telebot_status' => $telebotStatus,
            'telebot_message' => $status['message'] ?? ($metadata['telebot_message'] ?? null),
            'telebot_last_status' => $status,
        ]);

        $updates = [
            'last_progress_at' => now(),
        ];

        if ($progress !== null && $source->status !== 'ready') {
            $updates['progress_percent'] = min(99, max(0, $progress));
        }

        if ($telebotStatus === 'failed') {
            $updates['status'] = 'failed';
            $updates['failure_reason'] = (string) ($status['error'] ?? $status['message'] ?? 'Telebot import failed.');
            $updates['completed_at'] = now();
        }

        $source->update($updates);

        return $status;
    }

    private function mergeMetadata(MediaSource $source, array $metadata): void
    {
        $source->update([
            'source_metadata' => array_merge((array) ($source->source_metadata ?? []), $metadata),
        ]);
        $source->refresh();
    }
}

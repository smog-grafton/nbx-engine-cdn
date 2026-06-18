<?php

namespace App\Services;

use App\Jobs\DispatchNbxWebhookJob;
use App\Models\MediaSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NbxWebhookDispatcher
{
    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(MediaSource $source, string $event, array $context = []): void
    {
        if (! $this->shouldDispatch($source)) {
            return;
        }

        try {
            DispatchNbxWebhookJob::dispatch($source->id, $event, $context)
                ->onQueue((string) config('nbx.webhook_queue', 'nbx-webhook'));
        } catch (\Throwable $throwable) {
            Log::warning('NBX webhook queue dispatch failed', [
                'source_id' => $source->id,
                'job_id' => $source->external_job_id,
                'event' => $event,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(MediaSource $source, string $event, array $context = []): void
    {
        $callbackUrl = $this->callbackUrl($source);
        $secret = trim((string) config('nbx.webhook_secret', ''));
        if ($callbackUrl === null || $secret === '') {
            return;
        }

        $payload = $this->payload($source, $event, $context);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($body) || $body === '') {
            Log::warning('NBX webhook payload encoding failed', [
                'source_id' => $source->id,
                'job_id' => $source->external_job_id,
                'event' => $event,
            ]);

            return;
        }

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $headers = [
            'Content-Type' => 'application/json',
            'X-NBX-Event' => $event,
            'X-NBX-Signature' => $signature,
            'X-NBX-Timestamp' => $timestamp,
            'X-NBX-Job-Id' => (string) ($payload['nbx_job_id'] ?? $source->external_job_id ?? ''),
        ];

        $attempts = max(1, (int) config('nbx.webhook_retry_times', 3));
        $sleepMs = max(0, (int) config('nbx.webhook_retry_sleep_ms', 1500));
        $timeout = max(1, (int) config('nbx.webhook_timeout', 20));
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(min($timeout, 10))
                    ->withHeaders($headers)
                    ->send('POST', $callbackUrl, ['body' => $body]);

                if ($response->successful()) {
                    $this->recordDelivery($source, $event, true);

                    return;
                }

                $lastError = 'HTTP ' . $response->status();
            } catch (\Throwable $throwable) {
                $lastError = $throwable->getMessage();
            }

            if ($attempt < $attempts && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->recordDelivery($source, $event, false, $lastError);
        Log::warning('NBX webhook delivery failed', [
            'source_id' => $source->id,
            'job_id' => $source->external_job_id,
            'event' => $event,
            'callback_host' => parse_url($callbackUrl, PHP_URL_HOST) ?: null,
            'error' => $lastError,
        ]);
    }

    private function shouldDispatch(MediaSource $source): bool
    {
        return (bool) config('nbx.enabled', true)
            && trim((string) config('nbx.webhook_secret', '')) !== ''
            && $this->callbackUrl($source) !== null;
    }

    private function callbackUrl(MediaSource $source): ?string
    {
        $metadata = (array) ($source->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $candidate = $nbx['callback_url'] ?? $metadata['callback_url'] ?? $metadata['webhook_url'] ?? null;
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $url = trim($candidate);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function payload(MediaSource $source, string $event, array $context): array
    {
        $mediaSourceService = app(MediaSourceService::class);
        $discovery = app(NbxEngineService::class)->discoveryPayload($source, $mediaSourceService);
        $fresh = $source->fresh() ?? $source;
        $metadata = (array) ($fresh->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $probe = is_array($metadata['probe'] ?? null) ? $metadata['probe'] : [];
        $qualities = is_array($discovery['qualities'] ?? null) ? $discovery['qualities'] : [];

        $availableQualities = [];
        foreach ($qualities as $quality) {
            if (! is_array($quality)) {
                continue;
            }

            $id = strtolower((string) ($quality['id'] ?? ''));
            if ($id === '' || $id === 'auto') {
                continue;
            }

            $availableQualities[$id] = [
                'type' => $quality['type'] ?? 'hls',
                'url' => $quality['url'] ?? null,
                'height' => $quality['height'] ?? null,
                'bandwidth' => $quality['bandwidth'] ?? null,
            ];
        }

        return [
            'event' => $event,
            'nbx_job_id' => $discovery['nbx_job_id'] ?? $fresh->external_job_id,
            'job_id' => $discovery['nbx_job_id'] ?? $fresh->external_job_id,
            'asset_id' => $discovery['asset_id'] ?? (string) $fresh->media_asset_id,
            'source_id' => $discovery['source_id'] ?? $fresh->id,
            'video_ref_type' => $metadata['video_ref_type'] ?? null,
            'video_ref_id' => $metadata['video_ref_id'] ?? null,
            'status' => $context['status'] ?? ($discovery['status'] ?? ($nbx['status'] ?? $fresh->status)),
            'source_status' => $discovery['source_status'] ?? $fresh->status,
            'optimize_status' => $discovery['optimize_status'] ?? $fresh->optimize_status,
            'source_metadata' => $metadata,
            'available_sources' => [
                'original_url' => $discovery['original_url'] ?? null,
                'faststart_mp4_url' => $discovery['faststart_mp4_url'] ?? null,
                'download_mp4_url' => $discovery['download_mp4_url'] ?? null,
                'hls_master_url' => $discovery['hls_master_url'] ?? null,
                'qualities' => $availableQualities,
            ],
            'skipped_profiles' => (array) ($nbx['skipped_profiles'] ?? []),
            'failure_reason' => $discovery['failure_reason'] ?? null,
            'probe' => $probe,
            'context' => $context,
            'timestamps' => [
                'created_at' => $fresh->created_at?->toIso8601String(),
                'updated_at' => $fresh->updated_at?->toIso8601String(),
                'completed_at' => $fresh->completed_at?->toIso8601String(),
                'sent_at' => now()->toIso8601String(),
            ],
            'source' => $discovery,
        ];
    }

    private function recordDelivery(MediaSource $source, string $event, bool $delivered, ?string $error = null): void
    {
        $fresh = $source->fresh();
        if (! $fresh) {
            return;
        }

        $metadata = (array) ($fresh->source_metadata ?? []);
        $nbx = is_array($metadata['nbx'] ?? null) ? $metadata['nbx'] : [];
        $nbx['webhook'] = array_filter([
            'last_event' => $event,
            'last_attempt_at' => now()->toIso8601String(),
            'last_delivered_at' => $delivered ? now()->toIso8601String() : ($nbx['webhook']['last_delivered_at'] ?? null),
            'last_error' => $delivered ? null : $error,
        ], static fn (mixed $value): bool => $value !== null);
        $metadata['nbx'] = $nbx;

        $fresh->update(['source_metadata' => $metadata]);
    }
}

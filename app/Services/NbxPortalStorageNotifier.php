<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NbxPortalStorageNotifier
{
    public function isConfigured(): bool
    {
        return trim((string) config('nbx.portal_storage_callback_url', '')) !== ''
            && trim((string) config('nbx.webhook_secret', '')) !== '';
    }

    public function send(array $payload): void
    {
        $url = trim((string) config('nbx.portal_storage_callback_url', ''));
        $secret = trim((string) config('nbx.webhook_secret', ''));
        if ($url === '' || $secret === '') {
            throw new \RuntimeException('Portal storage callback URL or NBX webhook secret is not configured.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($body)) {
            throw new \RuntimeException('Could not encode the Portal storage reconciliation payload.');
        }
        $timestamp = (string) time();
        $response = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(max(5, (int) config('nbx.webhook_timeout', 20)))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-NBX-Event' => (string) ($payload['event'] ?? 'storage.updated'),
                'X-NBX-Timestamp' => $timestamp,
                'X-NBX-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            ])
            ->send('POST', $url, ['body' => $body]);

        if (! $response->successful()) {
            throw new \RuntimeException('Portal storage reconciliation failed with HTTP '.$response->status().'.');
        }
    }
}

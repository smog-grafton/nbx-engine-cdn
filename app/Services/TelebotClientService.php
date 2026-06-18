<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelebotClientService
{
    public function capacity(): array
    {
        return $this->request()
            ->get($this->url('/api/worker/capacity'))
            ->throw()
            ->json();
    }

    public function createJob(string $telegramUrl, array $metadata = []): array
    {
        return $this->request()
            ->post($this->url('/api/worker/jobs'), [
                'link' => $telegramUrl,
                'metadata' => $metadata,
            ])
            ->throw()
            ->json();
    }

    public function jobStatus(string $jobId): array
    {
        return $this->request()
            ->get($this->url('/api/worker/jobs/' . rawurlencode($jobId)))
            ->throw()
            ->json();
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout(max(5, (int) config('cdn.telebot_timeout', 30)));

        $token = trim((string) config('cdn.telebot_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function url(string $path): string
    {
        $baseUrl = trim((string) config('cdn.telebot_api_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('TELEBOT_API_URL is not configured.');
        }

        return rtrim($baseUrl, '/') . $path;
    }
}

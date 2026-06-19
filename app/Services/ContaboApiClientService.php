<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ContaboApiClientService
{
    public function isConfigured(): bool
    {
        return filled(config('services.contabo_api.client_id'))
            && filled(config('services.contabo_api.client_secret'))
            && filled(config('services.contabo_api.username'))
            && filled(config('services.contabo_api.password'));
    }

    public function getS3Credentials(?string $objectStorageId = null, ?string $userId = null): array
    {
        $objectStorageId = trim((string) ($objectStorageId ?: $this->resolveObjectStorageId()));

        if ($objectStorageId === '') {
            return [
                'ok' => false,
                'data' => null,
                'error' => 'Unable to resolve the Contabo object storage ID from the API.',
            ];
        }

        $result = $this->request('get', '/v1/users/' . rawurlencode((string) ($userId ?: $this->resolveUserId())) . '/object-storages/credentials', query: [
            'objectStorageId' => $objectStorageId,
        ]);

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'data' => null,
                'error' => (string) ($result['error'] ?? 'Unable to read Contabo S3 credentials.'),
            ];
        }

        $credentials = is_array($result['data'] ?? null) ? $result['data'] : [];
        $first = collect($credentials)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->first(function (array $item) use ($objectStorageId): bool {
                return (string) ($item['objectStorageId'] ?? '') === $objectStorageId
                    && filled($item['accessKey'] ?? null)
                    && filled($item['secretKey'] ?? null);
            });

        if (! is_array($first)) {
            return [
                'ok' => false,
                'data' => null,
                'error' => 'Contabo did not return S3 credentials for the selected object storage.',
            ];
        }

        return [
            'ok' => true,
            'data' => $first,
            'error' => null,
        ];
    }

    public function resolveUserId(): ?string
    {
        $configured = trim((string) config('services.contabo_api.user_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        $email = trim((string) config('services.contabo_api.username', ''));
        $result = $this->request('get', '/v1/users', query: $email !== '' ? ['email' => $email] : []);

        if (! ($result['ok'] ?? false) && $email !== '') {
            $result = $this->request('get', '/v1/users');
        }

        if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            return null;
        }

        $users = collect($result['data'])->filter(fn (mixed $item): bool => is_array($item));
        $exact = $users->first(fn (array $item): bool => strtolower((string) ($item['email'] ?? '')) === strtolower($email));
        $owner = $users->first(fn (array $item): bool => (bool) ($item['owner'] ?? false));
        $selected = is_array($exact) ? $exact : (is_array($owner) ? $owner : $users->first());

        return is_array($selected) && filled($selected['userId'] ?? null) ? (string) $selected['userId'] : null;
    }

    public function resolveObjectStorageId(): ?string
    {
        $configured = trim((string) config('services.contabo_api.object_storage_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        $result = $this->request('get', '/v1/object-storages');
        if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            return null;
        }

        $endpointHost = strtolower((string) parse_url((string) config('filesystems.disks.contabo.endpoint', ''), PHP_URL_HOST));
        $storages = collect($result['data'])->filter(fn (mixed $item): bool => is_array($item));
        $matchingEndpoint = $storages->first(function (array $item) use ($endpointHost): bool {
            $s3Url = strtolower((string) ($item['s3Url'] ?? ''));

            return $endpointHost !== '' && (
                $s3Url === $endpointHost
                || str_contains($s3Url, $endpointHost)
                || str_contains($endpointHost, $s3Url)
            );
        });
        $ready = $storages->first(fn (array $item): bool => strtoupper((string) ($item['status'] ?? '')) === 'READY');
        $selected = is_array($matchingEndpoint) ? $matchingEndpoint : (is_array($ready) ? $ready : $storages->first());

        return is_array($selected) && filled($selected['objectStorageId'] ?? null) ? (string) $selected['objectStorageId'] : null;
    }

    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => 'Contabo API is not configured. Set CONTABO_API_CLIENT_ID, CONTABO_API_CLIENT_SECRET, CONTABO_API_USERNAME, and CONTABO_API_PASSWORD.',
                'data' => null,
                'body' => null,
            ];
        }

        try {
            $url = rtrim((string) config('services.contabo_api.base_url', 'https://api.contabo.com'), '/') . '/' . ltrim($path, '/');
            $pending = Http::acceptJson()
                ->withToken($this->accessToken())
                ->withHeaders(['x-request-id' => (string) Str::uuid()])
                ->connectTimeout((int) config('services.contabo_api.connect_timeout', 10))
                ->timeout((int) config('services.contabo_api.timeout', 30));

            $response = match (strtolower($method)) {
                'post' => $pending->post($url, $payload),
                'patch' => $pending->patch($url, $payload),
                'put' => $pending->put($url, $payload),
                'delete' => $pending->delete($url, $payload),
                default => $pending->get($url, $query),
            };

            $body = $response->json();

            return [
                'ok' => $response->successful(),
                'status_code' => $response->status(),
                'data' => is_array($body) ? ($body['data'] ?? $body) : $body,
                'error' => $response->successful() ? null : $this->extractError($body, $response->body()),
                'body' => $body,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => $exception->getMessage(),
                'data' => null,
                'body' => null,
            ];
        }
    }

    private function accessToken(): string
    {
        $cached = Cache::get('contabo_api.access_token');
        if (is_array($cached) && isset($cached['token'], $cached['expires_at']) && now()->lt($cached['expires_at'])) {
            return (string) $cached['token'];
        }

        $response = Http::asForm()
            ->connectTimeout((int) config('services.contabo_api.connect_timeout', 10))
            ->timeout((int) config('services.contabo_api.timeout', 30))
            ->post((string) config('services.contabo_api.auth_url', 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token'), [
                'client_id' => config('services.contabo_api.client_id'),
                'client_secret' => config('services.contabo_api.client_secret'),
                'username' => config('services.contabo_api.username'),
                'password' => config('services.contabo_api.password'),
                'grant_type' => 'password',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Contabo token request failed with HTTP ' . $response->status() . '.');
        }

        $payload = $response->json();
        $token = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('Contabo token response did not contain an access_token.');
        }

        $ttl = max(60, (int) ($payload['expires_in'] ?? 300) - 60);
        Cache::put('contabo_api.access_token', [
            'token' => $token,
            'expires_at' => now()->addSeconds($ttl),
        ], $ttl);

        return $token;
    }

    private function extractError(mixed $body, string $fallback): string
    {
        if (is_array($body)) {
            foreach (['message', 'error', 'detail', 'title'] as $key) {
                if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                    return $body[$key];
                }
            }
        }

        return $fallback !== '' ? $fallback : 'Contabo API request failed.';
    }
}

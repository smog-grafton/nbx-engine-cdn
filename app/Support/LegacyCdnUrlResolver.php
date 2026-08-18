<?php

namespace App\Support;

final class LegacyCdnUrlResolver
{
    public function resolve(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        if (! $this->isConfiguredHost($host)) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        if (str_starts_with($path, '/media/')) {
            $path = '/storage/app/public'.$path;
        } elseif (! str_starts_with($path, '/storage/app/public/media/')) {
            return $url;
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $authority .= ':'.(int) $parts['port'];
        }

        $resolved = 'https://'.$authority.$path;
        if (array_key_exists('query', $parts)) {
            $resolved .= '?'.(string) $parts['query'];
        }
        if (array_key_exists('fragment', $parts)) {
            $resolved .= '#'.(string) $parts['fragment'];
        }

        return $resolved;
    }

    public function isLegacyMediaUrl(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! $this->isConfiguredHost(strtolower((string) ($parts['host'] ?? '')))) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');

        return str_starts_with($path, '/media/')
            || str_starts_with($path, '/storage/app/public/media/');
    }

    private function isConfiguredHost(string $host): bool
    {
        $hosts = config('cdn.legacy_cdn_hosts', ['cdn.naraboxtv.com']);

        return $host !== '' && in_array($host, array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) $hosts,
        ))), true);
    }
}

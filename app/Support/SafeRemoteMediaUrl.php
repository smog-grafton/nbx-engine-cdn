<?php

namespace App\Support;

final class SafeRemoteMediaUrl
{
    public static function assertAllowed(?string $url): string
    {
        $normalized = MediaUrl::normalize($url);
        if (! is_string($normalized) || ! filter_var($normalized, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Remote media URL is invalid.');
        }

        $parts = parse_url($normalized);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Remote media URL must use http or https.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            throw new \InvalidArgumentException('Remote media URL host is missing.');
        }

        if (in_array($host, (array) config('nbx.ssrf.blocked_hosts', []), true) || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Remote media URL host is not allowed.');
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw new \InvalidArgumentException('Remote media URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new \InvalidArgumentException('Remote media URL resolves to a blocked network.');
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP)) {
                    $ips[] = (string) $record[$key];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isBlockedIp(string $ip): bool
    {
        if ($ip === '169.254.169.254') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}

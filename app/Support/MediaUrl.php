<?php

namespace App\Support;

final class MediaUrl
{
    public static function normalize(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $trimmed;
        }

        $authority = '';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }
            $authority .= '@';
        }

        $host = (string) $parts['host'];
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $authority .= $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        $path = self::normalizePath((string) ($parts['path'] ?? ''));

        $normalized = $parts['scheme'] . '://' . $authority . $path;

        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $normalized .= '#' . rawurlencode(rawurldecode((string) $parts['fragment']));
        }

        return $normalized;
    }

    public static function isValid(?string $url): bool
    {
        $normalized = self::normalize($url);

        return is_string($normalized) && filter_var($normalized, FILTER_VALIDATE_URL) !== false;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('/', str_replace('\\', '/', $path));

        return implode('/', array_map(static function (string $segment): string {
            if ($segment === '') {
                return '';
            }

            return rawurlencode(rawurldecode($segment));
        }, $segments));
    }
}

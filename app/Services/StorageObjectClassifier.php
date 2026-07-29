<?php

namespace App\Services;

class StorageObjectClassifier
{
    /**
     * Classify only what the object key itself proves. Database associations
     * are added by StorageInventoryService; an unfamiliar key is unresolved,
     * never automatically orphaned.
     *
     * @return array<string,mixed>
     */
    public function classify(string $key): array
    {
        $key = ltrim($key, '/');
        $role = app(StorageReferenceService::class)->classifyRole($key);
        $parts = explode('/', $key);
        $result = [
            'storage_layout' => 'unknown',
            'logical_asset_key' => $this->fallbackGroup($key),
            'media_role' => $role,
            'asset_id_hint' => null,
            'source_id_hint' => null,
            'job_id_hint' => null,
            'portal_sourceable_type' => null,
            'portal_sourceable_id' => null,
            'is_manifest_member' => str_starts_with($role, 'hls_'),
        ];

        if (($parts[0] ?? null) === 'media'
            && $this->isUuid($parts[1] ?? null)
            && ctype_digit((string) ($parts[2] ?? ''))
        ) {
            $result['storage_layout'] = 'nbx_work';
            $result['logical_asset_key'] = implode('/', array_slice($parts, 0, 3));
            $result['asset_id_hint'] = $parts[1];
            $result['source_id_hint'] = (int) $parts[2];
            if (! str_contains($key, '/hls/') && $role === 'download_asset') {
                $result['media_role'] = str_ends_with(strtolower($key), '_play.mp4')
                    ? 'faststart_mp4'
                    : 'source_original';
            }

            return $result;
        }

        if (($parts[0] ?? null) === 'videos' && ($parts[1] ?? null) === 'nbx' && isset($parts[2])) {
            $result['storage_layout'] = 'nbx_final';
            $result['logical_asset_key'] = implode('/', array_slice($parts, 0, 3));
            $result['job_id_hint'] = $parts[2];

            return $result;
        }

        if (($parts[0] ?? null) === 'videos'
            && in_array($parts[1] ?? null, ['movies', 'episodes'], true)
            && ctype_digit((string) ($parts[2] ?? ''))
        ) {
            $type = $parts[1] === 'movies' ? 'App\\Models\\Movie' : 'App\\Models\\Episode';
            $result['storage_layout'] = 'portal_legacy';
            $result['logical_asset_key'] = implode('/', array_slice($parts, 0, 3));
            $result['portal_sourceable_type'] = $type;
            $result['portal_sourceable_id'] = (int) $parts[2];

            return $result;
        }

        if ($this->isUuid($parts[0] ?? null) && ctype_digit((string) ($parts[1] ?? ''))) {
            $result['storage_layout'] = 'nbx_legacy';
            $result['logical_asset_key'] = implode('/', array_slice($parts, 0, 2));
            $result['asset_id_hint'] = $parts[0];
            $result['source_id_hint'] = (int) $parts[1];

            return $result;
        }

        return $result;
    }

    private function fallbackGroup(string $key): string
    {
        $directory = trim((string) dirname($key), '.');

        return substr($directory !== '' ? $directory : $key, 0, 191);
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}

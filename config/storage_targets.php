<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logical storage targets
    |--------------------------------------------------------------------------
    |
    | Same logical keys as Portal's config/storage_targets.php. NBX resolves
    | these using its OWN credentials/env — Portal only ever sends the key
    | (e.g. "contabo_nbx", "contabo_nb_nbx", "auto"), never secrets.
    |
    */

    'targets' => [

        // Legacy bucket ("nbx"). Falls back to the existing single-bucket
        // CONTABO_* / CONTABO_OBJECT_STORAGE_* vars so upgrading does not
        // require touching production env vars immediately.
        'contabo_nbx' => [
            'label' => 'NaraBox Legacy Storage — nbx',
            'provider' => 'contabo',
            // Defaults to the SAME disk key ("contabo") the app already used
            // for its single bucket — this target is meant to be
            // indistinguishable from current production behavior unless an
            // operator explicitly opts into a separate disk/credentials via
            // CONTABO_NBX_DISK.
            'disk' => env('CONTABO_NBX_DISK', 'contabo'),
            'endpoint' => env('CONTABO_NBX_ENDPOINT', env('CONTABO_ENDPOINT', env('CONTABO_OBJECT_STORAGE_ENDPOINT', 'https://usc1.contabostorage.com'))),
            'region' => env('CONTABO_NBX_REGION', env('CONTABO_REGION', env('CONTABO_OBJECT_STORAGE_REGION', 'usc1'))),
            'bucket' => env('CONTABO_NBX_BUCKET', env('CONTABO_BUCKET', env('CONTABO_OBJECT_STORAGE_BUCKET', 'nbx'))),
            'public_url' => env('CONTABO_NBX_PUBLIC_URL', env('CONTABO_PUBLIC_URL', env('CONTABO_OBJECT_STORAGE_PUBLIC_URL'))),
            'path_prefix' => env('CONTABO_NBX_PATH_PREFIX', env('CONTABO_OBJECT_STORAGE_PATH_PREFIX', 'videos')),
            'enabled' => (bool) env('CONTABO_NBX_ENABLED', true),
            'writable' => (bool) env('CONTABO_NBX_WRITABLE', true),
            'priority' => (int) env('CONTABO_NBX_PRIORITY', 20),
            'capacity_bytes' => (int) env('CONTABO_NBX_CAPACITY_BYTES', 536870912000), // 500GB
            'reserve_percent' => (float) env('CONTABO_NBX_RESERVE_PERCENT', 10),
            'known_used_bytes' => (int) env('CONTABO_NBX_KNOWN_USED_BYTES', 471073193164), // ~438.66 GB
            'known_used_at' => env('CONTABO_NBX_KNOWN_USED_AT'),
        ],

        // New, separate storage service/bucket ("nb-nbx"). Independent
        // credentials — the two Contabo services are not assumed to share
        // access keys.
        'contabo_nb_nbx' => [
            'label' => 'NaraBox Storage 2 — nb-nbx',
            'provider' => 'contabo',
            'disk' => env('CONTABO_NB_NBX_DISK', 'contabo_nb_nbx'),
            'endpoint' => env('CONTABO_NB_NBX_ENDPOINT', 'https://usc1.contabostorage.com'),
            'region' => env('CONTABO_NB_NBX_REGION', 'usc1'),
            'bucket' => env('CONTABO_NB_NBX_BUCKET', 'nb-nbx'),
            // Contabo's public object URLs require the tenant-ID-prefixed
            // path form ("https://{endpoint}/{tenantId}:{bucket}/...") even
            // for path-style buckets — the bare "/nb-nbx/..." form returns
            // {"message":"Unauthorized"}. Confirmed against the actual
            // nb-nbx service's tenant ID.
            'public_url' => env('CONTABO_NB_NBX_PUBLIC_URL', 'https://usc1.contabostorage.com/5fa286e37e8b403abc5b60ba900a5c3d:nb-nbx'),
            'path_prefix' => env('CONTABO_NB_NBX_PATH_PREFIX', env('CONTABO_OBJECT_STORAGE_PATH_PREFIX', 'videos')),
            'enabled' => (bool) env('CONTABO_NB_NBX_ENABLED', true),
            'writable' => (bool) env('CONTABO_NB_NBX_WRITABLE', true),
            'priority' => (int) env('CONTABO_NB_NBX_PRIORITY', 100),
            'capacity_bytes' => (int) env('CONTABO_NB_NBX_CAPACITY_BYTES', 536870912000), // 500GB
            'reserve_percent' => (float) env('CONTABO_NB_NBX_RESERVE_PERCENT', 10),
            'known_used_bytes' => (int) env('CONTABO_NB_NBX_KNOWN_USED_BYTES', 0),
            'known_used_at' => env('CONTABO_NB_NBX_KNOWN_USED_AT'),
        ],

        'r2_nbx' => [
            'label' => 'Cloudflare R2 — nbx',
            'provider' => 'cloudflare_r2',
            'disk' => env('CLOUDFLARE_R2_DISK', 'r2'),
            'endpoint' => env('CLOUDFLARE_R2_ENDPOINT', 'https://e31bdccee36e2432baee084144f9c6ae.r2.cloudflarestorage.com'),
            'region' => env('CLOUDFLARE_R2_REGION', 'auto'),
            'bucket' => env('CLOUDFLARE_R2_BUCKET', 'nbx'),
            'public_url' => env('CLOUDFLARE_R2_PUBLIC_URL', 'https://nbxgen.naraboxtv.com'),
            'path_prefix' => env('CLOUDFLARE_R2_PATH_PREFIX', 'videos'),
            'enabled' => (bool) env('CLOUDFLARE_R2_ENABLED', false),
            'writable' => (bool) env('CLOUDFLARE_R2_WRITABLE', true),
            'priority' => (int) env('CLOUDFLARE_R2_PRIORITY', 200),
            'capacity_bytes' => (int) env('CLOUDFLARE_R2_CAPACITY_BYTES', 10995116277760), // 10 TiB accounting guardrail.
            'reserve_percent' => (float) env('CLOUDFLARE_R2_RESERVE_PERCENT', 5),
            'known_used_bytes' => (int) env('CLOUDFLARE_R2_KNOWN_USED_BYTES', 0),
            'known_used_at' => env('CLOUDFLARE_R2_KNOWN_USED_AT'),
        ],

    ],

    'default_target' => env('MEDIA_STORAGE_DEFAULT_TARGET', 'auto'),
    'auto_selection_enabled' => (bool) env('MEDIA_STORAGE_AUTO_SELECTION_ENABLED', true),
    'legacy_target_key' => 'contabo_nbx',
    'usage_stale_after_minutes' => (int) env('MEDIA_STORAGE_USAGE_STALE_AFTER_MINUTES', 30),

    // Conservative multiplier applied to input size when estimating required
    // output capacity for a transcode job (HLS + faststart + temp copies can
    // exceed 1x the source size).
    'transcode_safety_multiplier' => (float) env('MEDIA_STORAGE_TRANSCODE_SAFETY_MULTIPLIER', 2.0),
];

<?php

return [
    'enabled' => (bool) env('NBX_ENGINE_ENABLED', true),
    'public_url' => rtrim((string) env('NBX_ENGINE_PUBLIC_URL', env('CDN_APP_URL', env('APP_URL', ''))), '/'),
    'api_key' => (string) env('NBX_ENGINE_API_KEY', ''),
    'webhook_secret' => (string) env('NBX_ENGINE_WEBHOOK_SECRET', ''),
    'webhook_queue' => (string) env('NBX_WEBHOOK_QUEUE', 'nbx-webhook'),
    'webhook_retry_times' => (int) env('NBX_WEBHOOK_RETRY_TIMES', 3),
    'webhook_retry_sleep_ms' => (int) env('NBX_WEBHOOK_RETRY_SLEEP_MS', 1500),
    'webhook_timeout' => (int) env('NBX_WEBHOOK_TIMEOUT', 20),

    'default_storage' => (string) env('NBX_DEFAULT_STORAGE', 'contabo'),
    'work_storage' => (string) env('NBX_WORK_STORAGE', env('FILESYSTEM_DISK', 'public')),
    'allow_local_storage' => (bool) env('NBX_ALLOW_LOCAL_STORAGE', true),
    'allow_1080p' => (bool) env('NBX_ALLOW_1080P', false),

    'default_faststart' => (bool) env('NBX_DEFAULT_FASTSTART', true),
    'default_hls_480' => (bool) env('NBX_DEFAULT_HLS_480', true),
    'default_hls_720' => (bool) env('NBX_DEFAULT_HLS_720', false),
    'default_hls_1080' => (bool) env('NBX_DEFAULT_HLS_1080', false),

    'max_upload_mb' => (int) env('NBX_MAX_UPLOAD_SIZE_MB', env('MAX_UPLOAD_MB', 2048)),
    'upload_session_ttl_minutes' => (int) env('NBX_UPLOAD_SESSION_TTL_MINUTES', 60),
    'allowed_upload_extensions' => array_values(array_filter(array_map(
        static fn (string $extension): string => strtolower(trim($extension)),
        explode(',', (string) env('NBX_ALLOWED_UPLOAD_EXTENSIONS', 'mp4,m4v,mov,mkv,webm,avi,mpeg,mpg,ts'))
    ))),
    'allowed_upload_mimes' => array_values(array_filter(array_map(
        static fn (string $mime): string => strtolower(trim($mime)),
        explode(',', (string) env('NBX_ALLOWED_UPLOAD_MIMES', 'video/mp4,video/x-m4v,video/quicktime,video/webm,video/x-msvideo,video/x-matroska,video/mpeg,video/mp2t,application/octet-stream'))
    ))),
    'temp_dir' => (string) env('NBX_TEMP_DIR', storage_path('app/nbx/tmp')),
    'work_dir' => (string) env('NBX_WORK_DIR', storage_path('app/nbx/work')),
    'output_dir' => (string) env('NBX_OUTPUT_DIR', storage_path('app/nbx/output')),

    'ssrf' => [
        'blocked_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('NBX_BLOCKED_FETCH_HOSTS', 'localhost,localhost.localdomain'))
        ))),
        'max_redirects' => (int) env('NBX_FETCH_MAX_REDIRECTS', 5),
        'connect_timeout' => (int) env('NBX_FETCH_CONNECT_TIMEOUT', 30),
        'timeout' => (int) env('NBX_FETCH_TIMEOUT', 7200),
    ],
];

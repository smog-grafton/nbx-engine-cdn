<?php

return [
    'app_url' => env('CDN_APP_URL', env('APP_URL')),

    'disk' => env('FILESYSTEM_DISK', 'public'),
    'use_direct_storage_urls' => (bool) env('CDN_USE_DIRECT_STORAGE_URLS', true),
    'import_queue' => (string) env('CDN_IMPORT_QUEUE', 'default'),
    'optimization_queue' => (string) env('CDN_OPTIMIZATION_QUEUE', 'optimization'),
    'serialize_optimization_jobs' => (bool) env('CDN_SERIALIZE_OPTIMIZATION_JOBS', true),
    'optimization_overlap_lock_seconds' => (int) env('CDN_OPTIMIZATION_OVERLAP_LOCK_SECONDS', 14400),
    'admin_sources_polling_interval' => (string) env('CDN_ADMIN_SOURCES_POLLING_INTERVAL', '15s'),
    'admin_queue_stats_polling_interval' => (string) env('CDN_ADMIN_QUEUE_STATS_POLLING_INTERVAL', '60s'),
    'optimization_dashboard_batch_limit' => (int) env('CDN_OPTIMIZATION_DASHBOARD_BATCH_LIMIT', 10),
    'api_token_touch_interval_seconds' => (int) env('CDN_API_TOKEN_TOUCH_INTERVAL_SECONDS', 300),

    'default_import_mode' => in_array(env('CDN_DEFAULT_IMPORT_MODE', 'queue'), ['now', 'queue'], true)
        ? env('CDN_DEFAULT_IMPORT_MODE', 'queue')
        : 'queue',

    'ingest_secret' => (string) env('CDN_INGEST_SECRET', ''),

    'portal_fetch_proxy_url' => (string) env('PORTAL_FETCH_PROXY_URL', ''),
    'portal_fetch_proxy_token' => (string) env('PORTAL_FETCH_PROXY_TOKEN', ''),
    'portal_worker_sync_url' => (string) env('PORTAL_WORKER_SYNC_URL', ''),
    'portal_worker_api_token' => (string) env('PORTAL_WORKER_API_TOKEN', ''),
    'telebot_api_url' => rtrim((string) env('TELEBOT_API_URL', ''), '/'),
    'telebot_api_token' => (string) env('TELEBOT_API_TOKEN', ''),
    'telebot_timeout' => (int) env('TELEBOT_TIMEOUT', 30),
    'python_worker_enabled' => (bool) env('CDN_PYTHON_WORKER_ENABLED', false),
    'python_worker_queue_url' => (string) env('CDN_PYTHON_WORKER_QUEUE_URL', ''),
    'python_worker_auth_token' => (string) env('CDN_PYTHON_WORKER_AUTH_TOKEN', ''),
    'laravel_worker_enabled' => (bool) env('CDN_LARAVEL_WORKER_ENABLED', false),
    'laravel_worker_pull_enabled' => (bool) env('CDN_LARAVEL_WORKER_PULL_ENABLED', true),
    'laravel_worker_api_url' => rtrim((string) env('CDN_LARAVEL_WORKER_API_URL', ''), '/'),
    'laravel_worker_api_token' => (string) env('CDN_LARAVEL_WORKER_API_TOKEN', ''),
    'laravel_worker_artifact_fetch_timeout' => (int) env('CDN_WORKER_ARTIFACT_FETCH_TIMEOUT', 600),
    'laravel_worker_artifact_connect_timeout' => (int) env('CDN_WORKER_ARTIFACT_CONNECT_TIMEOUT', 60),
    'laravel_worker_artifact_retry_times' => (int) env('CDN_WORKER_ARTIFACT_RETRY_TIMES', 3),
    'laravel_worker_artifact_retry_sleep_ms' => (int) env('CDN_WORKER_ARTIFACT_RETRY_SLEEP_MS', 2000),
    'worker_artifacts_temp_disk' => env('CDN_WORKER_ARTIFACTS_TEMP_DISK', 'local'),
    'worker_artifacts_temp_path' => env('CDN_WORKER_ARTIFACTS_TEMP_PATH', 'worker-artifacts'),
    'hls_artifacts_queue' => env('CDN_HLS_ARTIFACTS_QUEUE', 'optimization'),
    'ffmpeg_binary' => (string) env('FFMPEG_BIN', env('CDN_FFMPEG_BINARY', '/usr/bin/ffmpeg')),
    'ffprobe_binary' => (string) env('FFPROBE_BIN', env('CDN_FFPROBE_BINARY', '/usr/bin/ffprobe')),
    'compress_before_playback' => (bool) env('CDN_COMPRESS_BEFORE_PLAYBACK', true),
    // SAFETY: Default is now false. Must be explicitly set to true in .env.
    // Deleting originals is irreversible; require deliberate opt-in per deployment.
    'compress_delete_original' => (bool) env('CDN_COMPRESS_DELETE_ORIGINAL', false),
    'compress_video_codec' => (string) env('CDN_COMPRESS_VIDEO_CODEC', 'libx264'),
    'compress_audio_codec' => (string) env('CDN_COMPRESS_AUDIO_CODEC', 'aac'),
    'compress_audio_bitrate' => (string) env('CDN_COMPRESS_AUDIO_BITRATE', '128k'),
    'compress_crf' => (int) env('CDN_COMPRESS_CRF', 23),
    'compress_preset' => (string) env('CDN_COMPRESS_PRESET', 'medium'),
    'compress_max_height' => (int) env('CDN_COMPRESS_MAX_HEIGHT', 0),
    'enable_hls' => (bool) env('CDN_ENABLE_HLS', true),
    'hls_profiles' => array_values(array_filter(array_map('trim', explode(',', (string) env('CDN_HLS_PROFILES', '480'))))),
    'hls_variant_delay_seconds' => (int) env('CDN_HLS_VARIANT_DELAY_SECONDS', 2),

    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 2048),

    'allowed_video_extensions' => array_values(array_filter(array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', (string) env('ALLOWED_VIDEO_EXTENSIONS', 'mp4,mkv,webm,avi,mov,m4v'))
    ))),
];

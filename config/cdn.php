<?php

return [
    'app_url' => env('CDN_APP_URL', env('APP_URL')),

    'disk' => env('FILESYSTEM_DISK', 'public'),
    'use_direct_storage_urls' => (bool) env('CDN_USE_DIRECT_STORAGE_URLS', true),
    'import_queue' => (string) env('CDN_IMPORT_QUEUE', 'default'),
    'optimization_queue' => (string) env('CDN_OPTIMIZATION_QUEUE', 'optimization'),
    'serialize_optimization_jobs' => (bool) env('CDN_SERIALIZE_OPTIMIZATION_JOBS', true),
    // Tiered concurrency budgets used in place of one global "1 at a time"
    // lock when serialize_optimization_jobs is true. transcode_concurrency
    // covers CPU-bound work (compression, HLS encoding — CDN_FFMPEG_THREADS=0
    // lets a single libx264 encode already use every core, so keep this low
    // on a small VPS). remux_concurrency covers cheap, I/O-bound fast-start
    // copies, which barely touch the CPU and can safely run alongside a
    // transcode. See App\Queue\Middleware\ConcurrencyPool.
    'transcode_concurrency' => (int) env('CDN_TRANSCODE_CONCURRENCY', 1),
    'remux_concurrency' => (int) env('CDN_REMUX_CONCURRENCY', 3),
    'optimization_overlap_lock_seconds' => (int) env('CDN_OPTIMIZATION_OVERLAP_LOCK_SECONDS', 25200),
    'optimization_overlap_release_seconds' => (int) env('CDN_OPTIMIZATION_OVERLAP_RELEASE_SECONDS', 30),
    'optimization_stale_minutes' => (int) env('CDN_OPTIMIZATION_STALE_MINUTES', 20),
    'queued_optimization_missing_job_grace_seconds' => (int) env('CDN_QUEUED_OPTIMIZATION_MISSING_JOB_GRACE_SECONDS', 120),
    'queued_optimization_reserved_rescue_seconds' => (int) env('CDN_QUEUED_OPTIMIZATION_RESERVED_RESCUE_SECONDS', 300),
    'optimization_recovery_batch_limit' => (int) env('CDN_OPTIMIZATION_RECOVERY_BATCH_LIMIT', 10),
    // FFmpeg itself is not timed out by default. Health is determined from
    // PID, heartbeat and output growth, not elapsed wall time.
    'ffmpeg_timeout_seconds' => (int) env('CDN_FFMPEG_TIMEOUT_SECONDS', 0),
    'ffmpeg_idle_timeout_seconds' => (int) env('CDN_FFMPEG_IDLE_TIMEOUT_SECONDS', 0),
    // Laravel queue timeout; zero disables SIGALRM for media jobs.
    'ffmpeg_job_timeout_seconds' => (int) env('CDN_FFMPEG_JOB_TIMEOUT_SECONDS', 0),
    'ffmpeg_heartbeat_seconds' => (int) env('CDN_FFMPEG_HEARTBEAT_SECONDS', 10),
    'ffmpeg_diagnostics_max_bytes' => (int) env('CDN_FFMPEG_DIAGNOSTICS_MAX_BYTES', 12000),
    // Full packet scans are only run to resolve a genuine duration conflict.
    // Zero means no elapsed-time cutoff; incomplete scans are never accepted
    // as media evidence.
    'ffprobe_packet_timeline_timeout_seconds' => (int) env('CDN_FFPROBE_PACKET_TIMELINE_TIMEOUT_SECONDS', 0),
    'queue_warn_delay_seconds' => (int) env('CDN_QUEUE_WARN_DELAY_SECONDS', 30),
    'ffmpeg_threads' => (int) env('CDN_FFMPEG_THREADS', 0),
    // ffprobe previously had no timeout anywhere in the codebase and could
    // hang a worker indefinitely on a large/borderline-corrupt file.
    'ffprobe_timeout_seconds' => (int) env('CDN_FFPROBE_TIMEOUT_SECONDS', 120),
    // Floor reserved on top of the estimated requirement by LocalDiskSpaceGuard
    // before a download/transcode/HLS stage is allowed to start.
    'disk_space_reserve_bytes' => (int) env('CDN_DISK_SPACE_RESERVE_BYTES', 1073741824),
    'admin_sources_polling_interval' => (string) env('CDN_ADMIN_SOURCES_POLLING_INTERVAL', '15s'),
    'admin_queue_stats_polling_interval' => (string) env('CDN_ADMIN_QUEUE_STATS_POLLING_INTERVAL', '60s'),
    'optimization_dashboard_batch_limit' => (int) env('CDN_OPTIMIZATION_DASHBOARD_BATCH_LIMIT', 10),
    'api_token_touch_interval_seconds' => (int) env('CDN_API_TOKEN_TOUCH_INTERVAL_SECONDS', 300),

    // Remote sources are fetched as small, verified byte ranges. One MiB is
    // deliberately conservative for PHP/Cloudflare download scripts that close
    // larger responses early; completed bytes survive every retry.
    'remote_fetch_chunk_bytes' => (int) env('CDN_REMOTE_FETCH_CHUNK_BYTES', 1048576),
    'remote_fetch_part_attempts' => (int) env('CDN_REMOTE_FETCH_PART_ATTEMPTS', 20),
    'remote_fetch_retry_wait_ms' => (int) env('CDN_REMOTE_FETCH_RETRY_WAIT_MS', 1000),
    'remote_fetch_probe_timeout' => (int) env('CDN_REMOTE_FETCH_PROBE_TIMEOUT', 45),
    'remote_fetch_chunk_timeout' => (int) env('CDN_REMOTE_FETCH_CHUNK_TIMEOUT', 90),
    'remote_fetch_user_agent' => (string) env('CDN_REMOTE_FETCH_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36'),

    'default_import_mode' => in_array(env('CDN_DEFAULT_IMPORT_MODE', 'queue'), ['now', 'queue'], true)
        ? env('CDN_DEFAULT_IMPORT_MODE', 'queue')
        : 'queue',

    'ingest_secret' => (string) env('CDN_INGEST_SECRET', ''),

    'legacy_cdn_api_base_url' => rtrim((string) env('LEGACY_CDN_API_BASE_URL', ''), '/'),
    'legacy_cdn_api_token' => (string) env('LEGACY_CDN_API_TOKEN', ''),
    'legacy_cdn_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NBX_LEGACY_CDN_HOSTS', 'cdn.naraboxtv.com')),
    ))),

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
    'compress_audio_bitrate_mono' => (string) env('CDN_COMPRESS_AUDIO_BITRATE_MONO', '96k'),
    'compress_audio_bitrate_surround' => (string) env('CDN_COMPRESS_AUDIO_BITRATE_SURROUND', '160k'),
    // Null means select the resolution-aware CRF profile in the optimizer.
    // Set CDN_COMPRESS_CRF only when a deployment deliberately needs a fixed
    // quality target for every job.
    'compress_crf' => env('CDN_COMPRESS_CRF'),
    'compress_preset' => (string) env('CDN_COMPRESS_PRESET', 'fast'),
    'compress_max_height' => (int) env('CDN_COMPRESS_MAX_HEIGHT', 0),
    // CRF remains the quality control; these resolution-aware caps prevent a
    // high-bitrate source from silently recreating its original size.
    'compress_maxrate_480p' => (string) env('CDN_COMPRESS_MAXRATE_480P', '1200k'),
    'compress_bufsize_480p' => (string) env('CDN_COMPRESS_BUFSIZE_480P', '2400k'),
    'compress_maxrate_720p' => (string) env('CDN_COMPRESS_MAXRATE_720P', '2500k'),
    'compress_bufsize_720p' => (string) env('CDN_COMPRESS_BUFSIZE_720P', '5000k'),
    'compress_maxrate_1080p' => (string) env('CDN_COMPRESS_MAXRATE_1080P', '4500k'),
    'compress_bufsize_1080p' => (string) env('CDN_COMPRESS_BUFSIZE_1080P', '9000k'),
    'compress_smaller_maxrate_720p' => (string) env('CDN_COMPRESS_SMALLER_MAXRATE_720P', '1800k'),
    'compress_smaller_bufsize_720p' => (string) env('CDN_COMPRESS_SMALLER_BUFSIZE_720P', '3600k'),
    'compress_ineffective_savings_percent' => (float) env('CDN_COMPRESS_INEFFECTIVE_SAVINGS_PERCENT', 5),
    'compress_skip_bitrate_480p' => (int) env('CDN_COMPRESS_SKIP_BITRATE_480P', 900000),
    'compress_skip_bitrate_720p' => (int) env('CDN_COMPRESS_SKIP_BITRATE_720P', 1500000),
    'compress_skip_bitrate_1080p' => (int) env('CDN_COMPRESS_SKIP_BITRATE_1080P', 2200000),
    'enable_hls' => (bool) env('CDN_ENABLE_HLS', true),
    'hls_profiles' => array_values(array_filter(array_map('trim', explode(',', (string) env('CDN_HLS_PROFILES', '480'))))),
    'hls_variant_delay_seconds' => (int) env('CDN_HLS_VARIANT_DELAY_SECONDS', 2),

    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 2048),

    'allowed_video_extensions' => array_values(array_filter(array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', (string) env('ALLOWED_VIDEO_EXTENSIONS', 'mp4,m4v,mov,mkv,webm,avi,mpeg,mpg,ts,m2ts'))
    ))),
];

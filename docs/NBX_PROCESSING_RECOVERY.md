# NBX processing recovery

## Required production values

```dotenv
DB_QUEUE_RETRY_AFTER=28800
CDN_OPTIMIZATION_OVERLAP_LOCK_SECONDS=25200
CDN_OPTIMIZATION_STALE_MINUTES=20
CDN_FFMPEG_TIMEOUT_SECONDS=21600
CDN_FFMPEG_HEARTBEAT_SECONDS=10
CDN_FFMPEG_DIAGNOSTICS_MAX_BYTES=12000
CDN_FFMPEG_THREADS=0
CDN_COMPRESS_PRESET=fast
CDN_COMPRESS_SKIP_BITRATE_480P=900000
CDN_COMPRESS_SKIP_BITRATE_720P=1500000
CDN_COMPRESS_SKIP_BITRATE_1080P=2200000
```

`DB_QUEUE_RETRY_AFTER` must remain greater than the worker/job timeout. A value
of `0` for `CDN_FFMPEG_THREADS` lets FFmpeg use the CPUs available to the
container.

## Deploy

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

Redeploy or restart Supervisor after changing queue environment values so the
workers receive the new timeouts.

## Recover the reported job

```bash
php artisan media:recover-stale-optimizations \
  --job=a349202b-c186-48aa-8287-9d95e8f12172 \
  --stale-minutes=20
```

The command restores a durable input before quarantining a legacy artifact,
assigns a new attempt ID, and safely queues normalization. It does not delete
the legacy object.

Recover the remaining stale jobs gradually:

```bash
php artisan media:recover-stale-optimizations --limit=30 --stale-minutes=20
php artisan nbx:reconcile-artifacts --limit=200
```

The scheduler also recovers one stale source every five minutes to avoid a CPU
or disk spike.

## What the stages mean

- `queued`: waiting for the optimization worker.
- `probing`: reading the input container, codecs, size, bitrate and duration.
- `faststarting`: remuxing an already compatible/efficient file to MP4.
- `compressing`: transcoding video to H.264/AAC because it is incompatible,
  oversized for the requested resolution, or above the configured bitrate.
- `validating`: probing the output and checking duration, codecs, pixel format,
  MP4 extension and `moov` atom order.
- `publishing`: uploading the verified output to final storage.
- `hls_480p`, `hls_720p`, `hls_1080p`: generating the requested HLS variants.
- `ready`, `partially_completed`, `failed`: terminal outcomes.

`processing_diagnostics` stores only a bounded tail of useful FFmpeg output.
Progress lines are parsed into heartbeats rather than written to the database.

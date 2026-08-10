# NBX processing recovery

## Required production values

```dotenv
DB_QUEUE_RETRY_AFTER=28800
CDN_OPTIMIZATION_OVERLAP_LOCK_SECONDS=25200
CDN_OPTIMIZATION_STALE_MINUTES=20
CDN_TRANSCODE_CONCURRENCY=1
CDN_REMUX_CONCURRENCY=3
CDN_FFMPEG_TIMEOUT_SECONDS=0
CDN_FFMPEG_IDLE_TIMEOUT_SECONDS=0
CDN_FFMPEG_HEARTBEAT_SECONDS=10
CDN_FFMPEG_DIAGNOSTICS_MAX_BYTES=12000
CDN_FFPROBE_PACKET_TIMELINE_TIMEOUT_SECONDS=0
CDN_QUEUE_WARN_DELAY_SECONDS=30
CDN_FFMPEG_THREADS=0
CDN_COMPRESS_PRESET=fast
CDN_COMPRESS_SKIP_BITRATE_480P=900000
CDN_COMPRESS_SKIP_BITRATE_720P=1500000
CDN_COMPRESS_SKIP_BITRATE_1080P=2200000
```

`DB_QUEUE_RETRY_AFTER` must remain greater than any explicit worker/job timeout. A value
of `0` for `CDN_FFMPEG_THREADS` lets FFmpeg use the CPUs available to the
container — keep `CDN_TRANSCODE_CONCURRENCY` at `1` unless threads are also
capped per job, since one full-core encode can already saturate a small VPS.

FFmpeg wall-clock and idle limits are disabled here intentionally. A quiet
encoder can still be flushing indexes or muxing a multi-gigabyte file; the
worker PID, heartbeat, output growth and exit code are the evidence used to
decide whether it died. Do not replace these values with a shorter timeout to
work around a queue delay.

## How staleness detection actually decides "dead"

`media:recover-stale-optimizations` no longer treats every old heartbeat as
proof of death. Two guards were added because they were previously the two
most common causes of a job being marked `failed` while FFmpeg kept running
to completion in the background:

1. A source still in the `queued` stage is never reaped — its heartbeat
   reflects dispatch time, not a stuck process, and under
   `CDN_SERIALIZE_OPTIMIZATION_JOBS=true` queue depth alone can exceed
   `--stale-minutes` before a job ever gets its turn.
2. Before reaping a source that HAS started, the command checks
   `App\Services\ProcessingLiveness::isAlive()` — a short-TTL marker
   (refreshed on every FFmpeg heartbeat and stage transition) that answers
   "is a worker still genuinely touching this row?" independently of the
   database heartbeat columns. Only a source with no fresh liveness marker
   is actually reaped.

## Deploy

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

Redeploy or restart Supervisor after changing queue environment values so the
workers receive the new timeouts.

## Recover the reported job

Before retrying a duration failure, collect the exact source evidence without
changing the job. For the reported source this is:

```bash
php artisan media:inspect-timeline 237
```

If the input packet timeline ends near the MP4 duration (about 4266 seconds in
the report) while the input container claims 4840 seconds, the MKV metadata is
malformed and a direct MKV → MP4 transcode is valid. If packets continue toward
4840 seconds, the output is genuinely truncated and must remain rejected; the
command prints both sides of that distinction.

When the original is present, retry only the derivative stage—do not click an
import retry that asks Teletyde to fetch the 1.12 GB file again:

```bash
php artisan media:retry-optimization 237
```

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

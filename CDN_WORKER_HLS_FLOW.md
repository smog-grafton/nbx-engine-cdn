# CDN worker HLS flow (pull-based)

## Overview

The CDN keeps **source ingestion** and **MP4 faststart** locally. When the Laravel worker is enabled in **pull mode**, the CDN sends only a **download URL** (optimized MP4) to the worker; the worker generates HLS and returns an **artifact download URL**. The CDN then **pulls** the HLS ZIP from the worker, extracts and validates it, and installs it under `media/{asset_id}/{source_id}/hls`.

## Current processing flow

1. **Playback processing** – After a source is `ready`, `MediaSourceService::queuePlaybackProcessing()` runs.
2. **Faststart (always local)** – Chain: `OptimizeMp4FaststartJob` then `ProcessHlsAfterFaststartJob`.
3. **HLS decision** – `ProcessHlsAfterFaststartJob`:
   - If `cdn.laravel_worker_enabled` and `cdn.laravel_worker_pull_enabled` and HLS enabled: call `queueLaravelWorkerPlaybackProcessing()` (POST to worker with **MP4 play URL** so worker downloads optimized file), set `hls_worker_status = 'queued'`.
   - Else: dispatch `GenerateHlsVariantsJob` locally.
4. **Worker callback** – Worker POSTs to `POST /api/v1/media/worker/callback` with `artifact_download_url`, `artifact_expires_at`, `quality_status`, `qualities_json`, `external_id`. CDN stores these on `MediaSource` and sets `hls_worker_status = 'artifact_ready'`, then dispatches **FetchWorkerHlsArtifactJob**.
5. **Fetch job** – Downloads ZIP from artifact URL (with `CDN_LARAVEL_WORKER_API_TOKEN`), saves to temp disk, extracts to temp dir, validates `master.m3u8` and variant playlists/segments, moves contents to `media/{asset_id}/{source_id}/hls`, updates `hls_master_path`, `qualities_json`, `playback_type`, `hls_worker_status` (`completed`/`partial`), cleans temp, calls worker `POST /api/v1/artifacts/{externalId}/ack`.

## Key tables / columns (media_sources)

- **hls_worker_status** – `queued` | `artifact_ready` | `fetching` | `installing` | `completed` | `partial` | `failed`
- **hls_worker_artifact_url** – Temporary URL to download HLS ZIP from worker
- **hls_worker_artifact_expires_at** – When the artifact URL expires
- **hls_worker_last_error** – Last error message if fetch/install failed
- **hls_worker_external_id** – Worker request UUID (used for ack)
- **hls_worker_quality_status** – `completed` or `partial` from worker

## Key jobs and services

- **ProcessHlsAfterFaststartJob** – Runs after faststart; either sends source to worker (pull mode) or dispatches `GenerateHlsVariantsJob`.
- **FetchWorkerHlsArtifactJob** – Downloads worker artifact ZIP, extracts, validates, moves to final HLS path, updates DB, calls worker ack.
- **MediaSourceService::queueLaravelWorkerPlaybackProcessing()** – Sends submit payload to worker using **buildMp4PlayUrl** (optimized file) or buildDownloadUrl.

## Config / env

- **CDN_LARAVEL_WORKER_ENABLED** – Use external worker for HLS.
- **CDN_LARAVEL_WORKER_PULL_ENABLED** – When true, CDN pulls HLS ZIP from worker (artifact URL in callback); when false, legacy push behaviour if worker uploads back.
- **CDN_LARAVEL_WORKER_API_URL**, **CDN_LARAVEL_WORKER_API_TOKEN** – Worker API (submit + artifact download + ack).
- **CDN_WORKER_ARTIFACT_FETCH_TIMEOUT**, **CDN_WORKER_ARTIFACT_RETRY_TIMES**, **CDN_WORKER_ARTIFACT_RETRY_SLEEP_MS** – Download timeout and retries for artifact ZIP.
- **CDN_WORKER_ARTIFACTS_TEMP_DISK**, **CDN_WORKER_ARTIFACTS_TEMP_PATH** – Temp location for ZIP and extraction (e.g. `local`, `worker-artifacts`).
- **CDN_HLS_ARTIFACTS_QUEUE** – Queue name for `FetchWorkerHlsArtifactJob` (default `optimization`).

## Running the optimization queue (required for HLS to appear)

`FetchWorkerHlsArtifactJob` is queued to the **optimization** queue when the worker callbacks with `artifact_ready`. If nothing processes that queue, the job never runs: the source stays at **Optimize: PROCESSING** and no HLS files are written to `media/{asset_id}/{source_id}/hls`.

**You must run a queue worker** that processes the `optimization` queue, for example:

- **Cron (recommended):** Run every 1–2 minutes:
  - `php artisan media:process-optimization-queue --max-jobs=5`
- **Or a long-running worker:** `php artisan queue:work --queue=optimization --tries=3`

If the CDN runs under a control panel (e.g. DirectAdmin) without a dedicated queue worker, use the built-in trigger (if available): `/_run/process-optimization-queue` or `/_run/optimization` so the optimization queue is processed periodically.

**Manual fallback:** For a source that shows **HLS Worker: artifact_ready** but no `hls` folder (because the queue was not processed), open the source in Filament and use **Run fetch now**. This runs the artifact fetch synchronously in the request and installs the HLS ZIP without needing the queue. Large ZIPs may take several minutes.

When the fetch job **fails** (e.g. download HTTP error, invalid ZIP, missing master.m3u8), the source is updated to **Optimize: failed** and **optimize_error** / **hls_worker_last_error** are set; check `storage/logs/laravel.log` for `FetchWorkerHlsArtifactJob` entries to see why.

## Migrations

- **2026_03_09_120000_add_hls_worker_columns_to_media_sources_table** – Adds `hls_worker_status`, `hls_worker_artifact_url`, `hls_worker_artifact_expires_at`, `hls_worker_last_error`, `hls_worker_external_id`, `hls_worker_quality_status` (with hasColumn checks).

## Filament

- **MediaSourcesRelationManager** – Columns (toggleable): **HLS Worker** (badge), **Worker Error**. Description updated to mention pull mode and env vars.

## Backward compatibility

- If the worker callback does **not** include `artifact_download_url` but includes `optimized_path` / `hls_master_path`, the callback is handled as before (direct DB update, no fetch job).

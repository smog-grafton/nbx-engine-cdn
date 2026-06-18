# CDN MP4 Deletion Bug — Forensic Investigation & Fix Summary

**Incident Date:** 2026-03-15
**Severity:** CRITICAL — Irreversible data loss (original MP4 files deleted in production)
**Status:** Root cause identified. Immediate mitigations deployed. Further action required in production.

---

## 1. The Real Pipeline (as it existed at time of incident)

```
Remote fetch (ImportRemoteMediaSourceJob)
    → sets storage_path = media/{asset_id}/{source_id}/filename.mp4
    → status = ready
    → calls queuePlaybackProcessing()

queuePlaybackProcessing() dispatches a chained job:
    [1] OptimizeMp4FaststartJob
        → if compress_enabled=true AND CDN_COMPRESS_BEFORE_PLAYBACK=true:
              runs FFmpeg compression transcode → output: filename_play.mp4
              on SUCCESS + CDN_COMPRESS_DELETE_ORIGINAL=true:
                  **DELETES** original filename.mp4
              updates storage_path = filename_play.mp4
        → else if mp4 input: runs faststart copy (no transcode, no delete)

    [2] ProcessHlsAfterFaststartJob
        → if CDN_LARAVEL_WORKER_ENABLED=true:
              sends filename_play.mp4 URL to external Laravel worker
              sets optimize_status = processing
              worker generates HLS zip artifact on remote server
        → else: dispatches GenerateHlsVariantsJob (local FFmpeg HLS)

    [3] FetchWorkerHlsArtifactJob (scheduled every 2 min via scheduler)
        → polls for artifact_ready status
        → downloads HLS zip from worker, extracts to media/{asset_id}/{source_id}/hls/
        → on FAILURE: sets optimize_status = failed, hls_worker_status = failed

Scheduler (every 5 min): media:retry-failed-optimizations
    → finds sources WHERE optimize_status = failed
    → calls queuePlaybackProcessing() AGAIN → restarts FULL pipeline including compression
```

---

## 2. The Exact Bug

### Bug A — The Primary Deletion Bug

**Files:** `config/cdn.php`, `app/Jobs/OptimizeMp4FaststartJob.php`

**What happened:**

1. A new compression feature was introduced. The migration `2026_03_15_000001_add_compress_enabled_to_media_sources_table.php` added `compress_enabled = true` (DEFAULT) to **all existing rows** in `media_sources`.

2. The config `CDN_COMPRESS_DELETE_ORIGINAL` defaulted to `true` in both `config/cdn.php` and `.env.example`.

3. When `CDN_LARAVEL_WORKER_ENABLED=true` was deployed, the command `media:queue-pending-for-worker` was (likely) run. This sent **all** sources with `optimize_status IN ('pending', 'failed')` through `queuePlaybackProcessing()` — including historical sources 1–372.

4. `OptimizeMp4FaststartJob` ran on these old sources:
   - FFmpeg compression succeeded (server had resources at that point)
   - `maybeDeleteOriginalAfterCompression()` deleted the original `filename_naraboxtv_com.mp4`
   - `storage_path` was updated to `filename_naraboxtv_com_play.mp4`

5. `ProcessHlsAfterFaststartJob` sent the `_play.mp4` to the Laravel worker.

6. The worker returned **HTTP 500** on every request (due to a PHP parse error in `97CA5761_MediaSourcesRelationManager.php` on the worker/production server).

7. `FetchWorkerHlsArtifactJob::markFailed()` set `optimize_status = failed`.

**Result:** Original MP4 files for sources 1–372 were deleted. Only `_play.mp4` variants may remain.

---

### Bug B — The Infinite Retry Loop (ongoing damage risk)

**Files:** `routes/console.php` (scheduler), `app/Services/MediaSourceService.php`

**What happened:**

The scheduler runs `media:retry-failed-optimizations` every **5 minutes**. This command:
- Finds sources with `optimize_status = failed`
- Calls `queuePlaybackProcessing()` on ALL of them — **without checking whether faststart/compression already succeeded**

After the first run:
- `storage_path` was updated to `filename_play.mp4` (the compressed version)
- On re-queue, `queuePlaybackProcessing` resets `optimized_path = null` but **not** `storage_path`
- `OptimizeMp4FaststartJob` re-runs with `_play.mp4` as input
- `buildOptimizedPath()` generates a timestamped output: `filename_play_20260315HHMMSS.mp4`
- If compression succeeds, the `_play.mp4` is **deleted** → replaced by a timestamped file
- Worker fails again → retry again → new timestamp file created → previous deleted
- **This is a destructive infinite loop** that would progressively delete all working files

**For sources 388–401 (newer batch):**
FFmpeg was failing with `pthread_create() failed: Resource temporarily unavailable` (exit code 245 — too many threads). This is why their originals survived — compression never succeeded, so nothing was deleted. The files still exist.

---

### Bug C — No Distinction Between "Original" and "Compressed Working Copy"

**What happened:**

The `storage_path` field was being overwritten when compression succeeded. On retry, the system had no way to know whether `storage_path` was the true original import file or an already-compressed version. There was no `original_storage_path` field.

---

### Bug D — PHP Parse Error on Worker/Production Server

**File:** `app/Filament/Resources/MediaAssetResource/RelationManagers/97CA5761_MediaSourcesRelationManager.php` on the production server

**What happened:**
An auto-generated Filament cache file contained invalid PHP syntax (`faile`). This caused Laravel to crash with HTTP 500 on every worker request. The worker itself may have been fine, but the CDN couldn't receive valid responses.

**Evidence from logs:**
```
[2026-03-15 17:38:04] local.ERROR: syntax error, unexpected string content "faile", expecting "}" {
  "exception": "ParseError ... 97CA5761_MediaSourcesRelationManager.php:300"
}
```

---

## 3. Why Older Media (IDs 1–372) Were Affected

1. These sources were created before the compression feature and had `optimize_status = null` or `pending` (never processed through the new pipeline).
2. When the migration ran, all rows got `compress_enabled = true`.
3. When the worker was enabled and `media:queue-pending-for-worker` ran, these sources entered the pipeline.
4. FFmpeg compression **succeeded** for them (server resources were available at the time, unlike the 388–401 batch which hit thread limits).
5. Their originals were deleted. The `_play.mp4` files remained temporarily.
6. The retry loop continued operating on them, potentially deleting `_play.mp4` files too.

---

## 4. Whether Data Loss Is Still Ongoing

**As of investigation time:**

- `CDN_LARAVEL_WORKER_ENABLED=false` and `CDN_LARAVEL_WORKER_PULL_ENABLED=false` were disabled by the operator ~7 hours ago.
- **However**, the scheduler's `media:retry-failed-optimizations` is still running every 5 minutes in production and continues to re-queue failed sources.
- Sources 388–401 still have their original MP4 files (compression failing due to thread exhaustion saves them for now), but every 2 minutes the scheduler dispatches a new optimization attempt.
- When server resources free up (load drops, processes complete), compression will succeed and deletions will resume for 388–401.

**ACTIVE RISK:** If production has `CDN_COMPRESS_DELETE_ORIGINAL=true` in its `.env`, and the queue worker is running, data loss is continuing right now.

---

## 5. Immediate Mitigations Applied (to local codebase)

### a) Changed `CDN_COMPRESS_DELETE_ORIGINAL` default to `false`

**File:** `config/cdn.php` line 33
The default changed from `true` to `false`. This means any deployment not explicitly setting `CDN_COMPRESS_DELETE_ORIGINAL=true` will never delete originals.

**Action required in production:** Set `CDN_COMPRESS_DELETE_ORIGINAL=false` in production `.env` immediately, then reload the config cache.

### b) Added 6-layer safety guards in `maybeDeleteOriginalAfterCompression`

**File:** `app/Jobs/OptimizeMp4FaststartJob.php`

Guards added:
1. Only delete when it was a full compression transcode (not a faststart copy)
2. Must be explicitly opted in via `CDN_COMPRESS_DELETE_ORIGINAL=true`
3. Never delete if `original_path === optimized_path`
4. **Never delete if the "original" path contains `_play` suffix** — this catches the retry loop scenario where `storage_path` was already updated to a compressed file
5. Never delete if `original_storage_path` is set and differs from `storage_path` (means we're operating on a downstream copy, not the true original)
6. Verify replacement file exists and has non-zero size before deleting

All decisions are logged with full context (source ID, asset ID, paths, file sizes).

### c) Added `original_storage_path` column (migration `2026_03_15_000002`)

**File:** `database/migrations/2026_03_15_000002_add_original_storage_path_and_retry_count_to_media_sources.php`

- `original_storage_path` — immutable pointer to where the file was before any processing. Back-filled for existing rows where `storage_path` doesn't look like a `_play` variant.
- `optimize_retry_count` — tracks how many times optimization has been retried. Used to cap infinite loops.

### d) Fixed the infinite retry loop in scheduler

**File:** `routes/console.php`

`media:retry-failed-optimizations` now:
- Checks whether `is_faststart=true` AND `optimized_path` exists on disk
- If faststart already succeeded: calls `retryWorkerHlsOnly()` (HLS step only, no re-compression)
- If faststart has not succeeded: runs the full pipeline (safe to compress fresh original)
- Caps retries via `optimize_retry_count < max-retries` (default: 10)

### e) Added `retryWorkerHlsOnly()` method to `MediaSourceService`

Dispatches only `ProcessHlsAfterFaststartJob` without touching `storage_path` or re-running compression.

### f) Added file-existence check in `ProcessHlsAfterFaststartJob`

Before dispatching HLS generation, verifies the input file actually exists. If not, marks the source `optimize_status=failed` with an explanatory error rather than silently queuing a job that will fail later.

### g) Added fallback logging when worker submit fails in `ProcessHlsAfterFaststartJob`

If the worker submit returns an error, the job now falls through to local FFmpeg HLS generation instead of silently leaving the source in an ambiguous state.

### h) Added new artisan commands

- `media:audit-deleted-originals` — forensic tool to see which sources have missing `storage_path` files on disk (with CSV output option)
- `media:reset-worker-failed-to-pending` — resets worker-failed sources so the scheduler can retry the HLS step without re-running compression

---

## 6. Production Action Plan (Required)

### Immediate (do right now)

1. **Disable deletion in production `.env`:**
   ```
   CDN_COMPRESS_DELETE_ORIGINAL=false
   ```
   Then run: `php artisan config:cache`

2. **Stop the queue workers temporarily** (via Coolify or supervisor) to halt further processing while you fix the environment.

3. **Fix the PHP parse error** in `97CA5761_MediaSourcesRelationManager.php` on the production server. Run `php artisan filament:cache` or delete the auto-generated file. This is why the worker keeps returning HTTP 500.

4. **Deploy the code changes** from this commit to production.

5. **Run the new migration** on production:
   ```bash
   php artisan migrate
   ```

6. **Run the audit command** to understand the scope of damage:
   ```bash
   php artisan media:audit-deleted-originals --csv > /tmp/cdn_audit.csv
   ```

7. **Restart queue workers** once `.env` change is confirmed.

### After stabilization

8. **Audit which sources still have playable `_play.mp4` files** for the 1–372 range. Check if `storage_path` points to them and if they exist on disk.

9. **For sources 388–401:** Their original MP4s are still on disk. Run:
   ```bash
   php artisan media:reset-worker-failed-to-pending
   ```
   This will re-queue them for HLS-only retry (no compression, no deletion).

10. **If you want to re-enable the worker later:**
    Fix the worker's PHP parse error first. Then test with a single source before re-enabling globally.

---

## 7. How to Verify the Fix

After deploying:

```bash
# Confirm CDN_COMPRESS_DELETE_ORIGINAL is false
php artisan tinker
>>> config('cdn.compress_delete_original')
# Expected: false

# Audit missing files
php artisan media:audit-deleted-originals

# After disabling worker, manually queue one source for HLS-only retry
php artisan media:reset-worker-failed-to-pending --limit=1
# Then monitor logs for: "ProcessHlsAfterFaststartJob: dispatching local HLS generation"
# And confirm NO log line: "OptimizeMp4FaststartJob: deleting original after verified compression"
```

---

## 8. Files Changed in This Fix

| File | Change |
|------|--------|
| `config/cdn.php` | Default `compress_delete_original` changed to `false` |
| `.env.example` | `CDN_COMPRESS_DELETE_ORIGINAL=false` with safety comment |
| `app/Jobs/OptimizeMp4FaststartJob.php` | 6-layer safety guards + full logging in `maybeDeleteOriginalAfterCompression`; preserve `original_storage_path` |
| `app/Jobs/ProcessHlsAfterFaststartJob.php` | Input file existence check; fallback logging; worker submit fallthrough to local HLS |
| `app/Jobs/FetchWorkerHlsArtifactJob.php` | `markFailed` increments `optimize_retry_count` + richer logging |
| `app/Services/MediaSourceService.php` | `queuePlaybackProcessing` preserves `original_storage_path`; new `retryWorkerHlsOnly()` method |
| `app/Models/MediaSource.php` | Added `original_storage_path`, `optimize_retry_count` to `$fillable` |
| `routes/console.php` | Smart retry in `media:retry-failed-optimizations`; new `media:audit-deleted-originals` and `media:reset-worker-failed-to-pending` commands |
| `database/migrations/2026_03_15_000002_...php` | Adds `original_storage_path` and `optimize_retry_count` columns; back-fills existing rows |

---

## 9. Manual Recovery for Lost Files

Unfortunately, if original MP4 files for sources 1–372 were deleted from disk and there is no backup, they cannot be recovered from the CDN server itself.

**Recovery options:**
1. Check if a cloud backup/snapshot of the storage volume exists (check hosting provider / Coolify volume snapshots).
2. Re-download from source URLs: check `source_url` column in `media_sources` for `source_type = remote_fetch`. If the original download URL is still valid, `ImportRemoteMediaSourceJob` can re-fetch.
3. For VJ-uploaded content: contact the VJ to re-upload.
4. Accept loss and serve from `_play.mp4` where it still exists: the `buildMp4PlayUrl()` prefers `optimized_path` if it exists, so playback may still work if `_play.mp4` is on disk.

**Check playback viability for lost originals:**
```sql
SELECT id, media_asset_id, storage_path, original_storage_path, optimized_path,
       is_faststart, optimize_status, hls_worker_status
FROM media_sources
WHERE id BETWEEN 1 AND 372
ORDER BY id;
```

If `optimized_path` is set and the `_play.mp4` file exists, those sources are still playable via mp4. HLS can be regenerated locally once the server is stable (re-queue via `media:retry-failed-optimizations`).

---

## 10. Root Cause Summary (One Paragraph)

The root cause was a combination of: (1) a new compression+deletion feature introduced today with `CDN_COMPRESS_DELETE_ORIGINAL=true` as the default, applied retroactively to all historical media sources via a migration setting `compress_enabled=true` on every row; (2) the `media:queue-pending-for-worker` command (or equivalent scheduler trigger) submitting all old sources through the full pipeline, causing compression to succeed and originals to be deleted; (3) an infinite retry loop in the `media:retry-failed-optimizations` scheduler which, every 5 minutes, re-queued worker-failed sources for the FULL pipeline again (including compression), gradually deleting any remaining `_play.mp4` files; and (4) a PHP parse error on the production worker server causing every HLS worker request to return HTTP 500, meaning the system was perpetually stuck in the fail→retry→compress→delete cycle with no exit condition.

# NBX storage inventory and large-file recovery

## What changed

- Video probing ignores attached cover-art streams and records the selected video/audio stream.
- FFmpeg maps the first real video and first audio stream, drops data/subtitle streams, and normalizes output to even dimensions before `yuv420p` encoding.
- Final uploads hold one source file descriptor for the whole upload, verify the remote byte count, and preserve retry artifacts until every required output is verified.
- A final-storage failure no longer rewrites a successful optimization as an encoder failure.
- Portal discovery prefers the persisted NBX source, job, and asset identities before using a mutable URL.
- The Contabo admin reads an indexed inventory, groups HLS objects by package, and calculates byte totals by role, layout, and lifecycle.
- Cleanup starts as a review plan. A bucket object cannot use the orphan deletion path without explicit inventory confirmation, an approved plan item, and an expired grace period.

## Deployment order

Deploy NBX first:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

The Docker image includes a `media-maintenance` worker. A non-Docker deployment must run:

```bash
php artisan queue:work --queue=media-maintenance --tries=2 --timeout=21600
```

Then deploy Portal:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

## Build the first storage account

Queue the scan from **Admin → Storage → Contabo inventory**, or run it in a maintenance shell:

```bash
php artisan nbx:storage-inventory --sync
```

The scan performs `ListObjectsV2` and database upserts only. It does not change bucket objects. The dashboard total should be compared with the Contabo control-panel usage after the run completes.

Run Portal identity reconciliation separately:

```bash
php artisan media:sources-audit --dry-run --limit=5000
php artisan media:sources-audit --repair --limit=5000
php artisan nbx:sync-video-sources --limit=5000
```

The repair derives stable object keys from both `/nbx/...` and Contabo account-prefixed `/{account}:nbx/...` URLs, then registers direct references with NBX without re-uploading the movie.

## Recover a failed large source

For a source whose FFmpeg output is already valid but final storage failed, choose **Retry storage** or send `retry_storage`. This reuses the verified Fast Start artifact. Use an ordinary retry only when source download/probing/encoding failed.

The source stages should progress through:

1. `fetching`
2. `probing`
3. `compressing` or `faststarting`
4. `validating`
5. `final_storage_upload`
6. `remote_verification`
7. `ready`

`ready` and 100% are not written until required remote artifacts pass verification.

## Interpreting storage

- HLS playlists and segments are separate billable objects, but the dashboard groups them into one logical package.
- Equal file sizes do not prove that an original and Fast Start MP4 are duplicates.
- ETag plus size is displayed only as duplicate-signature evidence. Multipart ETags are explicitly marked and are never treated as a content checksum. Use **Verify SHA-256** in a cleanup review to stream every candidate before treating it as byte-identical.
- `portal_candidate`, `nbx_unresolved`, and `unresolved` do not mean orphaned.
- Missing objects are retained in inventory history with `missing_since`; the scanner does not delete their rows or bucket keys.

Review object details on the media asset or in **Storage → Cleanup reviews**. Keep or mark uncertain items as needing evidence. Only unreferenced `unresolved` objects with a verified SHA-256-identical retained copy can be approved after the seven-day grace period. Execution runs on the maintenance queue, rechecks every object, records the existing deletion audit, verifies remote removal, and queues a package inventory refresh.

# NBX media pipeline deployment

Deploy in this order so Portal never emits operations that an older NBX release does not understand.

## 1. NBX

1. Back up the database.
2. Deploy the NBX code and run:

   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   php artisan optimize
   ```

3. Configure the active public URL as `https://nbx.naraboxtv.com`.
4. Configure the signed Portal storage callback:

   ```env
   NBX_PORTAL_STORAGE_CALLBACK_URL=https://portal.naraboxtv.com/api/v1/nbx/storage-events
   NBX_ENGINE_WEBHOOK_SECRET=<rotate-and-set-the-shared-webhook-secret>
   ```

5. Keep `NBX_DEFAULT_HLS_480=false`; HLS is generated only when a source requests it.
6. For Contabo uploads, start with:

   ```env
   NBX_MULTIPART_THRESHOLD_MB=64
   NBX_MULTIPART_PART_SIZE_MB=32
   NBX_MULTIPART_CONCURRENCY=2
   NBX_KEEP_LOCAL_WORK_FILES=false
   ```

7. Restart the `default`, `optimization`, and `nbx-webhook` queue workers, and ensure Laravel's scheduler is running. The scheduler retries Telegram jobs that were waiting for Teletyde capacity.
8. Verify routes and runtime dependencies:

   ```bash
   php artisan route:list --path=api/v1/nbx
   php artisan nbx:health
   php artisan nbx:reconcile-artifacts --limit=200
   ```

The reconciliation command is a dry run unless `--apply` is supplied.

## 2. Teletyde

Configure Teletyde to send a small signed-URL handoff to NBX:

```env
CDN_UPLOAD_URL=https://nbx.naraboxtv.com/api/v1/media/telegram-handoff
CDN_HANDOFF_MODE=source_url
TEMP_PUBLIC_URL=https://teletyde.nara24fm.com
TEMP_URL_SECRET=<rotate-and-set-a-long-random-secret>
CDN_API_TOKEN=<rotate-and-set-the-nbx-api-token>
```

Restart the Teletyde service. Do not use `telegram-stream-intake` as the active handoff for large files; that mode sends the media body through the reverse proxy and can be rejected with HTTP 413.

## 3. Portal

Configure Portal to use NBX:

```env
NBX_ENGINE_ENABLED=true
NBX_ENGINE_BASE_URL=https://nbx.naraboxtv.com
NBX_ENGINE_API_KEY=<rotate-and-set-the-nbx-api-token>
NBX_ENGINE_CALLBACK_URL=https://portal.naraboxtv.com/api/v1/nbx/webhook
NBX_ENGINE_WEBHOOK_SECRET=<rotate-and-set-the-shared-webhook-secret>
```

Then clear cached configuration and restart Portal workers.

Run the Portal database migration before accepting traffic:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

## 4. Web frontend

Deploy a fresh production build of `narabox-next`. `NEXT_PUBLIC_*` values are compiled into the client bundle, so reusing an older build will omit the new bounded source failover behavior.

## 5. Smoke test

1. Create an NBX remote source with HLS off and retention set to optimized-only.
2. Confirm the result is an H.264/AAC `.mp4`, its `moov` atom precedes `mdat`, and playback/download both use the verified fast-start URL.
3. Edit only `Primary Source` or `Active`, save, and confirm no new NBX asset, source, or queue job is created.
4. Run an explicit HLS action and confirm it uses the verified fast-start MP4 as input.
5. Run a Telegram import larger than the proxy body limit. Confirm Teletyde sends JSON to `telegram-handoff`, NBX pulls the signed URL, and no 413 occurs.
6. When retaining an original, use the explicit delete action and confirm NBX refuses deletion if the replacement object's byte size cannot be verified.

Rotate every credential that has previously appeared in logs, screenshots, local notes, or chat before production deployment.

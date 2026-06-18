# Investigating telegram-intake upload failures (httpx.ReadError)

When the telebot shows **httpx.ReadError** during “Upload to CDN”, the TCP connection is being closed before the upload completes. The CDN app code may be fine; the break is often **before** the request reaches Laravel or **while** the proxy/PHP is handling the body.

## 1. Check if any telegram intake reached the CDN (database)

On the **CDN server** (where `cdn.naraboxtv.com` and the DB run), use the database name from your `.env` (`DB_DATABASE` – e.g. `naraboxt_cdn` in production, or `naraboxtv-cdn` locally):

```bash
# List tables (use your actual DB name)
mysql -u root -e "SHOW TABLES FROM \`naraboxtv-cdn\`"
# or: SHOW TABLES FROM naraboxt_cdn

# Recent media assets (telegram intake often has title like "EP011 HERO IS BACK...")
mysql -u naraboxt_cdnuser -p naraboxt_cdn -e "
  SELECT a.id AS asset_id, a.title, a.status AS asset_status, a.created_at,
         s.id AS source_id, s.source_type, s.status AS source_status, s.file_size_bytes, s.failure_reason
  FROM media_assets a
  LEFT JOIN media_sources s ON s.media_asset_id = a.id
  ORDER BY a.created_at DESC
  LIMIT 20;
"

# If source_metadata exists, recent telegram-related rows
mysql -u naraboxt_cdnuser -p naraboxt_cdn -e "
  SELECT id, media_asset_id, source_type, status, file_size_bytes, failure_reason,
         JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.telegram_channel')) AS telegram_channel,
         created_at
  FROM media_sources
  WHERE source_type = 'upload'
  ORDER BY created_at DESC
  LIMIT 15;
"
```

- **No new rows** when you try to ingest → the request likely never reaches Laravel (proxy/nginx/PHP body limit closing the connection).
- **Rows with `status = 'processing'`** and no `file_size_bytes` → request reached Laravel but failed during `putFileAs` (e.g. disk full, or connection closed while PHP was reading the body).
- **Rows with `status = 'ready'`** → upload succeeded; then the issue is only on the telebot side (e.g. reading response).

## 2. Check CDN Laravel logs

On the CDN server:

```bash
tail -f storage/logs/laravel.log
```

Then trigger an upload from the telebot. Look for:

- **"CDN telegram intake request started"** with `content_length` → request reached the controller; if you never see **"CDN telegram intake complete"**, the failure is during validation, `storeUploadedSource`, or the response.
- No **"CDN telegram intake request started"** at all → the request is being dropped before Laravel (see §3).

## 3. Where the connection is usually dropped

| Layer | What to check (on CDN host or proxy in front of it) |
|-------|------------------------------------------------------|
| **Nginx / reverse proxy** | `client_max_body_size` (e.g. 2048m for 2GB); `proxy_read_timeout`, `proxy_send_timeout`, `send_timeout` (e.g. 3600s for long uploads). |
| **PHP** | `upload_max_filesize`, `post_max_size` (e.g. 2G). `max_execution_time` (e.g. 3600) so the request isn’t killed while writing the file. |
| **Network / Coolify** | If the telebot runs on Coolify and the CDN is elsewhere, any proxy in between (e.g. Traefik) may have body size or time limits. |

Your `.env` has `MAX_UPLOAD_MB=2048` and `ALLOWED_VIDEO_EXTENSIONS=mp4,mkv,webm,avi,mov,m4v` – the Laravel app allows 2GB and .mkv. So the next place to check is **nginx** (or Apache) and **PHP** on the CDN server.

## 4. Quick PHP check on CDN server

```bash
php -i | grep -E 'upload_max_filesize|post_max_size|max_execution_time'
```

Ensure they are at least 2G / 2G / 3600 (or higher) for the PHP that serves the web app (e.g. php-fpm).

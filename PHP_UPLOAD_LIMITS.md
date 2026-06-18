# PHP upload limits (required for Telegram / large files)

The CDN accepts large video uploads (e.g. from the telebot). **PHP** rejects the request **before** Laravel sees it if the body exceeds `post_max_size` or the file exceeds `upload_max_filesize`.

## Symptom

- Telebot uploads a ~700 MB (or 2 GB) file to `POST /api/v1/media/telegram-intake`.
- CDN logs: `PHP Warning: POST Content-Length of XXXXX bytes exceeds the limit of 104857600 bytes`.
- Request fails in 1–3 seconds; file is never stored.

So you must raise PHP’s limits on the **CDN server** (local and production).

## Required values

Set at least:

- `upload_max_filesize = 2048M` (or `2G`)
- `post_max_size = 2048M` (must be ≥ upload_max_filesize)

This matches the app’s `MAX_UPLOAD_MB=2048` and allows 2 GB uploads.

## Local (XAMPP)

1. Find the `php.ini` used by the web server:
   - XAMPP: e.g. `/Applications/XAMPP/xamppfiles/etc/php.ini` (or run `php --ini` and check “Loaded Configuration File” for `php artisan serve` it may be a different one).
2. Edit that file:
   ```ini
   upload_max_filesize = 2048M
   post_max_size = 2048M
   ```
3. Restart the web server (or `php artisan serve`).

## Production (Apache / Nginx + PHP-FPM)

- **php.ini** (or pool config): set `upload_max_filesize` and `post_max_size` as above, then reload PHP-FPM.
- If you use **.user.ini** in the project root (PHP-FPM with `php_admin_value` or per-dir config), add:
  ```ini
  upload_max_filesize = 2048M
  post_max_size = 2048M
  ```

## Verify

After changing, run:

```bash
php -r "echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . PHP_EOL; echo 'post_max_size: ' . ini_get('post_max_size') . PHP_EOL;"
```

You should see `2048M` (or your chosen value). For `php artisan serve`, the same PHP process uses these limits.

## Summary

| Issue | Cause | Fix |
|-------|--------|-----|
| Upload rejected, “exceeds limit of 104857600 bytes” | PHP `post_max_size` / `upload_max_filesize` = 100 MB | Set both to 2048M (or 2G) in PHP config on the CDN server |

The 25% “stall” in the telebot terminal was the **download from Telegram** to the telebot host (network). The **CDN** problem is purely PHP limits; once raised, the CDN can receive 2 GB files from the telebot on production as well.

# Local PHP upload limits (413 Content Too Large)

When running the CDN locally with `php artisan serve`, large Telegram uploads (e.g. 700 MB+) can fail with **413 Content Too Large** or:

```text
POST Content-Length of ... bytes exceeds the limit of 104857600 bytes
```

That happens because PHP’s default `post_max_size` and `upload_max_filesize` are often 100 MB. **Production** usually has these set much higher (e.g. 2G) and can receive multi‑GB uploads; the problem is **local only**.

## Fix for local / XAMPP

1. Find the `php.ini` used by your CLI PHP (the one that runs `php artisan serve`):
   ```bash
   php --ini
   ```
2. Edit that file and set (or add) at least:
   ```ini
   upload_max_filesize = 2G
   post_max_size = 2G
   ```
3. Restart `php artisan serve` (and any other PHP process).

### XAMPP on macOS

- CLI often uses: `/Applications/XAMPP/xamppfiles/etc/php.ini`
- After editing, no need to restart Apache if you only use `php artisan serve`; just stop and start the artisan server.

Once these are raised, local telegram-intake uploads of large files will succeed. Production is unaffected if it already has higher limits.

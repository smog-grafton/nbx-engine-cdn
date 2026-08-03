# Resumable remote fetch coordinator

Remote imports no longer stream a multi-gigabyte response through Laravel or a portal proxy. The CDN probes the source with a tiny ranged `GET`, then downloads sequential, verified ranges into a `.part` checkpoint beside the final media file.

## Failure handling

- Every response must be `206 Partial Content` and its `Content-Range` must exactly match the requested offset, end, and total size.
- A `200 OK` response to a range request is rejected and is never appended.
- If cURL error 18, a timeout, or an unexpected EOF occurs after validated headers, the bytes already received for that range remain on disk. The next request begins at the first missing byte.
- HTTP/1.1, `Accept-Encoding: identity`, an origin `Referer`, cookies, and a browser-compatible user agent are used for legacy download scripts and Cloudflare origins.
- One source host receives one sequential connection per job. Separate queue workers can still fetch separate movies.
- The completed `.part` is renamed to the final media path only after its exact expected size is present.
- A failed proxy/worker handoff is reclaimed by `cdn:reconcile`; it preserves the existing job id and resumes the local checkpoint.

## Durable state

`remote_fetch_sessions` stores the source identity, expected size, validators, strategy, total progress, errors, and heartbeat. `remote_fetch_parts` stores each byte range, retained bytes, attempts, checksum, and completion state.

Useful queries:

```sql
SELECT media_source_id, status, strategy, bytes_downloaded, expected_size,
       attempts, consecutive_failures, last_error, last_heartbeat_at
FROM remote_fetch_sessions
ORDER BY id DESC;

SELECT start_byte, end_byte, downloaded_bytes, attempts, status, last_error
FROM remote_fetch_parts
WHERE remote_fetch_session_id = ?
ORDER BY start_byte;
```

## Configuration

The default one MiB range is conservative because some PHP download endpoints close larger ranges near the one MiB mark.

```dotenv
CDN_REMOTE_FETCH_CHUNK_BYTES=1048576
CDN_REMOTE_FETCH_PART_ATTEMPTS=20
CDN_REMOTE_FETCH_RETRY_WAIT_MS=1000
CDN_REMOTE_FETCH_PROBE_TIMEOUT=45
CDN_REMOTE_FETCH_CHUNK_TIMEOUT=90
```

Run the normal `default` queue worker and Laravel scheduler. Deployments must run the migration before restarting workers:

```bash
php artisan migrate --force
php artisan queue:restart
```

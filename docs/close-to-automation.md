Yes — your VPS and a small Python worker can help a lot here.

I can’t inspect your Mac folders or local MySQL from here, so I’m basing this on the architecture and failure pattern you described. The strongest reading is that your **fetch succeeds**, but your **optimize / HLS stage is fragile under back-to-back ingests**, which sounds like a **queue/concurrency/orchestration problem**, not a simple URL problem. Laravel is built to handle exactly this kind of work with queued jobs, failed-job retries, and scheduled commands; FFmpeg’s HLS output also naturally centers on a `master.m3u8` plus segment folders, so your portal can predict and track that URL before it is ready. ([Laravel][1])

Here’s what I would do.

## 1. Stop treating optimization as “fire and hope”

Make the CDN pipeline explicitly stateful.

For each fetched file, store statuses like:

* fetched
* original_ready
* play_mp4_pending
* play_mp4_ready
* hls_pending
* hls_ready
* failed
* retrying

That way, the portal does not wait for the CDN to “return everything immediately.” It can save the original MP4 first, then track the rest asynchronously. Laravel queues support this model well, and failed jobs can be retried rather than silently dying. ([Laravel][1])

## 2. Serialize the heavy FFmpeg work

From your description, optimization often fails when you fetch another movie too soon. That suggests the CDN is probably letting multiple expensive jobs overlap in a way your server or code path does not tolerate.

So the first real fix is:

* allow many fetch jobs if you want
* but run **HLS/optimization jobs one at a time**, or with a very low concurrency limit

In practice:

* queue original fetch separately
* queue optimization separately
* dedicate one worker queue just for FFmpeg
* process that queue with concurrency = 1

That is the safest way to stop one movie from stepping on another.

## 3. Let the portal generate the related URLs immediately

This part is very doable.

You already noticed the pattern:

* original: `/media/{uuid}/{id}/name.mp4`
* play: `/media/{uuid}/{id}/name_play.mp4`
* HLS: `/media-hls/{uuid}/{id}/master.m3u8`

So once the portal receives the original URL and parses out:

* UUID
* numeric media/version id
* base filename

it can immediately create the expected:

* playback MP4 URL
* master.m3u8 URL

That does **not** mean the files exist yet. It just means the portal can create **predicted sources** with statuses like:

* expected
* probing
* ready
* failed

That will remove a lot of your manual source creation.

## 4. Add a Python or Laravel “source completion worker”

This is where the VPS becomes useful.

Run a small worker that does this:

1. detect a new original MP4 source in portal or CDN
2. derive the sibling URLs automatically
3. probe them on a schedule
4. once they exist, mark them ready
5. parse the HLS playlist and create individual variant sources

This worker can be Python or Laravel. Python is nice for probing and playlist parsing; Laravel is nice if you want it closer to your admin system.

The worker should:

* try `master.m3u8`
* if 200 OK, download it
* parse variant playlists
* extract available renditions like 480p, 720p, etc.
* create child video sources in the portal DB

FFmpeg’s HLS muxer documentation confirms the master playlist naming and structure pattern around `master.m3u8`, which fits your approach of deriving the URL first and validating it later. ([FFmpeg][2])

## 5. Build automatic HLS variant discovery

You said only a `480p` folder is being created sometimes. That means one of two things is likely happening:

* your FFmpeg command is only actually generating one rendition
* or the multi-rendition job is failing partway through and leaving only the first output

Either way, your portal should not rely on assumptions. It should inspect the real HLS output.

So add a parser that reads `master.m3u8` and registers the actual variants found. That way:

* if only 480p exists, portal shows only 480p
* if later 720p appears after retry, portal adds it automatically

## 6. Add mandatory retry / resume for failed optimization

You explicitly said this is mandatory, and I agree.

On the CDN side, every failed optimize/HLS job should:

* record the error
* increment attempts
* retry after delay
* stop only after max attempts
* remain visible in admin for manual re-run

Laravel supports failed-job tracking and retry commands, and the scheduler is meant for recurring maintenance/recovery work. ([Laravel][1])

So implement two layers:

**Automatic**

* scheduled command every few minutes
* find media where:

  * original exists
  * hls failed or pending too long
  * play mp4 failed or pending too long
* dispatch re-optimize job again

**Manual**

* Filament action button: **Run Re-optimise**
* maybe bulk action too

## 7. Separate “fetch complete” from “processing complete”

Right now it sounds like the UX mixes them together.

Better flow:

### Stage A: fetch/import

* URL pasted
* CDN fetches original file
* portal saves original source immediately

### Stage B: async enrich/process

* playback MP4 expected URL created
* HLS expected URL created
* worker probes
* source statuses update as files appear

This will make the system feel faster and more stable, because users see progress instead of “it failed” when the original actually succeeded.

## 8. Use the VPS as the orchestration brain

Your VPS does not need to run FFmpeg if the CDN already does that well. It can instead run helpers:

* retry orchestrator
* HLS probe worker
* source completion worker
* alerting bot
* dead-job detector
* dashboard for stuck ingests

That keeps heavy media processing on the CDN and uses the VPS for coordination.

## 9. Add a reconciliation worker

This is a very high-value fix.

Every 5 or 10 minutes, a worker should compare:

* portal sources
* CDN media records
* actual reachable URLs

Then repair missing relationships automatically.

Example:
if original exists in portal, but play/HLS sources do not, derive and insert them.
if HLS URL exists and returns 200, parse and register variants.
if optimize failed but original exists, queue re-optimise.

That will reduce a lot of manual cleanup.

## 10. Keep one cron, but make it smarter

Your cron for `php artisan schedule:run` is the right pattern; Laravel’s scheduler is designed around a single cron entry. ([Laravel][3])

What matters is what you schedule inside Laravel.

I would schedule:

* `media:probe-pending-sources`
* `media:retry-failed-optimizations`
* `media:sync-derived-sources`
* `media:mark-stale-processing`

## 11. What the portal should do automatically

You asked very specifically for this, and yes, the portal should do it.

When the first source is created manually or from CDN:

* create the sibling `_play.mp4` URL
* create the `master.m3u8` URL
* save both as derived sources with pending status
* start a background probe
* once `master.m3u8` is real, parse it and create individual quality sources

So the editor creates one source, and the system fans out the rest.

## 12. Most likely root cause

Based on your description, my best inference is:

* fetching is fine
* original storage is fine
* optimization fails when jobs overlap
* HLS generation is not isolated enough
* portal currently depends too much on immediate CDN response rather than async lifecycle

So the fix is mostly **workflow design**, not just “more FFmpeg.”

## Best practical plan

If I were prioritizing this for NaraboxTV, I’d do it in this order:

### First

In `naraboxt-lara`:

* auto-derive `_play.mp4` and `master.m3u8`
* create them as pending sources
* add source status fields

### Second

In `naraboxtv-cdn`:

* make optimization queue single-file / serialized
* add retryable optimize jobs
* add “Run Re-optimise” Filament action

### Third

On the VPS:

* run a probe/reconciliation worker
* parse HLS playlists
* auto-create variant sources
* notify portal when formats appear

That combination would remove most of your manual work.

If you want, I can turn this into a **single professional Cursor AI prompt** focused only on fixing the CDN optimization/retry/HLS-source automation flow.

[1]: https://laravel.com/docs/12.x/queues?utm_source=chatgpt.com "Queues | Laravel 12.x - The clean stack for Artisans and ..."
[2]: https://ffmpeg.org/ffmpeg-formats.html?utm_source=chatgpt.com "FFmpeg Formats Documentation"
[3]: https://laravel.com/docs/12.x/scheduling?utm_source=chatgpt.com "Task Scheduling | Laravel 12.x - The clean stack for ..."

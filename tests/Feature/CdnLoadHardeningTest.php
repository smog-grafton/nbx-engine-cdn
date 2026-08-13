<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateMediaApiToken;
use App\Jobs\OptimizeMp4FaststartJob;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
use App\Services\ProcessingLiveness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CdnLoadHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        Storage::fake('public');
    }

    public function test_queue_playback_processing_skips_duplicate_pending_dispatches(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.enable_hls', true);
        config()->set('queue.default', 'database');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Load Test Movie',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        Storage::disk('public')->put('media/'.$asset->id.'/1/movie.mkv', 'video-bytes');

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/1/movie.mkv',
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => true,
        ]);

        $service = app(MediaSourceService::class);

        $this->assertTrue($service->queuePlaybackProcessing($source));
        $this->assertFalse($service->queuePlaybackProcessing($source->fresh()));
        $this->assertSame('pending', $source->fresh()->optimize_status);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_stale_recovery_does_not_requeue_queued_source_with_matching_ready_job(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.enable_hls', false);
        config()->set('queue.default', 'database');
        config()->set('cdn.queued_optimization_missing_job_grace_seconds', 30);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Queued With Job',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $path = 'media/'.$asset->id.'/queued-with-job.mkv';
        Storage::disk('public')->put($path, 'video-bytes');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => true,
        ]);

        $this->assertTrue(app(MediaSourceService::class)->queuePlaybackProcessing($source));
        $source->refresh();
        MediaSource::withoutTimestamps(fn () => $source->forceFill([
            'processing_heartbeat_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->saveQuietly());

        Artisan::call('media:recover-stale-optimizations', ['--stale-minutes' => 5, '--limit' => 10]);

        $source->refresh();
        $this->assertSame('pending', $source->optimize_status);
        $this->assertSame('queued', $source->processing_stage);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_stale_recovery_requeues_abandoned_queued_source_when_queue_row_is_missing(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.enable_hls', false);
        config()->set('queue.default', 'database');
        config()->set('cdn.queued_optimization_missing_job_grace_seconds', 30);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Queued Missing Job',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $path = 'media/'.$asset->id.'/queued-missing-job.mkv';
        Storage::disk('public')->put($path, 'video-bytes');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'status' => 'ready',
            'optimize_status' => 'pending',
            'processing_stage' => 'queued',
            'processing_attempt_id' => (string) \Illuminate\Support\Str::uuid(),
            'processing_attempt_started_at' => now()->subMinutes(10),
            'processing_heartbeat_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinutes(10),
            'is_active' => true,
            'compress_enabled' => true,
        ]);

        Artisan::call('media:recover-stale-optimizations', ['--stale-minutes' => 5, '--limit' => 10]);

        $source->refresh();
        $this->assertSame('pending', $source->optimize_status);
        $this->assertSame('queued', $source->processing_stage);
        $this->assertNotNull($source->processing_attempt_id);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_stale_recovery_requeues_reserved_queued_job_with_no_worker_heartbeat(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.enable_hls', false);
        config()->set('queue.default', 'database');
        config()->set('cdn.queued_optimization_missing_job_grace_seconds', 30);
        config()->set('cdn.queued_optimization_reserved_rescue_seconds', 30);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Reserved Dead Job',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $path = 'media/'.$asset->id.'/reserved-dead-job.mkv';
        Storage::disk('public')->put($path, 'video-bytes');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => true,
        ]);

        $this->assertTrue(app(MediaSourceService::class)->queuePlaybackProcessing($source));
        $source->refresh();
        \Illuminate\Support\Facades\DB::table('jobs')->update([
            'reserved_at' => now()->subMinutes(10)->timestamp,
        ]);
        MediaSource::withoutTimestamps(fn () => $source->forceFill([
            'processing_heartbeat_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->saveQuietly());

        Artisan::call('media:recover-stale-optimizations', ['--stale-minutes' => 5, '--limit' => 10]);

        $source->refresh();
        $this->assertSame('pending', $source->optimize_status);
        $this->assertSame('queued', $source->processing_stage);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['reserved_at' => null]);
    }

    public function test_telegram_handoff_declared_size_survives_queue_dispatch_for_later_integrity_comparison(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cdn.import_queue', 'default');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Telegram integrity fixture',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => 'https://example.com/signed/movie.mkv',
            'status' => 'pending',
            'bytes_total' => 123456,
            'source_metadata' => [
                'handoff_integrity' => ['telebot_declared_bytes' => 123456],
            ],
        ]);

        app(MediaSourceService::class)->queueRemoteImport($source);
        $source->refresh();

        $this->assertSame(123456, $source->bytes_total);
        $this->assertSame('queued', $source->processing_stage);
        $this->assertSame('default', $source->source_metadata['queue']['import']['name'] ?? null);
        $this->assertNotEmpty($source->source_metadata['queue']['import']['dispatched_at'] ?? null);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_playback_manifest_prefers_direct_storage_urls_when_enabled(): void
    {
        config()->set('app.url', 'https://cdn.example.com');
        config()->set('filesystems.disks.public.url', 'https://cdn.example.com/storage');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.use_direct_storage_urls', true);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Direct URL Movie',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/10/original.mp4',
            'optimized_path' => 'media/'.$asset->id.'/10/original_play.mp4',
            'hls_master_path' => 'media/'.$asset->id.'/10/hls/master.m3u8',
            'status' => 'ready',
            'is_active' => true,
            'playback_type' => 'hls',
            'qualities_json' => [
                [
                    'id' => '720p',
                    'label' => '720P',
                    'bandwidth' => 1800000,
                    'width' => 1280,
                    'height' => 720,
                    'path' => 'media/'.$asset->id.'/10/hls/720p/index.m3u8',
                ],
            ],
        ]);

        Storage::disk('public')->put((string) $source->storage_path, 'original');
        Storage::disk('public')->put((string) $source->optimized_path, 'optimized');
        Storage::disk('public')->put((string) $source->hls_master_path, "#EXTM3U\n");
        Storage::disk('public')->put('media/'.$asset->id.'/10/hls/720p/index.m3u8', "#EXTM3U\n");

        $manifest = app(MediaSourceService::class)->buildPlaybackManifest($source);

        $this->assertSame('https://cdn.example.com/storage/media/'.$asset->id.'/10/hls/master.m3u8', $manifest['hls_master_url']);
        $this->assertSame('https://cdn.example.com/storage/media/'.$asset->id.'/10/original_play.mp4', $manifest['mp4_play_url']);
        $this->assertSame('https://cdn.example.com/storage/media/'.$asset->id.'/10/original.mp4', $manifest['download_url']);
        $this->assertSame('https://cdn.example.com/storage/media/'.$asset->id.'/10/hls/720p/index.m3u8', $manifest['qualities'][1]['url']);
    }

    public function test_playback_manifest_builds_hls_route_urls_when_direct_storage_urls_are_disabled(): void
    {
        config()->set('app.url', 'https://cdn.example.com');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.use_direct_storage_urls', false);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Routed HLS Movie',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/11/original.mp4',
            'optimized_path' => 'media/'.$asset->id.'/11/original_play.mp4',
            'hls_master_path' => 'media/'.$asset->id.'/11/hls/master.m3u8',
            'status' => 'ready',
            'is_active' => true,
            'playback_type' => 'hls',
            'qualities_json' => [
                [
                    'id' => '720p',
                    'label' => '720P',
                    'bandwidth' => 1800000,
                    'width' => 1280,
                    'height' => 720,
                    'path' => 'media/'.$asset->id.'/11/hls/720p/index.m3u8',
                ],
            ],
        ]);

        Storage::disk('public')->put((string) $source->storage_path, 'original');
        Storage::disk('public')->put((string) $source->optimized_path, 'optimized');
        Storage::disk('public')->put((string) $source->hls_master_path, "#EXTM3U\n");
        Storage::disk('public')->put('media/'.$asset->id.'/11/hls/720p/index.m3u8', "#EXTM3U\n");

        $manifest = app(MediaSourceService::class)->buildPlaybackManifest($source);

        $this->assertSame('https://cdn.example.com/media-hls/'.$asset->id.'/'.$source->id.'/master.m3u8', $manifest['hls_master_url']);
        $this->assertSame('https://cdn.example.com/media-hls/'.$asset->id.'/'.$source->id.'/720p/index.m3u8', $manifest['qualities'][1]['url']);
    }

    public function test_reconciler_finalizes_a_complete_stored_import_and_clears_stale_errors(): void
    {
        config()->set('cdn.disk', 'public');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Completed import with stale state',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $path = 'media/'.$asset->id.'/15/movie.mp4';
        Storage::disk('public')->put($path, str_repeat('x', 2048));

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => 'https://example.com/movie.mp4',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'status' => 'processing',
            'failure_reason' => 'Old failure message.',
            'last_error' => 'Old cURL error.',
            'progress_percent' => 100,
            'bytes_downloaded' => 2048,
            'bytes_total' => 2048,
            'last_progress_at' => now()->subHour(),
            'is_active' => true,
        ]);
        MediaSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->saveQuietly());

        Artisan::call('cdn:reconcile', ['--minutes' => 30]);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(100, $source->progress_percent);
        $this->assertSame(2048, $source->file_size_bytes);
        $this->assertNull($source->failure_reason);
        $this->assertNull($source->last_error);
        $this->assertNotNull($source->completed_at);
        $this->assertSame('ready', $asset->fresh()->status);
    }

    public function test_reconciler_does_not_finalize_a_truncated_stored_import(): void
    {
        config()->set('cdn.disk', 'public');
        config()->set('queue.default', 'database');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Truncated stored import',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $path = 'media/'.$asset->id.'/16/movie.mp4';
        Storage::disk('public')->put($path, str_repeat('x', 1024));

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => 'https://example.com/movie.mp4',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'status' => 'processing',
            'progress_percent' => 100,
            'bytes_downloaded' => 1024,
            'bytes_total' => 2048,
            'last_progress_at' => now()->subHour(),
            'is_active' => true,
        ]);
        MediaSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->saveQuietly());

        Artisan::call('cdn:reconcile', ['--minutes' => 30]);

        $source->refresh();
        $this->assertSame('pending', $source->status);
        $this->assertNull($source->completed_at);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_reconciler_does_not_requeue_or_fail_a_stale_row_while_the_worker_heartbeat_is_alive(): void
    {
        config()->set('cdn.disk', 'public');
        config()->set('queue.default', 'database');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Still-running import',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => 'https://example.com/still-running.mkv',
            'storage_disk' => 'public',
            'status' => 'processing',
            'processing_stage' => 'compressing',
            'is_active' => true,
        ]);
        MediaSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->saveQuietly());
        ProcessingLiveness::touch($source->id);

        Artisan::call('cdn:reconcile', ['--minutes' => 30]);

        $source->refresh();
        $this->assertSame('processing', $source->status);
        $this->assertSame('compressing', $source->processing_stage);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_targeted_optimization_retry_reuses_the_original_without_queueing_a_remote_import(): void
    {
        config()->set('cdn.disk', 'public');
        config()->set('nbx.work_storage', 'public');
        config()->set('queue.default', 'database');
        config()->set('cdn.enable_hls', false);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Retry original fixture',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $path = 'media/'.$asset->id.'/retry-original.mkv';
        Storage::disk('public')->put($path, 'original-movie-bytes');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => 'https://example.com/telegram-handoff',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_storage_path' => $path,
            'status' => 'failed',
            'optimize_status' => 'failed',
            'optimize_error' => 'Old validation failure.',
            'failure_reason' => 'Old validation failure.',
            'is_active' => true,
        ]);

        Artisan::call('media:retry-optimization', ['source' => $source->id]);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame('pending', $source->optimize_status);
        $this->assertSame('reuse_original', $source->source_metadata['retry']['optimization']['mode'] ?? null);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_import_endpoint_normalizes_bracketed_m4v_source_urls(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cdn.default_import_mode', 'queue');

        $this->withoutMiddleware(AuthenticateMediaApiToken::class);

        $rawUrl = 'https://media.vjluga.com/videos/1757246771368-Blood%20Done%20Sign%20My%20Name%20Mark[s2m%20Ent]-1-1.m4v';
        $normalizedUrl = 'https://media.vjluga.com/videos/1757246771368-Blood%20Done%20Sign%20My%20Name%20Mark%5Bs2m%20Ent%5D-1-1.m4v';

        $this->postJson('/api/v1/media/import', [
            'source_url' => $rawUrl,
            'title' => 'Bracketed M4V Import',
        ])->assertStatus(202);

        $source = MediaSource::query()->latest('id')->first();

        $this->assertNotNull($source);
        $this->assertSame($normalizedUrl, $source->source_url);
    }

    public function test_faststart_treats_m4v_as_mp4_family_input(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('cdn.compress_before_playback', false);

        $fakeFfmpeg = storage_path('framework/testing/fake-ffmpeg.sh');
        @mkdir(dirname($fakeFfmpeg), 0755, true);
        file_put_contents($fakeFfmpeg, <<<'SH'
#!/bin/sh
if [ "$1" = "-version" ]; then
  echo "ffmpeg test double"
  exit 0
fi
input=""
output=""
prev=""
for arg in "$@"; do
  if [ "$prev" = "-i" ]; then
    input="$arg"
  fi
  prev="$arg"
  output="$arg"
done
cp "$input" "$output"
SH
        );
        @chmod($fakeFfmpeg, 0755);
        config()->set('cdn.ffmpeg_binary', $fakeFfmpeg);
        $fakeFfprobe = storage_path('framework/testing/fake-ffprobe.sh');
        file_put_contents($fakeFfprobe, <<<'SH'
#!/bin/sh
if [ "$1" = "-version" ]; then
  echo "ffprobe test double"
  exit 0
fi
cat <<'JSON'
{"streams":[{"codec_type":"video","codec_name":"h264","pix_fmt":"yuv420p","width":1280,"height":720},{"codec_type":"audio","codec_name":"aac","channels":2}],"format":{"format_name":"mov,mp4,m4a,3gp,3g2,mj2","duration":"60.0","size":"16"}}
JSON
SH
        );
        @chmod($fakeFfprobe, 0755);
        config()->set('cdn.ffprobe_binary', $fakeFfprobe);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'M4V Faststart Movie',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/12/original.m4v',
            'mime_type' => 'video/x-m4v',
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => false,
        ]);

        Storage::disk('public')->put((string) $source->storage_path, 'xxxxmoovxxxxmdat');

        (new OptimizeMp4FaststartJob($source->id))->handle();
        $source->refresh();

        $this->assertSame('ready', $source->optimize_status, (string) $source->optimize_error);
        $this->assertSame('media/'.$asset->id.'/12/original_play.mp4', $source->optimized_path);
        $this->assertTrue((bool) $source->is_faststart);
        $this->assertTrue(Storage::disk('public')->exists((string) $source->optimized_path));
    }

    public function test_verified_output_survives_when_the_original_disappears_after_ffmpeg(): void
    {
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('nbx.work_storage', 'public');
        config()->set('cdn.enable_hls', false);
        config()->set('cdn.compress_before_playback', false);

        $fakeFfmpeg = storage_path('framework/testing/fake-ffmpeg-removes-input.sh');
        @mkdir(dirname($fakeFfmpeg), 0755, true);
        file_put_contents($fakeFfmpeg, <<<'SH'
#!/bin/sh
if [ "$1" = "-version" ]; then
  echo "ffmpeg test double"
  exit 0
fi
input=""
output=""
prev=""
for arg in "$@"; do
  if [ "$prev" = "-i" ]; then
    input="$arg"
  fi
  prev="$arg"
  output="$arg"
done
cp "$input" "$output"
rm -f "$input"
SH
        );
        @chmod($fakeFfmpeg, 0755);
        config()->set('cdn.ffmpeg_binary', $fakeFfmpeg);

        $fakeFfprobe = storage_path('framework/testing/fake-ffprobe-missing-input.sh');
        file_put_contents($fakeFfprobe, <<<'SH'
#!/bin/sh
if [ "$1" = "-version" ]; then
  echo "ffprobe test double"
  exit 0
fi
cat <<'JSON'
{"streams":[{"codec_type":"video","codec_name":"h264","pix_fmt":"yuv420p","width":1280,"height":720},{"codec_type":"audio","codec_name":"aac","channels":2}],"format":{"format_name":"mov,mp4,m4a,3gp,3g2,mj2","duration":"60.0","size":"16"}}
JSON
SH
        );
        @chmod($fakeFfprobe, 0755);
        config()->set('cdn.ffprobe_binary', $fakeFfprobe);

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Missing Original Recovery',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/124/input.mov',
            'mime_type' => 'video/quicktime',
            'file_size_bytes' => 16,
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => false,
        ]);
        Storage::disk('public')->put((string) $source->storage_path, 'xxxxmoovxxxxmdat');

        (new OptimizeMp4FaststartJob($source->id))->handle();
        $source->refresh();

        $this->assertSame('ready', $source->optimize_status, (string) $source->optimize_error);
        $this->assertTrue((bool) $source->is_faststart);
        $this->assertSame($source->optimized_path, $source->storage_path);
        Storage::disk('public')->assertExists((string) $source->optimized_path);
        $this->assertTrue((bool) data_get($source->source_metadata, 'processing_result.original_missing_after_processing'));
    }

    public function test_failed_legacy_job_recovers_an_orphaned_deterministic_faststart_output(): void
    {
        config()->set('cdn.disk', 'public');
        config()->set('nbx.work_storage', 'public');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Orphaned Faststart Recovery',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/124/weekend.mov',
            'mime_type' => 'video/quicktime',
            'status' => 'ready',
            'optimize_status' => 'failed',
            'optimize_error' => 'Optimization worker stopped: filesize(): stat failed',
            'is_active' => true,
        ]);
        $orphanedOutput = 'media/'.$asset->id.'/124/weekend_play.mp4';
        Storage::disk('public')->put($orphanedOutput, 'verified-output-bytes');

        $restored = app(MediaSourceService::class)->ensureLocalWorkFileForProcessing($source);

        $this->assertNotNull($restored);
        $this->assertStringContainsString('/restored/', (string) $restored->storage_path);
        Storage::disk('public')->assertExists((string) $restored->storage_path);
        $this->assertSame(
            'inferred_orphan_faststart',
            data_get($restored->source_metadata, 'nbx.restored_work_file.from'),
        );
    }

    public function test_nbx_contabo_manifest_does_not_check_missing_local_hls_path_or_return_local_urls(): void
    {
        config()->set('app.url', 'https://nbx.naraboxtv.com');
        config()->set('nbx.default_storage', 'contabo');
        config()->set('filesystems.disks.contabo.url', 'https://usc1.contabostorage.com/account:nbx');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'NBX Contabo Missing HLS',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'contabo',
            'storage_path' => 'media/'.$asset->id.'/5/original.mp4',
            'optimized_path' => 'media/'.$asset->id.'/5/original_play.mp4',
            'hls_master_path' => 'media/'.$asset->id.'/5/hls/master.m3u8',
            'status' => 'ready',
            'is_active' => true,
            'playback_type' => 'hls',
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'storage_target' => 'contabo',
                    'requested' => [
                        'hls' => ['480p' => true],
                    ],
                    'final_artifacts' => [
                        'original' => [
                            'disk' => 'contabo',
                            'key' => 'videos/nbx/job/original/original.mp4',
                            'url' => 'https://usc1.contabostorage.com/account:nbx/videos/nbx/job/original/original.mp4',
                            'verified' => true,
                        ],
                        'faststart' => [
                            'disk' => 'contabo',
                            'key' => 'videos/nbx/job/faststart/original_play.mp4',
                            'url' => 'https://usc1.contabostorage.com/account:nbx/videos/nbx/job/faststart/original_play.mp4',
                            'verified' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $manifest = app(MediaSourceService::class)->buildPlaybackManifest($source);

        $this->assertSame('mp4', $manifest['type']);
        $this->assertNull($manifest['hls_master_url']);
        $this->assertSame('https://usc1.contabostorage.com/account:nbx/videos/nbx/job/faststart/original_play.mp4', $manifest['mp4_play_url']);
        $this->assertSame('https://usc1.contabostorage.com/account:nbx/videos/nbx/job/faststart/original_play.mp4', $manifest['download_url']);
        $this->assertStringNotContainsString('nbx.naraboxtv.com/media/', (string) $manifest['mp4_play_url']);
    }
}

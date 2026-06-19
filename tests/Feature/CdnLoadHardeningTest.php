<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateMediaApiToken;
use App\Jobs\OptimizeMp4FaststartJob;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
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

        Storage::disk('public')->put((string) $source->storage_path, 'm4v-video-bytes');

        (new OptimizeMp4FaststartJob($source->id))->handle();
        $source->refresh();

        $this->assertSame('ready', $source->optimize_status);
        $this->assertSame('media/'.$asset->id.'/12/original_play.mp4', $source->optimized_path);
        $this->assertTrue((bool) $source->is_faststart);
        $this->assertTrue(Storage::disk('public')->exists((string) $source->optimized_path));
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
                        ],
                        'faststart' => [
                            'disk' => 'contabo',
                            'key' => 'videos/nbx/job/faststart/original_play.mp4',
                            'url' => 'https://usc1.contabostorage.com/account:nbx/videos/nbx/job/faststart/original_play.mp4',
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

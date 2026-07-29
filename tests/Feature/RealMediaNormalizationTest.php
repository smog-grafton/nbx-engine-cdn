<?php

namespace Tests\Feature;

use App\Jobs\GenerateHlsVariantsJob;
use App\Jobs\OptimizeMp4FaststartJob;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaBinaryDetector;
use App\Services\VideoProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RealMediaNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
        Storage::fake('public');
        config()->set('filesystems.default', 'public');
        config()->set('cdn.disk', 'public');
        config()->set('nbx.work_storage', 'public');
        config()->set('cdn.enable_hls', false);
        config()->set('cdn.ffmpeg_timeout_seconds', 120);
        config()->set('cdn.ffmpeg_heartbeat_seconds', 2);
        config()->set('cdn.compress_preset', 'ultrafast');
    }

    public function test_mov_mkv_mp4_and_telegram_handoff_inputs_become_verified_faststart_mp4(): void
    {
        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        $ffprobe = app(MediaBinaryDetector::class)->ffprobe();
        if (! $ffmpeg || ! $ffprobe) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for the real media normalization test.');
        }

        foreach ([
            ['extension' => 'mov', 'source' => 'upload'],
            ['extension' => 'mkv', 'source' => 'upload'],
            ['extension' => 'mp4', 'source' => 'upload'],
            ['extension' => 'mkv', 'source' => 'telegram'],
        ] as $case) {
            $asset = MediaAsset::query()->create([
                'type' => 'movie',
                'title' => strtoupper($case['source'].' '.$case['extension']).' fixture',
                'status' => 'ready',
                'visibility' => 'public',
            ]);
            $source = MediaSource::query()->create([
                'media_asset_id' => $asset->id,
                'source_type' => 'upload',
                'storage_disk' => 'public',
                'storage_path' => "media/{$asset->id}/input.{$case['extension']}",
                'mime_type' => $case['extension'] === 'mov' ? 'video/quicktime' : ($case['extension'] === 'mkv' ? 'video/x-matroska' : 'video/mp4'),
                'status' => 'ready',
                'is_active' => true,
                'compress_enabled' => true,
                'source_metadata' => [
                    'provider' => 'nbx_engine',
                    'source' => $case['source'],
                    'nbx' => [
                        'storage_target' => 'public',
                        'requested' => [
                            'compression' => true,
                            'faststart' => true,
                            'retention_policy' => 'optimized_only',
                            'allow_downloads' => true,
                            'allow_hls_streaming' => false,
                            'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                        ],
                    ],
                ],
            ]);

            Storage::disk('public')->makeDirectory("media/{$asset->id}");
            $input = Storage::disk('public')->path((string) $source->storage_path);
            (new Process([
                $ffmpeg,
                '-y',
                '-f', 'lavfi',
                '-i', 'color=c=blue:s=640x360:d=1',
                '-f', 'lavfi',
                '-i', 'sine=frequency=1000:duration=1',
                '-shortest',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                $input,
            ]))->setTimeout(60)->mustRun();

            (new OptimizeMp4FaststartJob($source->id))->handle();
            $source->refresh();
            $probe = app(VideoProbeService::class)->probe(Storage::disk('public')->path((string) $source->optimized_path));

            $this->assertSame('ready', $source->optimize_status, "{$case['source']} {$case['extension']}: {$source->optimize_error}");
            $this->assertSame('ready', $source->processing_stage);
            $this->assertStringEndsWith('.mp4', (string) $source->optimized_path);
            $this->assertSame('video/mp4', $source->mime_type);
            $this->assertSame('h264', $probe['video_codec'] ?? null);
            $this->assertSame('aac', $probe['audio_codec'] ?? null);
            $this->assertStringContainsString('mp4', (string) ($probe['container'] ?? ''));
            $this->assertSame(
                'remux_already_efficient',
                $source->source_metadata['nbx']['processing_result']['processing_mode'] ?? null,
            );
        }
    }

    public function test_verified_mp4_generates_a_real_hls_master_and_segments(): void
    {
        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg || ! app(MediaBinaryDetector::class)->ffprobe()) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for the real HLS test.');
        }

        config()->set('cdn.enable_hls', true);
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'HLS fixture',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $attemptId = '4ce9411d-529e-4e7b-89af-218de052f165';
        $path = "media/{$asset->id}/normalized.mp4";
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'optimized_path' => $path,
            'mime_type' => 'video/mp4',
            'status' => 'ready',
            'optimize_status' => 'ready',
            'is_faststart' => true,
            'is_active' => true,
            'processing_attempt_id' => $attemptId,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'storage_target' => 'public',
                    'requested' => [
                        'retention_policy' => 'optimized_only',
                        'allow_downloads' => true,
                        'allow_hls_streaming' => true,
                        'hls' => ['480p' => true, '720p' => false, '1080p' => false],
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->makeDirectory("media/{$asset->id}");
        (new Process([
            $ffmpeg,
            '-y',
            '-f', 'lavfi',
            '-i', 'color=c=green:s=640x480:d=2',
            '-f', 'lavfi',
            '-i', 'sine=frequency=750:duration=2',
            '-shortest',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-movflags', '+faststart',
            Storage::disk('public')->path($path),
        ]))->setTimeout(60)->mustRun();

        (new GenerateHlsVariantsJob($source->id, $attemptId))->handle();
        $source->refresh();

        $this->assertSame('ready', $source->optimize_status, (string) $source->optimize_error);
        $this->assertSame('hls', $source->playback_type);
        $this->assertSame('ready', $source->processing_stage);
        $this->assertNotEmpty($source->hls_master_path);
        Storage::disk('public')->assertExists((string) $source->hls_master_path);
        $this->assertNotEmpty(Storage::disk('public')->allFiles(dirname((string) $source->hls_master_path).'/480p'));
    }

    public function test_odd_sized_video_is_normalized_to_encoder_safe_even_dimensions(): void
    {
        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg || ! app(MediaBinaryDetector::class)->ffprobe()) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for the odd-dimension normalization test.');
        }

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Odd dimensions fixture',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $path = "media/{$asset->id}/odd-input.mkv";
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'mime_type' => 'video/x-matroska',
            'status' => 'ready',
            'is_active' => true,
            'compress_enabled' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'storage_target' => 'public',
                    'requested' => [
                        'compression' => true,
                        'faststart' => true,
                        'retention_policy' => 'optimized_only',
                        'allow_downloads' => true,
                        'allow_hls_streaming' => false,
                        'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->makeDirectory("media/{$asset->id}");
        (new Process([
            $ffmpeg,
            '-y',
            '-f', 'lavfi',
            '-i', 'testsrc=size=641x359:duration=1',
            '-c:v', 'ffv1',
            '-pix_fmt', 'bgr0',
            Storage::disk('public')->path($path),
        ]))->setTimeout(60)->mustRun();

        (new OptimizeMp4FaststartJob($source->id))->handle();
        $source->refresh();
        $probe = app(VideoProbeService::class)->probe(Storage::disk('public')->path((string) $source->optimized_path));

        $this->assertSame('ready', $source->optimize_status, (string) $source->optimize_error);
        $this->assertSame(0, ((int) $probe['width']) % 2);
        $this->assertSame(0, ((int) $probe['height']) % 2);
        $this->assertSame('h264', $probe['video_codec'] ?? null);
        $this->assertSame('yuv420p', $probe['pixel_format'] ?? null);
    }

    public function test_cover_art_subtitles_and_extra_audio_are_not_mapped_as_primary_output_streams(): void
    {
        $ffmpeg = app(MediaBinaryDetector::class)->ffmpeg();
        if (! $ffmpeg || ! app(MediaBinaryDetector::class)->ffprobe()) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for the multi-stream normalization test.');
        }

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Multi stream fixture',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $directory = "media/{$asset->id}";
        Storage::disk('public')->makeDirectory($directory);
        $base = Storage::disk('public')->path($directory.'/base.mp4');
        $cover = Storage::disk('public')->path($directory.'/cover.jpg');
        $subtitles = Storage::disk('public')->path($directory.'/captions.srt');
        $inputPath = $directory.'/multi-stream.mp4';
        $input = Storage::disk('public')->path($inputPath);

        (new Process([
            $ffmpeg,
            '-y',
            '-f', 'lavfi',
            '-i', 'testsrc=size=640x360:duration=1',
            '-f', 'lavfi',
            '-i', 'sine=frequency=700:duration=1',
            '-f', 'lavfi',
            '-i', 'sine=frequency=900:duration=1',
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-map', '2:a:0',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            $base,
        ]))->setTimeout(60)->mustRun();
        (new Process([
            $ffmpeg,
            '-y',
            '-f', 'lavfi',
            '-i', 'color=c=red:s=120x120',
            '-frames:v', '1',
            $cover,
        ]))->setTimeout(60)->mustRun();
        file_put_contents($subtitles, "1\n00:00:00,000 --> 00:00:00,800\nTest caption\n");
        (new Process([
            $ffmpeg,
            '-y',
            '-i', $base,
            '-i', $cover,
            '-i', $subtitles,
            '-map', '0:v:0',
            '-map', '0:a',
            '-map', '1:v:0',
            '-map', '2:0',
            '-c', 'copy',
            '-c:s', 'mov_text',
            '-disposition:v:1', 'attached_pic',
            $input,
        ]))->setTimeout(60)->mustRun();

        $inputProbe = app(VideoProbeService::class)->probe($input);
        $this->assertTrue($inputProbe['has_attached_picture']);
        $this->assertSame(2, $inputProbe['video_stream_count']);
        $this->assertSame(2, $inputProbe['audio_stream_count']);
        $this->assertSame(1, $inputProbe['subtitle_stream_count']);
        $this->assertSame(640, $inputProbe['width']);

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'storage_disk' => 'public',
            'storage_path' => $inputPath,
            'mime_type' => 'video/mp4',
            'status' => 'ready',
            'is_active' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'storage_target' => 'public',
                    'requested' => [
                        'compression' => false,
                        'faststart' => true,
                        'retention_policy' => 'optimized_only',
                        'allow_downloads' => true,
                        'allow_hls_streaming' => false,
                        'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                    ],
                ],
            ],
        ]);

        (new OptimizeMp4FaststartJob($source->id))->handle();
        $source->refresh();
        $outputProbe = app(VideoProbeService::class)->probe(
            Storage::disk('public')->path((string) $source->optimized_path),
        );

        $this->assertSame('ready', $source->optimize_status, (string) $source->optimize_error);
        $this->assertSame(1, $outputProbe['video_stream_count']);
        $this->assertSame(1, $outputProbe['audio_stream_count']);
        $this->assertSame(0, $outputProbe['subtitle_stream_count']);
        $this->assertFalse($outputProbe['has_attached_picture']);
    }
}

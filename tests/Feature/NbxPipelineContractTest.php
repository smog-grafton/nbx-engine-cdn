<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Jobs\OptimizeMp4FaststartJob;
use App\Services\NbxEngineService;
use App\Services\PipelineStateService;
use App\Services\ProcessingLiveness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NbxPipelineContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
        Storage::fake('public');
        Storage::fake('contabo');
    }

    public function test_processing_defaults_do_not_create_hls_or_retain_original(): void
    {
        $metadata = app(NbxEngineService::class)->initialMetadata([], 'remote_fetch');
        $requested = $metadata['nbx']['requested'];

        $this->assertTrue($requested['faststart']);
        $this->assertFalse($requested['compression']);
        $this->assertSame(['480p' => false, '720p' => false, '1080p' => false], $requested['hls']);
        $this->assertSame('optimized_only', $requested['retention_policy']);
    }

    public function test_telegram_processing_metadata_preserves_keep_original_only(): void
    {
        $metadata = app(NbxEngineService::class)->initialMetadata([
            'compress_enabled' => true,
            'retention_policy' => 'keep_original_only',
        ], 'telegram');

        $this->assertSame('telegram', $metadata['nbx']['input_type']);
        $this->assertTrue($metadata['nbx']['requested']['compression']);
        $this->assertSame('keep_original_only', $metadata['nbx']['requested']['retention_policy']);
    }

    public function test_mkv_duration_validation_prefers_primary_video_over_bad_container_metadata(): void
    {
        $method = new \ReflectionMethod(OptimizeMp4FaststartJob::class, 'durationValidation');
        $job = new OptimizeMp4FaststartJob(1);
        $validation = $method->invoke($job, [
            // This models the reported class of Matroska: the container says
            // 5412.18s but the actual picture timeline is 5388.46s.
            'format_duration' => 5412.18,
            'video_duration' => 5388.46,
            'audio_duration' => 5390.12,
            'primary_av_duration' => 5390.12,
        ], [
            'format_duration' => 5388.42,
            'video_duration' => 5388.42,
            'audio_duration' => 5388.44,
            'primary_av_duration' => 5388.44,
        ]);

        $this->assertSame('primary_video_stream', $validation['basis']);
        $this->assertTrue($validation['valid']);
        $this->assertTrue($validation['input_container_disagrees_with_video']);
        $this->assertSame(0.04, $validation['difference']);
    }

    public function test_explicit_portal_resolution_wins_over_profile_and_nbx_default(): void
    {
        config()->set('nbx.default_resolution', '480p');
        $asset = MediaAsset::query()->create([
            'type' => 'movie', 'title' => 'Resolution precedence', 'status' => 'ready', 'visibility' => 'public',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'upload',
            'status' => 'ready',
            'source_metadata' => app(NbxEngineService::class)->initialMetadata([
                'max_resolution' => '720p',
                'source_profile_resolution' => '480p',
            ], 'upload'),
        ]);

        $method = new \ReflectionMethod(OptimizeMp4FaststartJob::class, 'requestedMaxHeight');
        $this->assertSame(720, $method->invoke(new OptimizeMp4FaststartJob($source->id), $source, ['height' => 1080]));
    }

    public function test_compression_scale_filter_caps_height_without_square_warping(): void
    {
        $method = new \ReflectionMethod(OptimizeMp4FaststartJob::class, 'scaleFilter');
        $job = new OptimizeMp4FaststartJob(1);

        $this->assertSame('scale=-2:720', $method->invoke($job, ['width' => 1920, 'height' => 1080], 720));
        $this->assertSame(
            'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            $method->invoke($job, ['width' => 640, 'height' => 360], 720),
        );
    }

    public function test_keep_original_only_publishes_without_queuing_or_deleting_the_source(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        $asset = MediaAsset::query()->create([
            'type' => 'movie', 'title' => 'Original only', 'status' => 'ready', 'visibility' => 'public',
        ]);
        $path = "media/{$asset->id}/original.mkv";
        Storage::disk('public')->put($path, 'the-original-mkv');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_storage_path' => $path,
            'status' => 'ready',
            'external_job_id' => 'original-only-contract',
            'source_metadata' => app(NbxEngineService::class)->initialMetadata([
                'storage_target' => 'contabo',
                'retention_policy' => 'keep_original_only',
            ], 'remote_fetch'),
        ]);

        $this->assertTrue(app(\App\Services\MediaSourceService::class)->queuePlaybackProcessing($source));
        $source->refresh();

        $this->assertSame('skipped', $source->optimize_status);
        $this->assertSame('original_ready', data_get($source->source_metadata, 'nbx.status'));
        $this->assertSame(
            'videos/nbx/original-only-contract/original/original.mkv',
            data_get($source->source_metadata, 'nbx.final_artifacts.original.key'),
        );
        Storage::disk('contabo')->assertExists('videos/nbx/original-only-contract/original/original.mkv');
    }

    public function test_optimization_failure_preserves_ready_source_and_active_reconciliation_never_marks_it_failed(): void
    {
        $asset = MediaAsset::query()->create([
            'type' => 'movie', 'title' => 'Stage separation', 'status' => 'ready', 'visibility' => 'public',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'ready',
            'external_job_id' => 'stage-separation-contract',
            'source_metadata' => app(NbxEngineService::class)->initialMetadata(['storage_target' => 'contabo'], 'remote_fetch'),
        ]);

        $failed = app(PipelineStateService::class)->markOptimizationFailed($source, 'validation', 'Duration validation failed.');
        $this->assertSame('ready', $failed->status);
        $this->assertSame('failed', $failed->optimize_status);
        $this->assertSame('original_ready', data_get($failed->source_metadata, 'nbx.status'));

        $failed->update(['optimize_status' => 'processing', 'processing_stage' => 'compressing']);
        ProcessingLiveness::touch($failed->id);
        $reconciled = app(NbxEngineService::class)->reconcilePublishedArtifacts($failed);
        $this->assertSame('ready', $reconciled->status);
        $this->assertSame('processing', data_get($reconciled->source_metadata, 'nbx.publication_status'));
        $this->assertSame('processing', data_get($reconciled->source_metadata, 'nbx.reconciliation.state'));
    }

    public function test_unprocessed_mov_is_never_published_as_a_faststart_artifact(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'MOV awaiting normalization',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        Storage::disk('public')->put('media/'.$asset->id.'/movie.mov', 'original-mov');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'public',
            'storage_path' => 'media/'.$asset->id.'/movie.mov',
            'status' => 'ready',
            'optimize_status' => 'processing',
            'is_faststart' => false,
            'external_job_id' => 'never-publish-original-as-faststart',
            'is_active' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'storage_target' => 'contabo',
                    'requested' => ['retention_policy' => 'optimized_only'],
                ],
            ],
        ]);

        $result = app(NbxEngineService::class)->publishAvailableArtifacts($source, ['faststart']);

        $this->assertArrayNotHasKey('faststart', $result->source_metadata['nbx']['final_artifacts'] ?? []);
        Storage::disk('contabo')->assertMissing('videos/nbx/never-publish-original-as-faststart/faststart/movie.mov');
    }

    public function test_original_deletion_requires_and_preserves_verified_faststart_object(): void
    {
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Verified deletion',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        Storage::disk('contabo')->put('videos/job/original/movie.mov', 'original-bytes');
        Storage::disk('contabo')->put('videos/job/faststart/movie.mp4', 'optimized-bytes');

        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'ready',
            'external_job_id' => 'job-delete-contract',
            'is_active' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'status' => 'partially_completed',
                    'storage_target' => 'contabo',
                    'requested' => ['retention_policy' => 'retain_original', 'allow_downloads' => true],
                    'final_artifacts' => [
                        'original' => [
                            'disk' => 'contabo',
                            'key' => 'videos/job/original/movie.mov',
                            'url' => 'https://objects.example/videos/job/original/movie.mov',
                            'bytes' => strlen('original-bytes'),
                            'verified' => true,
                        ],
                        'faststart' => [
                            'disk' => 'contabo',
                            'key' => 'videos/job/faststart/movie.mp4',
                            'url' => 'https://objects.example/videos/job/faststart/movie.mp4',
                            'bytes' => strlen('optimized-bytes'),
                            'verified' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(NbxEngineService::class)->deleteOriginal($source, 'delete-contract-key');

        Storage::disk('contabo')->assertMissing('videos/job/original/movie.mov');
        Storage::disk('contabo')->assertExists('videos/job/faststart/movie.mp4');
        $this->assertArrayNotHasKey('original', $result->source_metadata['nbx']['final_artifacts']);
        $this->assertSame('optimized_only', $result->source_metadata['nbx']['requested']['retention_policy']);
    }

    public function test_original_deletion_is_refused_when_faststart_size_does_not_match(): void
    {
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Unsafe deletion',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        Storage::disk('contabo')->put('videos/job/original/movie.mov', 'original');
        Storage::disk('contabo')->put('videos/job/faststart/movie.mp4', 'short');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'ready',
            'external_job_id' => 'job-unsafe-delete',
            'is_active' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'final_artifacts' => [
                        'original' => [
                            'disk' => 'contabo', 'key' => 'videos/job/original/movie.mov',
                            'bytes' => 8, 'verified' => true,
                        ],
                        'faststart' => [
                            'disk' => 'contabo', 'key' => 'videos/job/faststart/movie.mp4',
                            'bytes' => 500, 'verified' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            app(NbxEngineService::class)->deleteOriginal($source, 'unsafe-delete-key');
        } finally {
            Storage::disk('contabo')->assertExists('videos/job/original/movie.mov');
        }
    }

    public function test_discovery_returns_safe_failure_contract_without_local_paths(): void
    {
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Safe failure',
            'status' => 'failed',
            'visibility' => 'unlisted',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'failed',
            'processing_stage' => 'failed',
            'failure_reason' => 'Final storage upload failed: fopen(/var/www/html/storage/private/movie.mp4): No such file',
            'processing_diagnostics' => 'ffmpeg -i /var/www/html/storage/private/movie.mp4',
            'external_job_id' => 'safe-failure-contract',
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'status' => 'failed',
                    'error_code' => 'STORAGE_UPLOAD_FAILED',
                ],
            ],
        ]);

        $payload = app(NbxEngineService::class)->discoveryPayload(
            $source,
            app(\App\Services\MediaSourceService::class),
        );

        $this->assertSame('STORAGE_UPLOAD_FAILED', $payload['error_code']);
        $this->assertTrue($payload['retryable']);
        $this->assertNotEmpty($payload['support_reference']);
        $this->assertStringNotContainsString('/var/www', $payload['failure_reason']);
        $this->assertNull($payload['processing_diagnostics']);
        $this->assertTrue($payload['diagnostics_available']);
    }

    public function test_final_storage_failure_preserves_output_and_retry_storage_does_not_reencode(): void
    {
        config()->set('filesystems.disks.contabo.bucket', 'test-bucket');
        config()->set('filesystems.disks.contabo.endpoint', 'https://objects.example');
        config()->set('filesystems.disks.contabo.key', null);
        config()->set('filesystems.disks.contabo.secret', null);
        foreach (['client_id', 'client_secret', 'username', 'password', 'user_id', 'object_storage_id'] as $key) {
            config()->set("services.contabo_api.{$key}", null);
        }
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Retry final storage',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $optimizedPath = "media/{$asset->id}/150/movie_play.mp4";
        Storage::disk('public')->put($optimizedPath, 'verified-faststart-output');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'public',
            'storage_path' => "media/{$asset->id}/150/movie-original.mp4",
            'optimized_path' => $optimizedPath,
            'mime_type' => 'video/mp4',
            'status' => 'ready',
            'optimize_status' => 'ready',
            'is_faststart' => true,
            'is_active' => true,
            'external_job_id' => 'retry-storage-job',
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'status' => 'processing',
                    'storage_target' => 'contabo',
                    'requested' => [
                        'faststart' => true,
                        'allow_downloads' => true,
                        'allow_hls_streaming' => false,
                        'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                    ],
                ],
            ],
        ]);

        $failed = app(NbxEngineService::class)->finalizeStorageIfNeeded($source);

        $this->assertSame('publication_pending', $failed->processing_stage);
        $this->assertSame('ready', $failed->status);
        $this->assertSame('publication_pending', data_get($failed->source_metadata, 'nbx.status'));
        $this->assertTrue((bool) data_get($failed->source_metadata, 'nbx.processing_complete'));
        $this->assertSame('ready', $failed->optimize_status);
        $this->assertLessThan(100, $failed->progress_percent);
        Storage::disk('public')->assertExists($optimizedPath);

        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        $retried = app(NbxEngineService::class)->performAction(
            $failed,
            'retry_storage',
            ['idempotency_key' => 'retry-storage-contract'],
            app(\App\Services\MediaSourceService::class),
        );

        $this->assertSame('ready', $retried->status);
        $this->assertSame('ready', $retried->processing_stage);
        $this->assertSame(100, $retried->progress_percent);
        $this->assertSame(
            'videos/nbx/retry-storage-job/faststart/movie_play.mp4',
            data_get($retried->source_metadata, 'nbx.final_artifacts.faststart.key'),
        );
        Storage::disk('contabo')->assertExists('videos/nbx/retry-storage-job/faststart/movie_play.mp4');
    }

    public function test_reconcile_restores_a_missing_public_url_without_reprocessing(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        $asset = MediaAsset::query()->create([
            'type' => 'episode',
            'title' => 'Already processed episode',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $key = 'videos/nbx/reconcile-url-job/faststart/episode_play.mp4';
        $mp4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2\x00\x00\x00\x08moov\x00\x00\x00\x08mdat";
        Storage::disk('contabo')->put($key, $mp4);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'failed',
            'optimize_status' => 'ready',
            'is_faststart' => true,
            'progress_percent' => 85,
            'processing_stage' => 'failed',
            'external_job_id' => 'reconcile-url-job',
            'is_active' => false,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'probe' => ['has_video' => true, 'has_audio' => true, 'duration_seconds' => 2700],
                'nbx' => [
                    'status' => 'failed',
                    'storage_target' => 'contabo',
                    'requested' => [
                        'faststart' => true,
                        'allow_hls_streaming' => false,
                        'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                    ],
                    'final_artifacts' => [
                        'faststart' => [
                            'disk' => 'contabo',
                            'key' => $key,
                            'bytes' => strlen($mp4),
                        ],
                    ],
                ],
            ],
        ]);

        $reconciled = app(NbxEngineService::class)->reconcilePublishedArtifacts($source);

        $this->assertSame('ready', $reconciled->status);
        $this->assertTrue($reconciled->is_active);
        $this->assertSame('ready', $reconciled->processing_stage);
        $this->assertSame(100, $reconciled->progress_percent);
        $this->assertSame('complete', data_get($reconciled->source_metadata, 'nbx.publication_status'));
        $this->assertNotEmpty(data_get($reconciled->source_metadata, 'nbx.final_artifacts.faststart.url'));
        $this->assertTrue((bool) data_get($reconciled->source_metadata, 'nbx.final_artifacts.faststart.inspection.fast_start'));
        $this->assertCount(1, Storage::disk('contabo')->allFiles('videos/nbx/reconcile-url-job'));

        $again = app(NbxEngineService::class)->reconcilePublishedArtifacts($reconciled);
        $this->assertSame(
            data_get($reconciled->source_metadata, 'nbx.final_artifacts.faststart.url'),
            data_get($again->source_metadata, 'nbx.final_artifacts.faststart.url'),
        );
        $this->assertCount(1, Storage::disk('contabo')->allFiles('videos/nbx/reconcile-url-job'));
    }

    public function test_reconcile_does_not_mislabel_a_legacy_mkv_as_faststart_mp4(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        $asset = MediaAsset::query()->create([
            'type' => 'episode',
            'title' => 'Legacy mislabeled package',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $originalKey = 'videos/nbx/legacy-mkv-job/original/episode.mkv';
        $faststartKey = 'videos/nbx/legacy-mkv-job/faststart/episode.mkv';
        Storage::disk('contabo')->put($originalKey, 'same-matroska-bytes');
        Storage::disk('contabo')->put($faststartKey, 'same-matroska-bytes');
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'status' => 'failed',
            'optimize_status' => 'ready',
            'is_faststart' => true,
            'progress_percent' => 85,
            'external_job_id' => 'legacy-mkv-job',
            'is_active' => false,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'probe' => ['has_video' => true, 'duration_seconds' => 2400, 'container' => 'matroska,webm'],
                'nbx' => [
                    'status' => 'failed',
                    'storage_target' => 'contabo',
                    'requested' => [
                        'faststart' => true,
                        'allow_hls_streaming' => false,
                        'hls' => ['480p' => false, '720p' => false, '1080p' => false],
                    ],
                ],
            ],
        ]);

        $reconciled = app(NbxEngineService::class)->reconcilePublishedArtifacts($source);

        $this->assertSame('ready', $reconciled->status);
        $this->assertFalse($reconciled->is_active);
        $this->assertSame('publication_attention', $reconciled->processing_stage);
        $this->assertSame(100, $reconciled->progress_percent);
        $this->assertSame('attention_required', data_get($reconciled->source_metadata, 'nbx.publication_status'));
        $this->assertNotEmpty(data_get($reconciled->source_metadata, 'nbx.final_artifacts.original.url'));
        $this->assertNull(data_get($reconciled->source_metadata, 'nbx.final_artifacts.faststart.url'));
        $this->assertNull(app(\App\Services\MediaSourceService::class)->buildMp4PlayUrl($reconciled));
    }
}

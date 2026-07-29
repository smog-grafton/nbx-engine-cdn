<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\NbxEngineService;
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

        $this->assertSame('failed', $failed->processing_stage);
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
}

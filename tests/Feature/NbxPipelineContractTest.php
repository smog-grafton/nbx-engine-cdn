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
}

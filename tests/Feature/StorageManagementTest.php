<?php

namespace Tests\Feature;

use App\Models\MediaApiToken;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Models\StorageObjectReference;
use App\Services\ContaboObjectBrowserService;
use App\Services\StorageDeletionService;
use App\Services\StorageReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('contabo');
        config()->set('filesystems.disks.contabo.bucket', 'test-bucket');
        config()->set('services.contabo_object_storage.disk', 'contabo');
        config()->set('services.contabo_object_storage.path_prefix', 'videos');
        config()->set('nbx.portal_storage_callback_url', 'https://portal.example/api/v1/nbx/storage-events');
        config()->set('nbx.webhook_secret', 'test-storage-secret');
    }

    public function test_direct_contabo_registration_creates_metadata_without_copying_the_object(): void
    {
        Storage::disk('contabo')->put('videos/direct/movie.mp4', 'movie-data');

        $reference = app(StorageReferenceService::class)->register([
            'idempotency_key' => 'register-direct-one',
            'portal_source_id' => 93,
            'portal_sourceable_type' => 'App\\Models\\Movie',
            'portal_sourceable_id' => 12,
            'storage_disk' => 'contabo',
            'storage_bucket' => 'test-bucket',
            'object_key' => 'videos/direct/movie.mp4',
            'object_url' => 'https://objects.example/videos/direct/movie.mp4?secret=removed',
            'media_role' => 'faststart_mp4',
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        $this->assertSame(93, $reference->portal_source_id);
        $this->assertTrue($reference->is_external_direct);
        $this->assertNotNull($reference->media_source_id);
        $this->assertSame(
            'https://objects.example/videos/direct/movie.mp4',
            $reference->object_url,
        );
        Storage::disk('contabo')->assertExists('videos/direct/movie.mp4');
        $this->assertCount(1, Storage::disk('contabo')->allFiles('videos/direct'));
    }

    public function test_storage_browser_refuses_missing_s3_credentials_before_aws_metadata_lookup(): void
    {
        config()->set('filesystems.disks.contabo', [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'region' => 'usc1',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://usc1.contabostorage.com',
            'use_path_style_endpoint' => true,
            'throw' => false,
        ]);
        foreach ([
            'client_id',
            'client_secret',
            'username',
            'password',
            'user_id',
            'object_storage_id',
        ] as $key) {
            config()->set("services.contabo_api.{$key}", null);
        }
        Storage::forgetDisk('contabo');

        try {
            app(ContaboObjectBrowserService::class)->list('videos');
            $this->fail('Expected missing Contabo credentials to stop the listing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('S3 keys are blank', $exception->getMessage());
            $this->assertStringNotContainsString('169.254.169.254', $exception->getMessage());
        }
    }

    public function test_direct_deletion_reconciles_portal_before_and_after_verified_storage_removal(): void
    {
        Http::fake(['portal.example/*' => Http::response(['ok' => true])]);
        Storage::disk('contabo')->put('videos/direct/delete-me.mp4', 'movie-data');
        $reference = $this->directReference('videos/direct/delete-me.mp4');

        $summary = app(StorageDeletionService::class)->deleteReference($reference);

        Storage::disk('contabo')->assertMissing('videos/direct/delete-me.mp4');
        $this->assertSame(strlen('movie-data'), $summary['bytes_freed']);
        $this->assertFalse($reference->fresh()->is_active);
        $this->assertNotNull($reference->fresh()->deleted_at_storage);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['phase'] === 'planned'
            && $request['portal_source_id'] === 93
            && str_starts_with((string) $request->header('X-NBX-Signature')[0], 'sha256=')
        );
        Http::assertSent(fn ($request): bool => $request['phase'] === 'deleted'
            && $request['portal_source_id'] === 93
        );
    }

    public function test_direct_deletion_stops_before_storage_when_portal_cannot_reconcile(): void
    {
        Http::fake(['portal.example/*' => Http::response(['message' => 'unavailable'], 503)]);
        Storage::disk('contabo')->put('videos/direct/keep-me.mp4', 'movie-data');
        $reference = $this->directReference('videos/direct/keep-me.mp4');

        try {
            app(StorageDeletionService::class)->deleteReference($reference);
            $this->fail('Expected the Portal reconciliation failure to stop deletion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        Storage::disk('contabo')->assertExists('videos/direct/keep-me.mp4');
        $this->assertTrue($reference->fresh()->is_active);
    }

    public function test_faststart_deletion_is_refused_before_portal_notification_without_verified_hls(): void
    {
        Http::fake(['portal.example/*' => Http::response(['ok' => true])]);
        Storage::disk('contabo')->put('videos/job/faststart/movie_play.mp4', 'faststart-data');
        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Safe deletion preflight',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'storage_disk' => 'contabo',
            'storage_path' => 'videos/job/faststart/movie_play.mp4',
            'optimized_path' => 'videos/job/faststart/movie_play.mp4',
            'status' => 'ready',
            'is_active' => true,
            'source_metadata' => [
                'provider' => 'nbx_engine',
                'nbx' => [
                    'requested' => ['allow_downloads' => true],
                    'final_artifacts' => [
                        'faststart' => [
                            'disk' => 'contabo',
                            'key' => 'videos/job/faststart/movie_play.mp4',
                            'bytes' => strlen('faststart-data'),
                            'verified' => true,
                        ],
                    ],
                ],
            ],
        ]);
        app(StorageReferenceService::class)->register([
            'idempotency_key' => 'faststart-preflight-reference',
            'portal_source_id' => 94,
            'portal_sourceable_type' => 'App\\Models\\Movie',
            'portal_sourceable_id' => 13,
            'storage_disk' => 'contabo',
            'storage_bucket' => 'test-bucket',
            'object_key' => 'videos/job/faststart/movie_play.mp4',
            'object_url' => 'https://objects.example/videos/job/faststart/movie_play.mp4',
            'media_role' => 'faststart_mp4',
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        try {
            app(StorageDeletionService::class)->deleteSourceArtifact(
                $source,
                'faststart',
                ['disable_downloads' => true],
            );
            $this->fail('Expected deletion to be refused without verified HLS.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('verified HLS', $exception->getMessage());
        }

        Storage::disk('contabo')->assertExists('videos/job/faststart/movie_play.mp4');
        Http::assertNothingSent();
    }

    public function test_storage_api_requires_the_requested_token_ability(): void
    {
        [$token, $plain] = MediaApiToken::issue('read-only', ['storage.view']);

        $this->withToken($plain)
            ->getJson('/api/v1/storage/objects?limit=10')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($plain)
            ->deleteJson('/api/v1/storage/objects/orphan', [
                'object_key' => 'videos/orphan.mp4',
                'confirmation' => 'DELETE ORPHAN '.substr(hash('sha256', 'videos/orphan.mp4'), 0, 12),
            ])
            ->assertUnauthorized();

        $this->assertNotNull($token);
    }

    private function directReference(string $key): StorageObjectReference
    {
        return app(StorageReferenceService::class)->register([
            'idempotency_key' => 'register-'.hash('sha256', $key),
            'portal_source_id' => 93,
            'portal_sourceable_type' => 'App\\Models\\Movie',
            'portal_sourceable_id' => 12,
            'storage_disk' => 'contabo',
            'storage_bucket' => 'test-bucket',
            'object_key' => $key,
            'object_url' => 'https://objects.example/'.$key,
            'media_role' => 'faststart_mp4',
            'is_primary' => false,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);
    }
}

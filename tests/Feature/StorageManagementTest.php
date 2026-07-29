<?php

namespace Tests\Feature;

use App\Jobs\ExecuteStorageCleanupPlanJob;
use App\Models\MediaApiToken;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Models\StorageCleanupPlan;
use App\Models\StorageInventoryObject;
use App\Models\StorageObjectReference;
use App\Models\User;
use App\Services\ContaboObjectBrowserService;
use App\Services\StorageDeletionService;
use App\Services\StorageInventoryService;
use App\Services\StorageReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_storage_browser_filters_across_the_listing_not_only_the_first_raw_page(): void
    {
        foreach (range(1, 60) as $index) {
            Storage::disk('contabo')->put(
                sprintf('videos/movies/1/filler-%03d.mp4', $index),
                'filler',
            );
        }
        Storage::disk('contabo')->put('videos/movies/999/wanted-large-file.mp4', 'wanted');

        $result = app(ContaboObjectBrowserService::class)->list(
            'videos',
            null,
            10,
            'wanted-large-file',
        );

        $this->assertCount(1, $result['objects']);
        $this->assertSame('videos/movies/999/wanted-large-file.mp4', $result['objects'][0]['key']);
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

    public function test_inventory_groups_hls_and_treats_legacy_portal_keys_as_candidates_not_orphans(): void
    {
        Storage::disk('contabo')->put('videos/movies/1431/movie.mp4', 'legacy-movie');
        Storage::disk('contabo')->put('media/019fa7a5-7989-7157-a354-dc4d5c39c7b5/150/movie_play.mp4', 'play');
        Storage::disk('contabo')->put('media/019fa7a5-7989-7157-a354-dc4d5c39c7b5/150/hls/master.m3u8', '#EXTM3U');
        Storage::disk('contabo')->put('media/019fa7a5-7989-7157-a354-dc4d5c39c7b5/150/hls/480p/segment_000.ts', 'segment');

        $run = app(StorageInventoryService::class)->createRun();
        app(StorageInventoryService::class)->scan($run);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(4, $run->fresh()->object_count);
        $this->assertSame(
            3,
            StorageInventoryObject::query()
                ->where('logical_asset_key', 'media/019fa7a5-7989-7157-a354-dc4d5c39c7b5/150')
                ->count(),
        );
        $legacy = StorageInventoryObject::query()
            ->where('object_key', 'videos/movies/1431/movie.mp4')
            ->firstOrFail();
        $this->assertSame('portal_legacy', $legacy->storage_layout);
        $this->assertSame('portal_candidate', $legacy->classification);
        $this->assertSame('App\\Models\\Movie', $legacy->portal_sourceable_type);
        $this->assertSame(1431, $legacy->portal_sourceable_id);
        $this->assertSame(
            2,
            StorageInventoryObject::query()->where('is_manifest_member', true)->count(),
        );
    }

    public function test_cleanup_review_has_a_grace_period_and_never_deletes_objects(): void
    {
        Storage::disk('contabo')->put('videos/movies/1431/movie.mp4', 'legacy-movie');
        $run = app(StorageInventoryService::class)->createRun();
        app(StorageInventoryService::class)->scan($run);

        $plan = app(StorageInventoryService::class)->createCleanupReview('videos/movies/1431', null);

        $this->assertSame('draft', $plan->status);
        $this->assertSame('review_only', $plan->items()->firstOrFail()->proposed_action);
        $this->assertTrue($plan->grace_expires_at->isFuture());
        Storage::disk('contabo')->assertExists('videos/movies/1431/movie.mp4');
    }

    public function test_orphan_deletion_is_refused_without_inventory_confirmation_and_expired_plan(): void
    {
        Storage::disk('contabo')->put('unknown/unreviewed.mp4', 'do-not-delete');

        try {
            app(StorageDeletionService::class)->deleteConfirmedOrphan('contabo', 'unknown/unreviewed.mp4');
            $this->fail('Expected unreviewed object deletion to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('explicitly confirmed', $exception->getMessage());
        }

        Storage::disk('contabo')->assertExists('unknown/unreviewed.mp4');
    }

    public function test_duplicate_signature_requires_streamed_sha256_verification_before_becoming_exact_evidence(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        $content = 'byte-identical-video-content';
        Storage::disk('contabo')->put('videos/movies/1/original.mp4', $content);
        Storage::disk('contabo')->put('videos/movies/1/movie_play.mp4', $content);
        $groupHash = hash('sha256', 'same-etag|'.strlen($content));
        foreach (['original.mp4', 'movie_play.mp4'] as $filename) {
            $key = 'videos/movies/1/'.$filename;
            StorageInventoryObject::query()->create([
                'object_hash' => hash('sha256', 'contabo|test-bucket|'.$key),
                'storage_disk' => 'contabo',
                'storage_bucket' => 'test-bucket',
                'object_key' => $key,
                'object_prefix' => 'videos/movies/1',
                'filename' => $filename,
                'extension' => 'mp4',
                'size_bytes' => strlen($content),
                'etag' => 'same-etag',
                'storage_layout' => 'portal_legacy',
                'logical_asset_key' => 'videos/movies/1',
                'media_role' => str_contains($filename, '_play') ? 'faststart_mp4' : 'download_asset',
                'is_duplicate_candidate' => true,
                'duplicate_group_hash' => $groupHash,
                'duplicate_evidence' => 'etag_and_size',
                'classification' => 'portal_candidate',
                'confidence' => 'medium',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $verified = app(StorageInventoryService::class)->verifyDuplicateGroup($groupHash);

        $this->assertSame(2, $verified);
        $this->assertSame(
            2,
            StorageInventoryObject::query()->where('duplicate_evidence', 'sha256')->count(),
        );
        $this->assertSame(
            hash('sha256', $content),
            StorageInventoryObject::query()->firstOrFail()->content_sha256,
        );
    }

    public function test_confirmed_cleanup_job_rechecks_deletes_a_single_duplicate_and_queues_inventory_refresh(): void
    {
        config()->set('filesystems.disks.contabo.key', 'test-key');
        config()->set('filesystems.disks.contabo.secret', 'test-secret');
        Queue::fake();
        $content = 'verified duplicate bytes';
        $checksum = hash('sha256', $content);
        $objects = collect(['delete.mp4', 'retain.mp4'])->map(function (string $filename) use ($content, $checksum) {
            $key = 'unresolved/package/'.$filename;
            Storage::disk('contabo')->put($key, $content);

            return StorageInventoryObject::query()->create([
                'object_hash' => hash('sha256', 'contabo|test-bucket|'.$key),
                'storage_disk' => 'contabo',
                'storage_bucket' => 'test-bucket',
                'object_key' => $key,
                'object_prefix' => 'unresolved/package',
                'filename' => $filename,
                'extension' => 'mp4',
                'size_bytes' => strlen($content),
                'storage_layout' => 'unknown',
                'logical_asset_key' => 'unresolved/package',
                'media_role' => 'download_asset',
                'is_duplicate_candidate' => true,
                'duplicate_group_hash' => hash('sha256', 'sha256|'.$checksum.'|'.strlen($content)),
                'duplicate_evidence' => 'sha256',
                'content_sha256' => $checksum,
                'checksum_verified_at' => now(),
                'classification' => $filename === 'delete.mp4' ? 'orphan_confirmed' : 'unresolved',
                'confidence' => $filename === 'delete.mp4' ? 'high' : 'low',
                'first_seen_at' => now()->subDays(8),
                'last_seen_at' => now(),
            ]);
        });
        $plan = StorageCleanupPlan::query()->create([
            'status' => 'queued',
            'logical_asset_key' => 'unresolved/package',
            'object_count' => 2,
            'total_bytes' => strlen($content) * 2,
            'risk_level' => 'high',
            'grace_expires_at' => now()->subMinute(),
            'confirmed_at' => now()->subMinute(),
        ]);
        $plan->items()->create([
            'storage_inventory_object_id' => $objects[0]->id,
            'proposed_action' => 'delete_exact_duplicate',
            'status' => 'approved',
        ]);
        $plan->items()->create([
            'storage_inventory_object_id' => $objects[1]->id,
            'proposed_action' => 'review_only',
            'status' => 'kept',
        ]);

        app(ExecuteStorageCleanupPlanJob::class, [
            'planId' => $plan->id,
            'userId' => null,
        ])->handle(
            app(StorageDeletionService::class),
            app(StorageInventoryService::class),
        );

        Storage::disk('contabo')->assertMissing('unresolved/package/delete.mp4');
        Storage::disk('contabo')->assertExists('unresolved/package/retain.mp4');
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame('deleted', $plan->items()->where('status', 'deleted')->firstOrFail()->status);
        Queue::assertPushed(\App\Jobs\ScanContaboStorageInventoryJob::class);
    }

    public function test_storage_inventory_and_cleanup_review_admin_pages_render(): void
    {
        $user = User::factory()->create(['storage_permissions' => ['*']]);
        $plan = StorageCleanupPlan::query()->create([
            'status' => 'draft',
            'logical_asset_key' => 'videos/movies/1431',
            'object_count' => 0,
            'total_bytes' => 0,
            'risk_level' => 'high',
            'grace_expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($user)
            ->get('/admin/contabo-storage-manager')
            ->assertOk()
            ->assertSee('Logical packages');
        $this->actingAs($user)
            ->get('/admin/storage-cleanup-plans/'.$plan->id)
            ->assertOk()
            ->assertSee('videos/movies/1431');
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

<?php

namespace Tests\Feature;

use App\Services\Storage\AutomaticStorageSelector;
use App\Services\Storage\StorageTargetException;
use App\Services\Storage\StorageTargetRegistry;
use App\Services\Storage\StorageUsageService;
use Tests\TestCase;

class StorageTargetSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'storage_targets.targets.contabo_nbx' => [
                'label' => 'NaraBox Legacy Storage — nbx',
                'provider' => 'contabo',
                'disk' => 'contabo_nbx',
                'endpoint' => 'https://usc1.contabostorage.com',
                'region' => 'usc1',
                'bucket' => 'nbx',
                'public_url' => 'https://usc1.contabostorage.com/tenant:nbx',
                'path_prefix' => 'videos',
                'enabled' => true,
                'writable' => true,
                'priority' => 20,
                'capacity_bytes' => 536870912000,
                'reserve_percent' => 10,
                'known_used_bytes' => 471073193164, // ~438.66GB (~87.7%)
                'known_used_at' => now()->toIso8601String(),
            ],
            'storage_targets.targets.contabo_nb_nbx' => [
                'label' => 'NaraBox Storage 2 — nb-nbx',
                'provider' => 'contabo',
                'disk' => 'contabo_nb_nbx',
                'endpoint' => 'https://usc1.contabostorage.com',
                'region' => 'usc1',
                'bucket' => 'nb-nbx',
                'public_url' => 'https://usc1.contabostorage.com/nb-nbx',
                'path_prefix' => 'videos',
                'enabled' => true,
                'writable' => true,
                'priority' => 100,
                'capacity_bytes' => 536870912000,
                'reserve_percent' => 10,
                'known_used_bytes' => 0,
                'known_used_at' => now()->toIso8601String(),
            ],
            'storage_targets.legacy_target_key' => 'contabo_nbx',
        ]);
    }

    public function test_missing_target_normalizes_to_legacy_contabo_nbx(): void
    {
        $registry = app(StorageTargetRegistry::class);

        $this->assertSame('contabo_nbx', $registry->normalizeStoredKey(null));
        $this->assertSame('contabo_nbx', $registry->normalizeStoredKey('contabo'));
        $this->assertSame('contabo_nb_nbx', $registry->normalizeStoredKey('contabo_nb_nbx'));
    }

    public function test_explicit_targets_resolve_to_correct_buckets(): void
    {
        $selector = app(AutomaticStorageSelector::class);

        $this->assertSame('nbx', $selector->resolveExplicit('contabo_nbx', 1000)['target']->bucket);
        $this->assertSame('nb-nbx', $selector->resolveExplicit('contabo_nb_nbx', 1000)['target']->bucket);
    }

    public function test_automatic_selection_prefers_nb_nbx_while_nbx_is_near_soft_limit(): void
    {
        $resolution = app(AutomaticStorageSelector::class)->resolveAutomatic(1_000_000);

        $this->assertSame('contabo_nb_nbx', $resolution['target']->key);
        $this->assertTrue($resolution['auto']);
    }

    public function test_disabled_target_is_skipped(): void
    {
        config(['storage_targets.targets.contabo_nb_nbx.enabled' => false]);

        $resolution = app(AutomaticStorageSelector::class)->resolveAutomatic(1_000_000);
        $this->assertSame('contabo_nbx', $resolution['target']->key);

        $this->expectException(StorageTargetException::class);
        app(AutomaticStorageSelector::class)->resolveExplicit('contabo_nb_nbx', 1000);
    }

    public function test_insufficient_capacity_fails_before_processing_begins(): void
    {
        config(['storage_targets.targets.contabo_nb_nbx.known_used_bytes' => 500000000000]);

        $this->expectException(StorageTargetException::class);
        app(AutomaticStorageSelector::class)->resolveAutomatic(50_000_000_000);
    }

    public function test_transcode_output_estimate_applies_safety_multiplier(): void
    {
        config(['storage_targets.transcode_safety_multiplier' => 2.5]);

        $estimate = app(AutomaticStorageSelector::class)->estimateTranscodeOutputBytes(1_000_000_000);

        $this->assertSame(2_500_000_000, $estimate);
    }

    public function test_usage_service_tracks_incremental_uploads(): void
    {
        $registry = app(StorageTargetRegistry::class);
        $usage = app(StorageUsageService::class);
        $target = $registry->findOrFail('contabo_nb_nbx');

        $before = $usage->usageFor($target)['used_bytes'];
        $usage->recordUpload('contabo_nb_nbx', 5_000_000);
        $after = $usage->usageFor($target)['used_bytes'];

        $this->assertSame($before + 5_000_000, $after);
    }

    public function test_adding_a_third_target_requires_no_schema_change(): void
    {
        config(['storage_targets.targets.contabo_third' => [
            'label' => 'Third Storage',
            'provider' => 'contabo',
            'disk' => 'contabo_third',
            'endpoint' => 'https://usc1.contabostorage.com',
            'region' => 'usc1',
            'bucket' => 'third',
            'public_url' => 'https://usc1.contabostorage.com/third',
            'path_prefix' => 'videos',
            'enabled' => true,
            'writable' => true,
            'priority' => 50,
            'capacity_bytes' => 100000000000,
            'reserve_percent' => 10,
            'known_used_bytes' => 0,
            'known_used_at' => null,
        ]]);

        $registry = app(StorageTargetRegistry::class);
        $this->assertArrayHasKey('contabo_third', $registry->all());
        $this->assertSame('third', $registry->find('contabo_third')->bucket);
    }
}

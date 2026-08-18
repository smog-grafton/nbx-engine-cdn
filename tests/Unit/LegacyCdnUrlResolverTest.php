<?php

namespace Tests\Unit;

use App\Support\LegacyCdnUrlResolver;
use Tests\TestCase;

class LegacyCdnUrlResolverTest extends TestCase
{
    public function test_it_resolves_legacy_media_urls_idempotently_and_preserves_query(): void
    {
        config()->set('cdn.legacy_cdn_hosts', ['cdn.naraboxtv.com']);
        $resolver = app(LegacyCdnUrlResolver::class);

        $legacy = 'http://cdn.naraboxtv.com/media/foo%20bar.mp4?token=abc';
        $resolved = 'https://cdn.naraboxtv.com/storage/app/public/media/foo%20bar.mp4?token=abc';

        $this->assertSame($resolved, $resolver->resolve($legacy));
        $this->assertSame($resolved, $resolver->resolve($resolved));
        $this->assertTrue($resolver->isLegacyMediaUrl($resolved));
    }

    public function test_it_does_not_modify_other_hosts_or_malformed_values(): void
    {
        $resolver = app(LegacyCdnUrlResolver::class);

        $this->assertSame('https://nbx.naraboxtv.com/media/movie.mp4', $resolver->resolve('https://nbx.naraboxtv.com/media/movie.mp4'));
        $this->assertSame('https://example.com/media/movie.mp4', $resolver->resolve('https://example.com/media/movie.mp4'));
        $this->assertNull($resolver->resolve(null));
        $this->assertSame('not-a-url', $resolver->resolve('not-a-url'));
    }
}

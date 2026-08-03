<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\ResumableRemoteFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResumableRemoteFetcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
        config()->set('cdn.remote_fetch_chunk_bytes', 1024 * 1024);
        config()->set('cdn.remote_fetch_retry_wait_ms', 0);
    }

    public function test_content_range_parser_rejects_unsafe_or_inconsistent_values(): void
    {
        $this->assertSame(
            ['start' => 1048576, 'end' => 2097151, 'total' => 531149595],
            ResumableRemoteFetcher::parseContentRange('bytes 1048576-2097151/531149595')
        );
        $this->assertNull(ResumableRemoteFetcher::parseContentRange('bytes 10-9/100'));
        $this->assertNull(ResumableRemoteFetcher::parseContentRange('bytes */100'));
        $this->assertNull(ResumableRemoteFetcher::parseContentRange(null));
    }

    public function test_it_assembles_verified_ranges_and_resumes_from_the_existing_offset(): void
    {
        $payload = str_repeat('a', 1024 * 1024)
            .str_repeat('b', 1024 * 1024)
            .str_repeat('c', 3217);
        $url = 'https://example.com/download.php?file=movie.mp4';
        $ranges = [];

        Http::fake(function (Request $request) use ($payload, &$ranges) {
            $range = $request->header('Range')[0] ?? null;
            $this->assertNotNull($range);
            $this->assertSame('identity', $request->header('Accept-Encoding')[0] ?? null);
            $this->assertSame('https://example.com/', $request->header('Referer')[0] ?? null);
            preg_match('/bytes=(\d+)-(\d+)/', (string) $range, $matches);
            $start = (int) $matches[1];
            $end = min((int) $matches[2], strlen($payload) - 1);
            $ranges[] = [$start, $end];

            return Http::response(substr($payload, $start, ($end - $start) + 1), 206, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) (($end - $start) + 1),
                'Content-Range' => "bytes {$start}-{$end}/".strlen($payload),
                'ETag' => '"fixture-v1"',
            ]);
        });

        $asset = MediaAsset::query()->create([
            'type' => 'movie',
            'title' => 'Resumable fixture',
            'status' => 'importing',
            'visibility' => 'unlisted',
        ]);
        $source = MediaSource::query()->create([
            'media_asset_id' => $asset->id,
            'source_type' => 'remote_fetch',
            'source_url' => $url,
            'status' => 'downloading',
        ]);
        $destination = storage_path('framework/testing/resumable-'.$source->id.'.part');
        @unlink($destination);

        try {
            $fetcher = app(ResumableRemoteFetcher::class);
            $fetcher->download($source, $url, $destination, static function (): void {});

            $this->assertSame($payload, file_get_contents($destination));
            $this->assertDatabaseHas('remote_fetch_sessions', [
                'media_source_id' => $source->id,
                'status' => 'completed',
                'bytes_downloaded' => strlen($payload),
            ]);
            $this->assertDatabaseCount('remote_fetch_parts', 3);

            // Simulate a process dying after the first committed MiB. The next
            // coordinator run must begin at that byte instead of restarting zero.
            $handle = fopen($destination, 'c+b');
            ftruncate($handle, 1024 * 1024);
            fclose($handle);
            $ranges = [];

            app(ResumableRemoteFetcher::class)->download(
                $source->fresh(),
                $url,
                $destination,
                static function (): void {}
            );

            $this->assertSame([0, 1], $ranges[0]); // probe
            $this->assertSame(1024 * 1024, $ranges[1][0]);
            $this->assertSame($payload, file_get_contents($destination));
        } finally {
            @unlink($destination);
            foreach (glob($destination.'.range-*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }
}

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

    public function test_probe_rejects_an_html_error_page_before_download(): void
    {
        Http::fake(fn (Request $request) => Http::response(
            '<!doctype html><html><body>Origin error</body></html>',
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Length' => '56',
            ],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Remote source returned HTML instead of a video file.');
        $this->expectExceptionMessage('HTTP status: 200');
        $this->expectExceptionMessage('Content-Type: text/html');

        app(ResumableRemoteFetcher::class)->probe('https://example.com/error');
    }

    public function test_probe_accepts_binary_media_with_a_generic_content_type(): void
    {
        $payload = "\0\0\0\x18ftypisom\0\0\0\0";
        Http::fake(fn (Request $request) => Http::response($payload, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($payload),
        ]));

        $probe = app(ResumableRemoteFetcher::class)->probe('https://example.com/movie');

        $this->assertSame(200, $probe->httpStatus);
        $this->assertSame('application/octet-stream', $probe->contentType);
        $this->assertSame(strlen($payload), $probe->expectedSize);
        $this->assertFalse($probe->supportsRanges);
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
            $probe = $fetcher->download($source, $url, $destination, static function (): void {});

            $this->assertSame($payload, file_get_contents($destination));
            $this->assertSame(206, $probe->httpStatus);
            $this->assertSame(strlen($payload), $probe->expectedSize);
            $this->assertTrue($probe->supportsRanges);
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

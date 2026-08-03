<?php

namespace App\Services;

use App\Models\MediaSource;
use App\Models\RemoteFetchPart;
use App\Models\RemoteFetchSession;
use App\Support\SafeRemoteMediaUrl;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class ResumableRemoteFetcher
{
    private CookieJar $cookies;

    public function __construct()
    {
        $this->cookies = new CookieJar;
    }

    public function probe(string $url): RemoteFetchProbe
    {
        $url = SafeRemoteMediaUrl::assertAllowed($url);
        $response = $this->request($url, (int) config('cdn.remote_fetch_probe_timeout', 45))
            // Use two bytes. Some legacy PHP range handlers incorrectly treat
            // bytes=0-0 as an empty/falsy end and stream the complete object.
            ->withHeaders(['Range' => 'bytes=0-1'])
            ->withOptions(array_merge($this->requestOptions(), ['stream' => true]))
            ->get($url);

        if (! in_array($response->status(), [200, 206], true)) {
            $response->throw();
            throw new \RuntimeException('Remote probe failed with HTTP '.$response->status().'.');
        }

        $contentRange = self::parseContentRange($response->header('Content-Range'));
        $supportsRanges = $response->status() === 206
            && $contentRange !== null
            && $contentRange['start'] === 0
            && $contentRange['end'] === min(1, $contentRange['total'] - 1);

        if ($response->status() === 206 && ! $supportsRanges) {
            $this->closeResponseBody($response);
            throw new \RuntimeException('Remote server returned an invalid Content-Range for the range probe.');
        }

        $contentLength = $this->numericHeader($response->header('Content-Length'));
        $expectedSize = $contentRange['total'] ?? ($response->status() === 200 ? $contentLength : null);
        $contentType = $this->normalizedContentType($response->header('Content-Type'));
        $finalUrl = $this->effectiveUrl($response, $url);
        $this->closeResponseBody($response);

        return new RemoteFetchProbe(
            originalUrl: $url,
            finalUrl: SafeRemoteMediaUrl::assertAllowed($finalUrl),
            expectedSize: $expectedSize,
            contentType: $contentType,
            etag: $this->headerOrNull($response->header('ETag')),
            lastModified: $this->headerOrNull($response->header('Last-Modified')),
            supportsRanges: $supportsRanges,
        );
    }

    public function download(
        MediaSource $source,
        string $url,
        string $destination,
        callable $progress,
        ?RemoteFetchProbe $probe = null,
    ): RemoteFetchProbe {
        $probe ??= $this->probe($url);
        $session = $this->prepareSession($source, $probe, $destination);

        if (! $probe->supportsRanges || ! $probe->expectedSize) {
            $this->downloadWholeFile($session, $probe, $destination, $progress);

            return $probe;
        }

        $this->downloadRanges($session, $probe, $destination, $progress);

        return $probe;
    }

    public function markAwaitingFallback(MediaSource $source, string $error): void
    {
        RemoteFetchSession::query()->where('media_source_id', $source->id)->update([
            'status' => 'awaiting_worker',
            'last_error' => $error,
            'last_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{start: int, end: int, total: int}|null
     */
    public static function parseContentRange(?string $value): ?array
    {
        if (! is_string($value) || preg_match('/^bytes\s+(\d+)-(\d+)\/(\d+)$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];
        $total = (int) $matches[3];

        if ($start > $end || $end >= $total || $total <= 0) {
            return null;
        }

        return compact('start', 'end', 'total');
    }

    private function downloadRanges(
        RemoteFetchSession $session,
        RemoteFetchProbe $probe,
        string $destination,
        callable $progress,
    ): void {
        $expectedSize = (int) $probe->expectedSize;
        $chunkSize = max(128 * 1024, (int) config('cdn.remote_fetch_chunk_bytes', 1024 * 1024));
        $localSize = is_file($destination) ? (int) filesize($destination) : 0;

        if ($localSize > $expectedSize) {
            $this->resetSession($session, $destination);
            $localSize = 0;
        }

        $session->parts()->where('end_byte', '<', $localSize)->update([
            'status' => 'completed',
            'last_error' => null,
            'completed_at' => now(),
        ]);

        while ($localSize < $expectedSize) {
            $start = $localSize;
            $end = min($expectedSize - 1, $start + $chunkSize - 1);
            $part = RemoteFetchPart::query()->firstOrCreate(
                ['remote_fetch_session_id' => $session->id, 'start_byte' => $start],
                ['end_byte' => $end, 'status' => 'pending']
            );

            if ((int) $part->end_byte !== $end) {
                $part->update(['end_byte' => $end, 'downloaded_bytes' => 0, 'status' => 'pending']);
            }

            $this->downloadPart($session, $part->fresh(), $probe, $destination, $progress);
            clearstatcache(true, $destination);
            $localSize = is_file($destination) ? (int) filesize($destination) : 0;
        }

        if ($localSize !== $expectedSize) {
            throw new \RuntimeException("Resumable download size mismatch: received {$localSize} of {$expectedSize} bytes.");
        }

        $session->update([
            'status' => 'completed',
            'bytes_downloaded' => $localSize,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_heartbeat_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function downloadPart(
        RemoteFetchSession $session,
        RemoteFetchPart $part,
        RemoteFetchProbe $probe,
        string $destination,
        callable $progress,
    ): void {
        $chunkPath = $destination.'.range-'.$part->start_byte.'-'.$part->end_byte;
        $expectedBytes = ((int) $part->end_byte - (int) $part->start_byte) + 1;
        $attemptsThisRun = 0;
        $maxAttempts = max(1, (int) config('cdn.remote_fetch_part_attempts', 20));

        while ((is_file($chunkPath) ? (int) filesize($chunkPath) : 0) < $expectedBytes) {
            clearstatcache(true, $chunkPath);
            $partialBytes = is_file($chunkPath) ? (int) filesize($chunkPath) : 0;
            if ($partialBytes > $expectedBytes) {
                @unlink($chunkPath);
                $partialBytes = 0;
            }

            $requestStart = (int) $part->start_byte + $partialBytes;
            $attemptsThisRun++;
            $part->increment('attempts');
            $session->increment('attempts');
            $part->update(['status' => 'fetching', 'downloaded_bytes' => $partialBytes, 'last_error' => null]);
            $session->update(['status' => 'fetching', 'last_heartbeat_at' => now(), 'last_error' => null]);

            try {
                $this->requestRangeToFile(
                    $probe,
                    $requestStart,
                    (int) $part->end_byte,
                    $chunkPath
                );
            } catch (\Throwable $error) {
                clearstatcache(true, $chunkPath);
                $received = is_file($chunkPath) ? min($expectedBytes, (int) filesize($chunkPath)) : 0;
                $globalBytes = (int) $part->start_byte + $received;
                $part->update([
                    'downloaded_bytes' => $received,
                    'status' => 'retrying',
                    'last_error' => $error->getMessage(),
                ]);
                $session->update([
                    'status' => 'retrying',
                    'bytes_downloaded' => $globalBytes,
                    'consecutive_failures' => (int) $session->consecutive_failures + 1,
                    'last_error' => $error->getMessage(),
                    'last_heartbeat_at' => now(),
                ]);
                $progress((int) $probe->expectedSize, $globalBytes);

                Log::warning('Remote range attempt interrupted; retaining partial bytes', [
                    'source_id' => $session->media_source_id,
                    'range' => $requestStart.'-'.$part->end_byte,
                    'part_bytes_retained' => $received,
                    'attempt' => $attemptsThisRun,
                    'error' => $error->getMessage(),
                ]);

                if ($attemptsThisRun >= $maxAttempts) {
                    throw new \RuntimeException(
                        "Remote range {$part->start_byte}-{$part->end_byte} failed after {$attemptsThisRun} attempts; {$received} bytes retained.",
                        0,
                        $error
                    );
                }

                usleep(max(0, (int) config('cdn.remote_fetch_retry_wait_ms', 1000)) * 1000);
            }
        }

        $this->appendCompletedPart($chunkPath, $destination, (int) $part->start_byte, $expectedBytes);
        $globalBytes = (int) $part->end_byte + 1;
        $part->update([
            'downloaded_bytes' => $expectedBytes,
            'status' => 'completed',
            'checksum' => hash_file('sha256', $chunkPath),
            'last_error' => null,
            'completed_at' => now(),
        ]);
        @unlink($chunkPath);
        $session->update([
            'status' => 'fetching',
            'bytes_downloaded' => $globalBytes,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_heartbeat_at' => now(),
        ]);
        $progress((int) $probe->expectedSize, $globalBytes);
    }

    private function requestRangeToFile(RemoteFetchProbe $probe, int $start, int $end, string $chunkPath): void
    {
        $handle = fopen($chunkPath, 'ab');
        if (! is_resource($handle)) {
            throw new \RuntimeException('Could not open the remote range checkpoint file.');
        }

        $validatedHeaders = false;
        $before = is_file($chunkPath) ? (int) filesize($chunkPath) : 0;
        $ifRange = $this->strongValidator($probe);
        $headers = ['Range' => "bytes={$start}-{$end}"];
        if ($ifRange !== null) {
            $headers['If-Range'] = $ifRange;
        }

        try {
            $response = $this->request($probe->finalUrl, (int) config('cdn.remote_fetch_chunk_timeout', 90))
                ->withHeaders($headers)
                ->withOptions(array_merge($this->requestOptions(), [
                    'sink' => $handle,
                    'on_headers' => function (ResponseInterface $response) use ($start, $end, $probe, &$validatedHeaders): void {
                        $this->validateRangeResponse(
                            $response->getStatusCode(),
                            $response->getHeaderLine('Content-Range'),
                            $start,
                            $end,
                            (int) $probe->expectedSize
                        );
                        $validatedHeaders = true;
                    },
                ]))
                ->get($probe->finalUrl);

            if (! $validatedHeaders) {
                $this->validateRangeResponse(
                    $response->status(),
                    $response->header('Content-Range'),
                    $start,
                    $end,
                    (int) $probe->expectedSize
                );
                $validatedHeaders = true;
            }

            clearstatcache(true, $chunkPath);
            $after = is_file($chunkPath) ? (int) filesize($chunkPath) : 0;
            if ($after === $before) {
                $body = $response->body();
                if ($body !== '') {
                    fwrite($handle, $body);
                }
            }
        } catch (\Throwable $error) {
            if (! $validatedHeaders) {
                fclose($handle);
                @unlink($chunkPath);
                throw $error;
            }

            fclose($handle);
            throw $error;
        }

        fclose($handle);
        clearstatcache(true, $chunkPath);
        $actual = (int) filesize($chunkPath) - $before;
        $expected = ($end - $start) + 1;
        if ($actual !== $expected) {
            throw new \RuntimeException("Remote range returned {$actual} bytes; expected {$expected}.");
        }
    }

    private function validateRangeResponse(int $status, ?string $contentRange, int $start, int $end, int $total): void
    {
        if ($status === 200) {
            throw new \RuntimeException('Remote server ignored Range and returned 200; refusing to append a complete response.');
        }

        $parsed = self::parseContentRange($contentRange);
        if ($status !== 206 || $parsed === null) {
            throw new \RuntimeException("Remote range request failed with HTTP {$status} or invalid Content-Range.");
        }

        if ($parsed['start'] !== $start || $parsed['end'] !== $end || $parsed['total'] !== $total) {
            throw new \RuntimeException(
                "Remote Content-Range mismatch: requested {$start}-{$end}/{$total}, received {$parsed['start']}-{$parsed['end']}/{$parsed['total']}."
            );
        }
    }

    private function appendCompletedPart(string $chunkPath, string $destination, int $expectedOffset, int $expectedBytes): void
    {
        clearstatcache(true, $chunkPath);
        if (! is_file($chunkPath) || (int) filesize($chunkPath) !== $expectedBytes) {
            throw new \RuntimeException('Remote range checkpoint is incomplete and cannot be assembled.');
        }

        $output = fopen($destination, 'c+b');
        $input = fopen($chunkPath, 'rb');
        if (! is_resource($output) || ! is_resource($input)) {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            throw new \RuntimeException('Could not assemble the remote range checkpoint.');
        }

        try {
            if (! flock($output, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the resumable destination file.');
            }
            fseek($output, 0, SEEK_END);
            $offset = ftell($output);
            if ($offset !== $expectedOffset) {
                throw new \RuntimeException("Resumable destination offset changed: expected {$expectedOffset}, found {$offset}.");
            }
            $written = stream_copy_to_stream($input, $output);
            fflush($output);
            flock($output, LOCK_UN);
            if ($written !== $expectedBytes) {
                throw new \RuntimeException("Could not append the complete range checkpoint ({$written}/{$expectedBytes} bytes). ");
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function downloadWholeFile(RemoteFetchSession $session, RemoteFetchProbe $probe, string $destination, callable $progress): void
    {
        $temporary = $destination.'.whole';
        @unlink($temporary);
        $response = $this->request($probe->finalUrl, (int) config('nbx.ssrf.timeout', 7200))
            ->withOptions(array_merge($this->requestOptions(), [
                'sink' => $temporary,
                'progress' => function (int $total, int $downloaded) use ($session, $probe, $progress): void {
                    $expected = $total > 0 ? $total : (int) ($probe->expectedSize ?? 0);
                    $session->update([
                        'status' => 'fetching',
                        'bytes_downloaded' => $downloaded,
                        'last_heartbeat_at' => now(),
                    ]);
                    $progress($expected, $downloaded);
                },
            ]))
            ->get($probe->finalUrl);
        $response->throw();

        if (! is_file($temporary) && $response->body() !== '') {
            file_put_contents($temporary, $response->body());
        }
        $size = is_file($temporary) ? (int) filesize($temporary) : 0;
        if ($probe->expectedSize !== null && $size !== $probe->expectedSize) {
            throw new \RuntimeException("Complete remote response has {$size} bytes; expected {$probe->expectedSize}.");
        }
        if ($size <= 0 || ! rename($temporary, $destination)) {
            throw new \RuntimeException('Complete remote response could not be committed.');
        }

        $session->update([
            'status' => 'completed',
            'bytes_downloaded' => $size,
            'last_error' => null,
            'last_heartbeat_at' => now(),
            'completed_at' => now(),
        ]);
        $progress($size, $size);
    }

    private function prepareSession(MediaSource $source, RemoteFetchProbe $probe, string $destination): RemoteFetchSession
    {
        $session = RemoteFetchSession::query()->where('media_source_id', $source->id)->first();
        $changed = $session && (
            $session->original_url !== $probe->originalUrl
            || ($session->expected_size !== null && $probe->expectedSize !== null && (int) $session->expected_size !== $probe->expectedSize)
            || ($session->etag !== null && $probe->etag !== null && $session->etag !== $probe->etag)
            || ($session->last_modified !== null && $probe->lastModified !== null && $session->last_modified !== $probe->lastModified)
        );

        if (! $session) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            $session = RemoteFetchSession::query()->create([
                'media_source_id' => $source->id,
                'original_url' => $probe->originalUrl,
            ]);
        } elseif ($changed) {
            $this->resetSession($session, $destination);
        }

        $bytes = is_file($destination) ? (int) filesize($destination) : 0;
        $session->update([
            'original_url' => $probe->originalUrl,
            'final_url' => $probe->finalUrl,
            'expected_size' => $probe->expectedSize,
            'etag' => $probe->etag,
            'last_modified' => $probe->lastModified,
            'strategy' => $probe->supportsRanges ? 'ranged' : 'whole',
            'status' => 'fetching',
            'bytes_downloaded' => $bytes,
            'last_error' => null,
            'last_heartbeat_at' => now(),
            'completed_at' => null,
        ]);

        return $session->fresh();
    }

    private function resetSession(RemoteFetchSession $session, string $destination): void
    {
        foreach (glob($destination.'.range-*') ?: [] as $chunk) {
            if (is_file($chunk)) {
                @unlink($chunk);
            }
        }
        if (is_file($destination)) {
            @unlink($destination);
        }
        $session->parts()->delete();
        $session->update([
            'bytes_downloaded' => 0,
            'attempts' => 0,
            'consecutive_failures' => 0,
            'last_error' => null,
            'completed_at' => null,
        ]);
    }

    private function request(string $url, int $timeout): PendingRequest
    {
        return Http::connectTimeout((int) config('nbx.ssrf.connect_timeout', 30))
            ->timeout(max(1, $timeout))
            ->withHeaders($this->browserHeaders($url));
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        return [
            'cookies' => $this->cookies,
            // Several PHP download scripts behind Cloudflare terminate HTTP/2
            // streams early but serve the exact same range correctly over 1.1.
            'version' => 1.1,
            'allow_redirects' => [
                'max' => max(0, (int) config('nbx.ssrf.max_redirects', 5)),
                'strict' => true,
                'on_redirect' => static function ($request, $response, $uri): void {
                    SafeRemoteMediaUrl::assertAllowed((string) $uri);
                },
            ],
        ];
    }

    /** @return array<string, string> */
    private function browserHeaders(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return [
            'User-Agent' => (string) config('cdn.remote_fetch_user_agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36'),
            'Accept' => '*/*',
            'Accept-Encoding' => 'identity',
            'Referer' => $scheme.'://'.$host.$port.'/',
        ];
    }

    private function strongValidator(RemoteFetchProbe $probe): ?string
    {
        if ($probe->etag !== null && ! str_starts_with(trim($probe->etag), 'W/')) {
            return $probe->etag;
        }

        return $probe->lastModified;
    }

    private function effectiveUrl(Response $response, string $fallback): string
    {
        $stats = $response->handlerStats();
        $url = is_array($stats) ? ($stats['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : $fallback;
    }

    private function closeResponseBody(Response $response): void
    {
        try {
            $response->toPsrResponse()->getBody()->close();
        } catch (\Throwable) {
            // The probe headers are already captured; closing is best effort.
        }
    }

    private function numericHeader(?string $value): ?int
    {
        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    private function normalizedContentType(?string $value): ?string
    {
        $value = $this->headerOrNull($value);

        return $value === null ? null : strtolower(trim(explode(';', $value)[0]));
    }

    private function headerOrNull(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

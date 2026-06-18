<?php

namespace App\Http\Controllers;

use App\Models\MediaSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStreamController extends Controller
{
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
        'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
        'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
    ];

    private function notFoundWithCors(): Response
    {
        return response('', 404, self::CORS_HEADERS);
    }

    public function hlsRoot(string $asset, int $source, string $file)
    {
        return $this->hlsResolve($asset, $source, $file);
    }

    public function hls(string $asset, int $source, string $variant, string $file)
    {
        if (str_contains($variant, '..') || str_contains($file, '..')) {
            return $this->notFoundWithCors();
        }

        return $this->hlsPath($asset, $source, trim($variant . '/' . $file, '/'));
    }

    public function hlsPath(string $asset, int $source, string $path)
    {
        if ($path === '' || str_contains($path, '..')) {
            return $this->notFoundWithCors();
        }

        return $this->hlsResolve($asset, $source, $path);
    }

    private function hlsResolve(string $asset, int $source, string $path): Response
    {
        $mediaSource = $this->resolveReadySource($asset, $source);

        if (! $mediaSource || ! $mediaSource->hls_master_path) {
            return $this->notFoundWithCors();
        }

        $disk = $mediaSource->storage_disk ?: (string) config('cdn.disk', 'public');
        $hlsBase = dirname((string) $mediaSource->hls_master_path);
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        if ($normalizedPath === '' || str_contains($normalizedPath, '..')) {
            return $this->notFoundWithCors();
        }

        $candidatePath = $hlsBase . '/' . $normalizedPath;
        if (! Storage::disk($disk)->exists($candidatePath)) {
            return $this->notFoundWithCors();
        }

        $absolutePath = Storage::disk($disk)->path($candidatePath);
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };

        return response()->file($absolutePath, array_merge([
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=60',
        ], self::CORS_HEADERS));
    }

    public function stream(Request $request, string $asset, int $source, ?string $filename = null): StreamedResponse
    {
        $mediaSource = $this->resolveReadySource($asset, $source);

        if (! $mediaSource) {
            abort(404);
        }

        if (! in_array($mediaSource->source_type, ['upload', 'remote_fetch'], true) || ! $mediaSource->storage_path) {
            abort(404);
        }

        $disk = $mediaSource->storage_disk ?: (string) config('cdn.disk', 'public');
        $isDownload = $request->boolean('download');
        $targetPath = $isDownload
            ? $mediaSource->storage_path
            : (($mediaSource->optimized_path && Storage::disk($disk)->exists($mediaSource->optimized_path))
                ? $mediaSource->optimized_path
                : $mediaSource->storage_path);

        if (! Storage::disk($disk)->exists($targetPath)) {
            abort(404);
        }

        $absolutePath = Storage::disk($disk)->path($targetPath);
        $size = filesize($absolutePath);
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            default => ($mediaSource->mime_type ?: (mime_content_type($absolutePath) ?: 'application/octet-stream')),
        };
        $range = $request->header('Range');
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $basename = basename($absolutePath);

        if (! $range) {
            return response()->stream(function () use ($absolutePath): void {
                $stream = fopen($absolutePath, 'rb');
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                fclose($stream);
            }, 200, [
                'Content-Type' => $mime,
                'Content-Length' => (string) $size,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
                'Content-Disposition' => $disposition . '; filename="' . $basename . '"',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
                'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
                'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
            ]);
        }

        [$start, $end] = $this->parseRangeHeader($range, $size);
        $length = $end - $start + 1;

        return response()->stream(function () use ($absolutePath, $start, $end): void {
            $stream = fopen($absolutePath, 'rb');
            fseek($stream, $start);
            $remaining = $end - $start + 1;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(8192, $remaining));
                $chunkLength = strlen($chunk);
                if ($chunkLength === 0) {
                    break;
                }
                $remaining -= $chunkLength;
                echo $chunk;
                flush();
            }

            fclose($stream);
        }, 206, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
            'Cache-Control' => 'public, max-age=3600',
            'Content-Disposition' => $disposition . '; filename="' . $basename . '"',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Range,Content-Type,Accept,Origin,Authorization',
            'Access-Control-Allow-Methods' => 'GET,HEAD,OPTIONS',
            'Access-Control-Expose-Headers' => 'Content-Length,Content-Range,Accept-Ranges',
        ]);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseRangeHeader(string $rangeHeader, int $fileSize): array
    {
        if (! preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
            return [0, max(0, $fileSize - 1)];
        }

        $start = $matches[1] !== '' ? (int) $matches[1] : 0;
        $end = $matches[2] !== '' ? (int) $matches[2] : max(0, $fileSize - 1);

        $start = max(0, min($start, max(0, $fileSize - 1)));
        $end = max($start, min($end, max(0, $fileSize - 1)));

        return [$start, $end];
    }

    private function resolveReadySource(string $asset, int $source): ?MediaSource
    {
        $cacheKey = "media-stream:{$asset}:{$source}";

        return Cache::remember($cacheKey, now()->addSeconds(30), static function () use ($asset, $source): ?MediaSource {
            return MediaSource::query()
                ->select([
                    'id',
                    'media_asset_id',
                    'source_type',
                    'status',
                    'is_active',
                    'storage_disk',
                    'storage_path',
                    'optimized_path',
                    'mime_type',
                    'hls_master_path',
                ])
                ->whereKey($source)
                ->where('media_asset_id', $asset)
                ->where('is_active', true)
                ->where('status', 'ready')
                ->first();
        });
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class IngestController extends Controller
{
    public function assetSourceUpload(Request $request, MediaSourceService $mediaSourceService): JsonResponse
    {
        $validated = $request->validate([
            'source_id' => ['required', 'integer'],
            'asset_id' => ['required', 'uuid'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:100'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file'],
        ]);

        if (! $this->isSignatureValid($request, $validated)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Invalid ingest signature.',
            ], 401);
        }

        $source = MediaSource::with('asset')
            ->where('id', (int) $validated['source_id'])
            ->where('media_asset_id', (string) $validated['asset_id'])
            ->where('source_type', 'remote_fetch')
            ->first();

        if (! $source) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Source not found for ingest upload.',
            ], 404);
        }

        $disk = $mediaSourceService->storageDisk();
        $safeFilename = $this->sanitizeFilename((string) $validated['filename']) ?? sprintf('source-%d.mp4', $source->id);
        $storagePath = sprintf('media/%s/%d/%s', $source->media_asset_id, $source->id, $safeFilename);

        try {
            $source->update([
                'status' => 'uploading',
                'failure_reason' => null,
                'last_error' => null,
                'storage_disk' => $disk,
                'progress_percent' => max(1, (int) ($source->progress_percent ?? 0)),
                'last_progress_at' => now(),
            ]);

            Storage::disk($disk)->makeDirectory(dirname($storagePath));
            Storage::disk($disk)->putFileAs(dirname($storagePath), $request->file('file'), basename($storagePath));

            $absolutePath = Storage::disk($disk)->path($storagePath);
            $size = (int) (Storage::disk($disk)->size($storagePath) ?: 0);
            $mimeType = @mime_content_type($absolutePath) ?: ((string) ($validated['mime_type'] ?? '') ?: 'application/octet-stream');

            $source->update([
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size_bytes' => $size > 0 ? $size : null,
                'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
                'status' => 'ready',
                'progress_percent' => 100,
                'bytes_downloaded' => $size > 0 ? $size : null,
                'bytes_total' => $size > 0 ? $size : ($validated['size_bytes'] ?? null),
                'failure_reason' => null,
                'last_error' => null,
                'last_progress_at' => now(),
                'completed_at' => now(),
            ]);
            $mediaSourceService->queuePlaybackProcessing($source->fresh());
            $mediaSourceService->refreshAssetStatus($source->asset);

            return response()->json([
                'success' => true,
                'data' => [
                    'source_id' => $source->id,
                    'asset_id' => $source->media_asset_id,
                    'status' => $source->status,
                ],
                'error' => null,
            ], 201);
        } catch (\Throwable $throwable) {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Portal ingest upload failed.',
                'last_error' => $throwable->getMessage(),
                'completed_at' => now(),
            ]);
            $mediaSourceService->refreshAssetStatus($source->asset);

            return response()->json([
                'success' => false,
                'data' => null,
                'error' => $throwable->getMessage(),
            ], 500);
        }
    }

    private function isSignatureValid(Request $request, array $validated): bool
    {
        $secret = (string) config('cdn.ingest_secret', '');
        if ($secret === '') {
            return false;
        }

        $timestamp = (string) $request->header('X-Ingest-Timestamp', '');
        $nonce = (string) $request->header('X-Ingest-Nonce', '');
        $signature = (string) $request->header('X-Ingest-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > 300) {
            return false;
        }

        $nonceKey = 'ingest_nonce:' . hash('sha256', $timestamp . ':' . $nonce);
        if (Cache::has($nonceKey)) {
            return false;
        }

        $canonical = implode('|', [
            $timestamp,
            $nonce,
            (string) $validated['source_id'],
            (string) $validated['asset_id'],
            (string) $validated['filename'],
            (string) ($validated['size_bytes'] ?? ''),
            (string) ($validated['mime_type'] ?? ''),
        ]);

        $expected = hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        Cache::put($nonceKey, true, now()->addMinutes(10));

        return true;
    }

    private function sanitizeFilename(?string $filename): ?string
    {
        if (! is_string($filename) || trim($filename) === '') {
            return null;
        }

        $decoded = urldecode($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $decoded) ?: '';
        $clean = ltrim($clean, '.');

        if ($clean === '') {
            return null;
        }

        $base = (string) pathinfo($clean, PATHINFO_FILENAME);
        $extension = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        $suffix = 'naraboxtv_com';

        $base = preg_replace('/(?:[_.-]+)?mobifliks(?:[_.-]*com)?$/i', '', $base) ?: $base;
        $base = preg_replace('/(?:[_.-]+)?naraboxtv(?:[_.-]*com)?$/i', '', $base) ?: $base;
        $base = rtrim($base, '_-');
        if ($base === '') {
            $base = 'source';
        }

        return $extension !== ''
            ? ($base . '_' . $suffix . '.' . $extension)
            : ($base . '_' . $suffix);
    }
}


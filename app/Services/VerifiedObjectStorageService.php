<?php

namespace App\Services;

use Aws\S3\Exception\S3MultipartUploadException;
use Aws\S3\MultipartUploader;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

class VerifiedObjectStorageService
{
    public function verify(string $disk, string $path, int $expectedBytes): bool
    {
        if ($path === '' || $expectedBytes <= 0) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path)
                && (int) Storage::disk($disk)->size($path) === $expectedBytes;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{bytes:int,content_type:string,etag:?string,multipart:bool}
     */
    public function copy(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): array
    {
        $expectedBytes = (int) Storage::disk($sourceDisk)->size($sourcePath);
        if ($expectedBytes <= 0) {
            throw new \RuntimeException("Source artifact {$sourcePath} is empty.");
        }

        $contentType = $this->contentType($targetPath);
        $existing = $this->head($targetDisk, $targetPath);
        if (($existing['bytes'] ?? 0) === $expectedBytes) {
            return [
                'bytes' => $expectedBytes,
                'content_type' => $contentType,
                'etag' => $existing['etag'] ?? null,
                'multipart' => (bool) ($existing['multipart'] ?? false),
            ];
        }

        $multipartThreshold = max(
            5 * 1024 * 1024,
            (int) config('nbx.multipart_threshold_mb', 64) * 1024 * 1024,
        );

        if ($expectedBytes >= $multipartThreshold
            && $this->isLocalDisk($sourceDisk)
            && $this->isS3Disk($targetDisk)
        ) {
            $this->multipartUpload(
                Storage::disk($sourceDisk)->path($sourcePath),
                $targetDisk,
                $targetPath,
                $contentType,
            );
            $multipart = true;
        } else {
            $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
            if (! is_resource($stream)) {
                throw new \RuntimeException("Could not open {$sourcePath} from {$sourceDisk}.");
            }

            try {
                $stored = Storage::disk($targetDisk)->put($targetPath, $stream, [
                    'visibility' => 'public',
                    'ContentType' => $contentType,
                ]);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new \RuntimeException("Could not store {$targetPath} on {$targetDisk}.");
            }
            $multipart = false;
        }

        $verified = $this->head($targetDisk, $targetPath);
        if (($verified['bytes'] ?? 0) !== $expectedBytes) {
            throw new \RuntimeException(sprintf(
                'Stored object verification failed for %s: expected %d bytes, found %d.',
                $targetPath,
                $expectedBytes,
                (int) ($verified['bytes'] ?? 0),
            ));
        }

        return [
            'bytes' => $expectedBytes,
            'content_type' => $contentType,
            'etag' => $verified['etag'] ?? null,
            'multipart' => $multipart,
        ];
    }

    /**
     * @return array{bytes:int,etag:?string,multipart:bool}|null
     */
    public function head(string $disk, string $path): ?array
    {
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $bytes = (int) Storage::disk($disk)->size($path);
            $etag = null;
            if ($this->isS3Disk($disk)) {
                $client = Storage::disk($disk)->getClient();
                $result = $client->headObject([
                    'Bucket' => (string) config("filesystems.disks.{$disk}.bucket"),
                    'Key' => $path,
                ]);
                $bytes = (int) ($result['ContentLength'] ?? $bytes);
                $etag = isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null;
            }

            return [
                'bytes' => $bytes,
                'etag' => $etag,
                'multipart' => is_string($etag) && str_contains($etag, '-'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function multipartUpload(string $absoluteSource, string $targetDisk, string $targetPath, string $contentType): void
    {
        $client = Storage::disk($targetDisk)->getClient();
        $bucket = (string) config("filesystems.disks.{$targetDisk}.bucket");
        $partSize = max(
            MultipartUploader::PART_MIN_SIZE,
            (int) config('nbx.multipart_part_size_mb', 32) * 1024 * 1024,
        );

        try {
            (new MultipartUploader($client, $absoluteSource, [
                'bucket' => $bucket,
                'key' => $targetPath,
                'part_size' => $partSize,
                'concurrency' => max(1, (int) config('nbx.multipart_concurrency', 2)),
                'params' => [
                    'ContentType' => $contentType,
                ],
            ]))->upload();
        } catch (S3MultipartUploadException $exception) {
            $state = $exception->getState();
            $id = $state->getId();
            if (isset($id['UploadId'])) {
                try {
                    $client->abortMultipartUpload([
                        'Bucket' => $id['Bucket'] ?? $bucket,
                        'Key' => $id['Key'] ?? $targetPath,
                        'UploadId' => $id['UploadId'],
                    ]);
                } catch (\Throwable) {
                    // The upload may already have completed; the verification step decides.
                }
            }

            throw $exception;
        }
    }

    private function isLocalDisk(string $disk): bool
    {
        return (string) config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function isS3Disk(string $disk): bool
    {
        return Storage::disk($disk) instanceof AwsS3V3Adapter;
    }

    private function contentType(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };
    }
}

<?php

namespace App\Services;

use Aws\S3\Exception\S3MultipartUploadException;
use Aws\S3\MultipartUploader;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
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
        $sourceStream = $this->openStableSourceStream($sourceDisk, $sourcePath);

        try {
            $stat = fstat($sourceStream);
            $expectedBytes = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
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
            $useMultipart = $expectedBytes >= $multipartThreshold && $this->isS3Disk($targetDisk);

            [$verified, $multipart] = $this->uploadWithRetry(
                $sourceStream,
                $targetDisk,
                $targetPath,
                $contentType,
                $expectedBytes,
                $useMultipart,
            );

            return [
                'bytes' => $expectedBytes,
                'content_type' => $contentType,
                'etag' => $verified['etag'] ?? null,
                'multipart' => $multipart,
            ];
        } finally {
            fclose($sourceStream);
        }
    }

    /**
     * Retry the upload+verify attempt with exponential backoff and jitter.
     * Individual transient failures (network blips, S3 5xx) no longer fail
     * the whole publish attempt outright.
     *
     * @param  resource  $sourceStream
     * @return array{0: array{bytes:int,etag:?string,multipart:bool}, 1: bool}
     */
    private function uploadWithRetry(
        $sourceStream,
        string $targetDisk,
        string $targetPath,
        string $contentType,
        int $expectedBytes,
        bool $useMultipart,
    ): array {
        $maxAttempts = max(1, (int) config('nbx.multipart_max_attempts', 4));
        $baseMs = max(100, (int) config('nbx.multipart_retry_base_ms', 1000));
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                if (@rewind($sourceStream) === false) {
                    // The source stream can't be re-read from the start (e.g. a
                    // non-seekable remote readStream); retrying would silently
                    // upload a truncated/corrupt object, which is worse than
                    // failing loudly.
                    throw new \RuntimeException(
                        "Cannot retry the upload of {$targetPath}: the source stream is not rewindable.",
                        0,
                        $lastException,
                    );
                }

                $delayMs = (int) ($baseMs * (2 ** ($attempt - 2)));
                $delayMs += random_int(0, (int) ($delayMs * 0.25) + 1);
                usleep($delayMs * 1000);

                Log::info('verified_object_storage.upload_retry', [
                    'target_disk' => $targetDisk,
                    'target_path' => $targetPath,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);
            } else {
                @rewind($sourceStream);
            }

            try {
                if ($useMultipart) {
                    $this->multipartUpload($sourceStream, $targetDisk, $targetPath, $contentType);
                    $multipart = true;
                } else {
                    $stored = Storage::disk($targetDisk)->put(
                        $targetPath,
                        $sourceStream,
                        $this->putOptionsForDisk($targetDisk, $contentType),
                    );

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

                return [$verified, $multipart];
            } catch (\Throwable $exception) {
                $lastException = $exception;
                Log::warning('verified_object_storage.upload_attempt_failed', [
                    'target_disk' => $targetDisk,
                    'target_path' => $targetPath,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            "Final storage upload of {$targetPath} failed after {$maxAttempts} attempts: ".$lastException?->getMessage(),
            0,
            $lastException,
        );
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

    /**
     * @param  resource  $sourceStream
     */
    private function multipartUpload($sourceStream, string $targetDisk, string $targetPath, string $contentType): void
    {
        $client = Storage::disk($targetDisk)->getClient();
        $bucket = (string) config("filesystems.disks.{$targetDisk}.bucket");
        $partSize = max(
            MultipartUploader::PART_MIN_SIZE,
            (int) config('nbx.multipart_part_size_mb', 32) * 1024 * 1024,
        );

        try {
            (new MultipartUploader($client, $sourceStream, [
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

    /**
     * R2 public delivery is controlled by the bucket/custom domain, not by
     * per-object ACLs. Avoid sending public-read ACL options to R2 endpoints.
     */
    private function putOptionsForDisk(string $disk, string $contentType): array
    {
        $endpoint = strtolower((string) config("filesystems.disks.{$disk}.endpoint", ''));
        $provider = strtolower((string) config("filesystems.disks.{$disk}.provider", ''));
        $isR2 = $disk === 'r2'
            || str_contains($endpoint, '.r2.cloudflarestorage.com')
            || str_contains($provider, 'r2');

        $options = ['ContentType' => $contentType];

        if (! $isR2) {
            $options['visibility'] = 'public';
        }

        return $options;
    }

    /**
     * Keep one file descriptor alive for the complete upload. On Unix the
     * descriptor remains readable even if a legacy cleanup unlinks the path,
     * eliminating the exists()/fopen() race seen on large multipart uploads.
     *
     * @return resource
     */
    private function openStableSourceStream(string $sourceDisk, string $sourcePath)
    {
        $stream = $this->isLocalDisk($sourceDisk)
            ? @fopen(Storage::disk($sourceDisk)->path($sourcePath), 'rb')
            : Storage::disk($sourceDisk)->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new \RuntimeException(
                "Expected output artifact {$sourcePath} is missing or unreadable; final storage upload was not started."
            );
        }

        return $stream;
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

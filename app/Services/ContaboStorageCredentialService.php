<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContaboStorageCredentialService
{
    private ?string $lastError = null;

    /**
     * The Contabo control-plane API credential-discovery flow only knows
     * how to resolve keys for ONE object-storage account (services.contabo_api
     * .object_storage_id). It must never be used to backfill credentials for
     * a DIFFERENT storage target/disk — the two Contabo services are not
     * assumed to share access keys. Discovery therefore only applies to the
     * legacy disk; every other target must set its own *_ACCESS_KEY_ID /
     * *_SECRET_ACCESS_KEY env vars directly.
     */
    private const DISCOVERABLE_DISKS = ['contabo', 'contabo_nbx'];

    public function ensureRuntimeDiskCredentials(string $disk = 'contabo'): bool
    {
        $diskConfig = config('filesystems.disks.'.$disk, []);
        if (filled($diskConfig['key'] ?? null) && filled($diskConfig['secret'] ?? null)) {
            return true;
        }

        if (! in_array($disk, self::DISCOVERABLE_DISKS, true)) {
            $this->lastError = "Storage disk [{$disk}] is missing S3 access credentials in the active .env. This target does not support Contabo API credential auto-discovery.";

            return false;
        }

        if (! app(ContaboApiClientService::class)->isConfigured()) {
            $this->lastError = 'Contabo S3 keys are blank and Contabo API credentials are not configured.';

            return false;
        }

        $credentials = app(ContaboApiClientService::class)->getS3Credentials();
        if (! ($credentials['ok'] ?? false) || ! is_array($credentials['data'] ?? null)) {
            $this->lastError = (string) ($credentials['error'] ?? 'Contabo S3 credential discovery failed.');
            Log::warning('Contabo S3 credential discovery failed', ['error' => $this->lastError, 'disk' => $disk]);

            return false;
        }

        config([
            'filesystems.disks.'.$disk.'.key' => (string) $credentials['data']['accessKey'],
            'filesystems.disks.'.$disk.'.secret' => (string) $credentials['data']['secretKey'],
        ]);

        Storage::forgetDisk($disk);
        $this->lastError = null;

        return true;
    }

    public function configurationError(string $disk = 'contabo'): string
    {
        $diskConfig = config('filesystems.disks.'.$disk, []);
        $missing = [];

        foreach (['bucket', 'endpoint'] as $key) {
            if (! filled($diskConfig[$key] ?? null)) {
                $missing[] = strtoupper($key);
            }
        }

        if ($missing !== []) {
            return "Storage disk [{$disk}] is missing required values: ".implode(', ', $missing).'.';
        }

        return $this->lastError ?: "Storage disk [{$disk}] is missing S3 credentials. Set its *_ACCESS_KEY_ID/*_SECRET_ACCESS_KEY env vars".(in_array($disk, self::DISCOVERABLE_DISKS, true) ? ', or configure valid CONTABO_API_* credentials.' : '.');
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContaboStorageCredentialService
{
    private ?string $lastError = null;

    public function ensureRuntimeDiskCredentials(): bool
    {
        $disk = config('filesystems.disks.contabo', []);
        if (filled($disk['key'] ?? null) && filled($disk['secret'] ?? null)) {
            return true;
        }

        if (! app(ContaboApiClientService::class)->isConfigured()) {
            $this->lastError = 'Contabo S3 keys are blank and Contabo API credentials are not configured.';
            return false;
        }

        $credentials = app(ContaboApiClientService::class)->getS3Credentials();
        if (! ($credentials['ok'] ?? false) || ! is_array($credentials['data'] ?? null)) {
            $this->lastError = (string) ($credentials['error'] ?? 'Contabo S3 credential discovery failed.');
            Log::warning('Contabo S3 credential discovery failed', ['error' => $this->lastError]);
            return false;
        }

        config([
            'filesystems.disks.contabo.key' => (string) $credentials['data']['accessKey'],
            'filesystems.disks.contabo.secret' => (string) $credentials['data']['secretKey'],
        ]);

        Storage::forgetDisk('contabo');
        $this->lastError = null;

        return true;
    }

    public function configurationError(): string
    {
        $disk = config('filesystems.disks.contabo', []);
        $missing = [];

        foreach (['bucket', 'endpoint'] as $key) {
            if (! filled($disk[$key] ?? null)) {
                $missing[] = strtoupper($key);
            }
        }

        if ($missing !== []) {
            return 'Contabo disk is missing required values: ' . implode(', ', $missing) . '.';
        }

        return $this->lastError ?: 'Contabo disk is missing S3 credentials. Set CONTABO_OBJECT_STORAGE_ACCESS_KEY/SECRET_KEY or valid CONTABO_API_* credentials.';
    }
}

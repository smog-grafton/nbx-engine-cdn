<?php

namespace App\Services\Storage;

/**
 * Immutable, non-secret description of one logical storage target (bucket).
 * Access keys / secret keys are never carried on this object — they stay in
 * the resolved Laravel filesystem disk config. Portal has an equivalent
 * class; the two only need to agree on logical keys, never on credentials.
 */
final class StorageTarget
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $provider,
        public readonly string $disk,
        public readonly string $endpoint,
        public readonly string $region,
        public readonly string $bucket,
        public readonly string $publicUrl,
        public readonly string $pathPrefix,
        public readonly bool $enabled,
        public readonly bool $writable,
        public readonly int $priority,
        public readonly int $capacityBytes,
        public readonly float $reservePercent,
        public readonly int $knownUsedBytes,
        public readonly ?string $knownUsedAt,
    ) {
    }

    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: (string) ($config['label'] ?? $key),
            provider: (string) ($config['provider'] ?? 'contabo'),
            disk: (string) ($config['disk'] ?? $key),
            endpoint: rtrim((string) ($config['endpoint'] ?? ''), '/'),
            region: (string) ($config['region'] ?? ''),
            bucket: (string) ($config['bucket'] ?? ''),
            publicUrl: rtrim((string) ($config['public_url'] ?? ''), '/'),
            pathPrefix: trim((string) ($config['path_prefix'] ?? 'videos'), '/'),
            enabled: (bool) ($config['enabled'] ?? false),
            writable: (bool) ($config['writable'] ?? true),
            priority: (int) ($config['priority'] ?? 100),
            capacityBytes: (int) ($config['capacity_bytes'] ?? 0),
            reservePercent: (float) ($config['reserve_percent'] ?? 10),
            knownUsedBytes: (int) ($config['known_used_bytes'] ?? 0),
            knownUsedAt: $config['known_used_at'] ?? null,
        );
    }

    public function reserveBytes(): int
    {
        return (int) round($this->capacityBytes * ($this->reservePercent / 100));
    }

    public function softLimitBytes(): int
    {
        return max(0, $this->capacityBytes - $this->reserveBytes());
    }

    public function usedBytes(?int $overrideUsedBytes = null): int
    {
        return $overrideUsedBytes ?? $this->knownUsedBytes;
    }

    public function remainingBytes(?int $overrideUsedBytes = null): int
    {
        return max(0, $this->capacityBytes - $this->usedBytes($overrideUsedBytes));
    }

    public function usedPercent(?int $overrideUsedBytes = null): float
    {
        if ($this->capacityBytes <= 0) {
            return 0.0;
        }

        return round(($this->usedBytes($overrideUsedBytes) / $this->capacityBytes) * 100, 2);
    }

    public function isPastSoftLimit(?int $overrideUsedBytes = null): bool
    {
        return $this->usedBytes($overrideUsedBytes) >= $this->softLimitBytes();
    }

    public function hasSafeCapacityFor(int $expectedBytes, ?int $overrideUsedBytes = null): bool
    {
        if (! $this->enabled || ! $this->writable) {
            return false;
        }

        return ($this->usedBytes($overrideUsedBytes) + $expectedBytes) <= $this->softLimitBytes();
    }

    public function toArray(?int $overrideUsedBytes = null): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'provider' => $this->provider,
            'disk' => $this->disk,
            'bucket' => $this->bucket,
            'region' => $this->region,
            'public_url' => $this->publicUrl,
            'enabled' => $this->enabled,
            'writable' => $this->writable,
            'priority' => $this->priority,
            'capacity_bytes' => $this->capacityBytes,
            'reserve_percent' => $this->reservePercent,
            'used_bytes' => $this->usedBytes($overrideUsedBytes),
            'used_percent' => $this->usedPercent($overrideUsedBytes),
            'remaining_bytes' => $this->remainingBytes($overrideUsedBytes),
            'soft_limit_bytes' => $this->softLimitBytes(),
            'past_soft_limit' => $this->isPastSoftLimit($overrideUsedBytes),
            'known_used_at' => $this->knownUsedAt,
        ];
    }
}

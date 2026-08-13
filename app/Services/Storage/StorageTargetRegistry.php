<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * Central lookup for the logical storage targets NBX knows about. Portal has
 * its own equivalent registry backed by its own config/env — the two only
 * need to agree on the logical keys, never on credentials.
 */
class StorageTargetRegistry
{
    /** @var array<string, StorageTarget>|null */
    private ?array $targets = null;

    /**
     * @return array<string, StorageTarget>
     */
    public function all(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }

        $this->targets = [];

        foreach ((array) config('storage_targets.targets', []) as $key => $config) {
            $this->targets[$key] = StorageTarget::fromConfig($key, (array) $config);
        }

        return $this->targets;
    }

    /**
     * @return array<string, StorageTarget>
     */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (StorageTarget $target) => $target->enabled);
    }

    public function find(string $key): ?StorageTarget
    {
        return $this->all()[$key] ?? null;
    }

    public function findOrFail(string $key): StorageTarget
    {
        $target = $this->find($key);

        if ($target === null) {
            throw new StorageTargetException("Unknown storage target key: [{$key}].");
        }

        return $target;
    }

    public function legacyKey(): string
    {
        return (string) config('storage_targets.legacy_target_key', 'contabo_nbx');
    }

    public function legacyTarget(): StorageTarget
    {
        return $this->findOrFail($this->legacyKey());
    }

    public function isAutoKey(?string $key): bool
    {
        return $key === null || $key === '' || $key === 'auto';
    }

    /**
     * True for any remote object-storage target (Contabo or R2), as opposed
     * to NBX's own local/public work disk. Kept under the legacy method name
     * because older call sites use it to mean "not local".
     */
    public function isContaboFamily(?string $key): bool
    {
        return ! in_array($key, [null, '', 'local', 'public'], true);
    }

    /**
     * Resolve any incoming key (including legacy literals like "contabo",
     * null, or "auto") down to a concrete stored key, WITHOUT performing
     * automatic selection. Use AutomaticStorageSelector for "auto".
     */
    public function normalizeStoredKey(?string $key): string
    {
        if ($key === null || $key === '' || $key === 'contabo') {
            return $this->legacyKey();
        }

        return $key;
    }

    public function disk(StorageTarget $target)
    {
        return Storage::disk($target->disk);
    }
}

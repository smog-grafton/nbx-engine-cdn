<?php

namespace App\Services\Storage;

/**
 * Chooses which storage target a new write should land on.
 *
 * Automatic selection considers: enabled/writable state, health, capacity,
 * reserved headroom, expected upload/output size, and target priority.
 * Manual/explicit selection is still validated against the soft-cap.
 */
class AutomaticStorageSelector
{
    public function __construct(
        private readonly StorageTargetRegistry $registry,
        private readonly StorageUsageService $usage,
    ) {
    }

    /**
     * @return array{target: StorageTarget, used_bytes: int, warning: ?string, auto: bool}
     */
    public function resolve(?string $requestedKey, int $expectedBytes, bool $privileged = false): array
    {
        if ($this->registry->isAutoKey($requestedKey)) {
            return $this->resolveAutomatic($expectedBytes);
        }

        return $this->resolveExplicit($this->registry->normalizeStoredKey($requestedKey), $expectedBytes, $privileged);
    }

    /**
     * @return array{target: StorageTarget, used_bytes: int, warning: ?string, auto: bool}
     */
    public function resolveExplicit(string $key, int $expectedBytes, bool $privileged = false): array
    {
        $target = $this->registry->findOrFail($key);

        if (! $target->enabled) {
            throw new StorageTargetException("Storage target [{$target->label}] is currently disabled.");
        }

        if (! $target->writable) {
            throw new StorageTargetException("Storage target [{$target->label}] is read-only.");
        }

        $usage = $this->usage->usageFor($target);
        $usedBytes = $usage['used_bytes'];
        $warning = null;

        if (! $target->hasSafeCapacityFor($expectedBytes, $usedBytes)) {
            $message = "Storage target [{$target->label}] does not have enough safe capacity for this write "
                .'(used '.$this->formatBytes($usedBytes).' of '.$this->formatBytes($target->capacityBytes)
                .', needs '.$this->formatBytes($expectedBytes).', reserve '.$target->reservePercent.'%).';

            if (! $privileged) {
                throw new StorageTargetException($message.' Choose another target or ask an administrator to override.');
            }

            $warning = $message.' Proceeding only because an administrator explicitly overrode the soft-cap warning.';
        } elseif ($target->isPastSoftLimit($usedBytes)) {
            $warning = "Storage target [{$target->label}] is past its configured soft limit ({$target->usedPercent($usedBytes)}% used).";
        }

        return [
            'target' => $target,
            'used_bytes' => $usedBytes,
            'warning' => $warning,
            'auto' => false,
        ];
    }

    /**
     * @return array{target: StorageTarget, used_bytes: int, warning: ?string, auto: bool}
     */
    public function resolveAutomatic(int $expectedBytes): array
    {
        $candidates = [];

        foreach ($this->registry->enabled() as $target) {
            if (! $target->writable) {
                continue;
            }

            $usage = $this->usage->usageFor($target);
            $candidates[] = [
                'target' => $target,
                'used_bytes' => $usage['used_bytes'],
                'safe' => $target->hasSafeCapacityFor($expectedBytes, $usage['used_bytes']),
            ];
        }

        if ($candidates === []) {
            throw new StorageTargetException('No storage target is enabled/writable. Fetch/processing cannot start.');
        }

        $safe = array_values(array_filter($candidates, fn (array $c) => $c['safe']));

        if ($safe === []) {
            $summary = implode('; ', array_map(
                fn (array $c) => $c['target']->label.': '.$c['target']->usedPercent($c['used_bytes']).'% used',
                $candidates
            ));

            throw new StorageTargetException(
                'No storage target has enough safe capacity for an estimated '
                .$this->formatBytes($expectedBytes)." write ({$summary}). Refusing to start an expensive fetch/transcode job."
            );
        }

        usort($safe, function (array $a, array $b) {
            if ($a['target']->priority !== $b['target']->priority) {
                return $b['target']->priority <=> $a['target']->priority;
            }

            return $b['target']->remainingBytes($b['used_bytes']) <=> $a['target']->remainingBytes($a['used_bytes']);
        });

        $winner = $safe[0];

        return [
            'target' => $winner['target'],
            'used_bytes' => $winner['used_bytes'],
            'warning' => null,
            'auto' => true,
        ];
    }

    public function estimateTranscodeOutputBytes(int $inputBytes): int
    {
        $multiplier = (float) config('storage_targets.transcode_safety_multiplier', 2.0);

        return (int) ceil($inputBytes * max(1.0, $multiplier));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusForAllTargets(): array
    {
        $rows = [];

        foreach ($this->registry->all() as $target) {
            $usage = $this->usage->usageFor($target);
            $rows[] = $target->toArray($usage['used_bytes']) + [
                'usage_source' => $usage['source'],
                'usage_stale' => $usage['stale'],
            ];
        }

        return $rows;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return round($value, 2).' '.$units[$unitIndex];
    }
}

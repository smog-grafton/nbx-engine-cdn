<?php

namespace App\Services;

use App\Models\MediaSource;
use Illuminate\Support\Facades\DB;

class OptimizationQueueInspector
{
    public function canInspect(): bool
    {
        return $this->databaseQueueConfig() !== null;
    }

    /**
     * @return array{
     *   driver:string|null,queue:string,can_inspect:bool,total:int|null,ready:int|null,delayed:int|null,reserved:int|null,
     *   retry_after:int|null,oldest_ready_seconds:int|null,oldest_delayed_seconds:int|null,oldest_reserved_seconds:int|null
     * }
     */
    public function summary(?string $queue = null): array
    {
        $queue = $queue ?: (string) config('cdn.optimization_queue', 'optimization');
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");
        $config = $this->databaseQueueConfig();
        if ($config === null) {
            return [
                'driver' => is_string($driver) ? $driver : null,
                'queue' => $queue,
                'can_inspect' => false,
                'total' => null,
                'ready' => null,
                'delayed' => null,
                'reserved' => null,
                'retry_after' => null,
                'oldest_ready_seconds' => null,
                'oldest_delayed_seconds' => null,
                'oldest_reserved_seconds' => null,
            ];
        }

        $now = time();
        $base = DB::connection($config['connection'])->table($config['table'])->where('queue', $queue);

        return [
            'driver' => 'database',
            'queue' => $queue,
            'can_inspect' => true,
            'total' => (clone $base)->count(),
            'ready' => (clone $base)->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
            'delayed' => (clone $base)->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
            'reserved' => (clone $base)->whereNotNull('reserved_at')->count(),
            'retry_after' => $config['retry_after'],
            'oldest_ready_seconds' => $this->oldestAgeSeconds((clone $base)->whereNull('reserved_at')->where('available_at', '<=', $now), 'created_at', $now),
            'oldest_delayed_seconds' => $this->oldestAgeSeconds((clone $base)->whereNull('reserved_at')->where('available_at', '>', $now), 'created_at', $now),
            'oldest_reserved_seconds' => $this->oldestAgeSeconds((clone $base)->whereNotNull('reserved_at'), 'reserved_at', $now),
        ];
    }

    /**
     * @return array<int, array{
     *   id:int,queue:string,attempts:int,state:string,display_name:?string,source_id:?int,attempt_id:?string,
     *   created_at:int,available_at:int,reserved_at:?int,age_seconds:int,available_in_seconds:int,reserved_for_seconds:?int
     * }>
     */
    public function jobsForSource(MediaSource $source, int $limit = 50): array
    {
        $config = $this->databaseQueueConfig();
        if ($config === null) {
            return [];
        }

        $queue = (string) config('cdn.optimization_queue', 'optimization');
        $attemptId = is_string($source->processing_attempt_id) && $source->processing_attempt_id !== ''
            ? $source->processing_attempt_id
            : null;

        $query = DB::connection($config['connection'])
            ->table($config['table'])
            ->where('queue', $queue)
            ->orderByDesc('id')
            ->limit(max(1, min(250, $limit)));

        if ($attemptId !== null) {
            $query->where('payload', 'like', '%'.$this->escapeLike($attemptId).'%');
        } else {
            $query->where('payload', 'like', '%sourceId%');
        }

        $sourceId = (int) $source->id;
        $jobs = [];
        foreach ($query->get() as $row) {
            $parsed = $this->parsePayload((string) $row->payload);
            if (($parsed['source_id'] ?? null) !== $sourceId) {
                continue;
            }
            if ($attemptId !== null && ($parsed['attempt_id'] ?? null) !== $attemptId) {
                continue;
            }

            $jobs[] = $this->describeRow($row, $parsed);
        }

        return $jobs;
    }

    /**
     * @return array{
     *   id:int,queue:string,attempts:int,state:string,display_name:?string,source_id:?int,attempt_id:?string,
     *   created_at:int,available_at:int,reserved_at:?int,age_seconds:int,available_in_seconds:int,reserved_for_seconds:?int
     * }|null
     */
    public function currentAttemptJob(MediaSource $source): ?array
    {
        return $this->jobsForSource($source, 10)[0] ?? null;
    }

    public function deleteJob(int $id): int
    {
        $config = $this->databaseQueueConfig();
        if ($config === null) {
            return 0;
        }

        return DB::connection($config['connection'])
            ->table($config['table'])
            ->where('id', $id)
            ->where('queue', (string) config('cdn.optimization_queue', 'optimization'))
            ->delete();
    }

    /**
     * @return array{connection:?string,table:string,retry_after:int}|null
     */
    private function databaseQueueConfig(): ?array
    {
        $connection = config('queue.default');
        $config = config("queue.connections.{$connection}");
        if (($config['driver'] ?? null) !== 'database') {
            return null;
        }

        return [
            'connection' => $config['connection'] ?? null,
            'table' => $config['table'] ?? 'jobs',
            'retry_after' => (int) ($config['retry_after'] ?? 0),
        ];
    }

    private function oldestAgeSeconds(mixed $query, string $column, int $now): ?int
    {
        $oldest = $query->min($column);
        if (! is_numeric($oldest)) {
            return null;
        }

        return max(0, $now - (int) $oldest);
    }

    /**
     * @param  object  $row
     * @param  array{display_name:?string,source_id:?int,attempt_id:?string}  $parsed
     * @return array{
     *   id:int,queue:string,attempts:int,state:string,display_name:?string,source_id:?int,attempt_id:?string,
     *   created_at:int,available_at:int,reserved_at:?int,age_seconds:int,available_in_seconds:int,reserved_for_seconds:?int
     * }
     */
    private function describeRow(object $row, array $parsed): array
    {
        $now = time();
        $reservedAt = is_numeric($row->reserved_at ?? null) ? (int) $row->reserved_at : null;
        $availableAt = (int) $row->available_at;
        $state = $reservedAt !== null
            ? 'reserved'
            : ($availableAt > $now ? 'delayed' : 'ready');

        return [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'attempts' => (int) $row->attempts,
            'state' => $state,
            'display_name' => $parsed['display_name'],
            'source_id' => $parsed['source_id'],
            'attempt_id' => $parsed['attempt_id'],
            'created_at' => (int) $row->created_at,
            'available_at' => $availableAt,
            'reserved_at' => $reservedAt,
            'age_seconds' => max(0, $now - (int) $row->created_at),
            'available_in_seconds' => max(0, $availableAt - $now),
            'reserved_for_seconds' => $reservedAt !== null ? max(0, $now - $reservedAt) : null,
        ];
    }

    /**
     * @return array{display_name:?string,source_id:?int,attempt_id:?string}
     */
    private function parsePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $displayName = is_array($decoded) && is_string($decoded['displayName'] ?? null)
            ? $decoded['displayName']
            : null;
        $command = is_array($decoded) && is_string($decoded['data']['command'] ?? null)
            ? $decoded['data']['command']
            : $payload;

        return [
            'display_name' => $displayName,
            'source_id' => $this->serializedInt($command, 'sourceId'),
            'attempt_id' => $this->serializedNullableString($command, 'attemptId'),
        ];
    }

    private function serializedInt(string $payload, string $property): ?int
    {
        if (preg_match('/s:\d+:"'.preg_quote($property, '/').'";i:(\d+);/', $payload, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function serializedNullableString(string $payload, string $property): ?string
    {
        if (preg_match('/s:\d+:"'.preg_quote($property, '/').'";s:\d+:"([^"]*)";/', $payload, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/s:\d+:"'.preg_quote($property, '/').'";N;/', $payload) === 1) {
            return null;
        }

        return null;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}

<?php

namespace App\Services;

use App\Models\MediaSource;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FfmpegProcessRunner
{
    /**
     * Sentinel exit code meaning "this attempt was superseded mid-run; the
     * process was stopped and no status was written." Never a real FFmpeg
     * exit code, so callers can distinguish it from a genuine failure.
     */
    public const SUPERSEDED_EXIT_CODE = -1;

    /**
     * @param  array<int, string>  $command
     * @return array{0:int,1:string}
     */
    public function run(array $command, MediaSource $source, float $durationSeconds, string $stage): array
    {
        $timeout = max(300, (int) config('cdn.ffmpeg_timeout_seconds', 21600));
        $heartbeatEvery = max(2, (int) config('cdn.ffmpeg_heartbeat_seconds', 10));
        // Captured once: every write for this run is guarded against this
        // exact attempt, so a superseded process (e.g. one a stale-recovery
        // pass wrongly declared dead while it kept running) can never
        // silently overwrite a fresher attempt's state. See heartbeat().
        $attemptId = $source->processing_attempt_id;
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->setIdleTimeout(max(120, $heartbeatEvery * 12));
        $commandSummary = $this->sanitizedCommand($command);
        Log::info('Starting FFmpeg media stage', [
            'source_id' => $source->id,
            'asset_id' => $source->media_asset_id,
            'attempt_id' => $source->processing_attempt_id,
            'stage' => $stage,
            'command' => $commandSummary,
        ]);
        $process->start();

        $diagnostics = "FFmpeg command: {$commandSummary}";
        $progressBuffer = '';
        $lastHeartbeat = 0.0;
        $stageProgress = 0;

        try {
            while ($process->isRunning()) {
                $chunk = $process->getIncrementalErrorOutput().$process->getIncrementalOutput();
                if ($chunk !== '') {
                    $progressBuffer .= $chunk;
                    $diagnostics = $this->boundedDiagnostics($diagnostics.$chunk);
                    [$progressBuffer, $stageProgress] = $this->readProgress(
                        $progressBuffer,
                        $durationSeconds,
                        $stageProgress,
                    );
                }

                $now = microtime(true);
                if ($now - $lastHeartbeat >= $heartbeatEvery) {
                    if (! $this->heartbeat($source, $stage, $stageProgress, $diagnostics, $attemptId)) {
                        Log::warning('FFmpeg attempt superseded by a newer attempt; stopping process without writing status', [
                            'source_id' => $source->id,
                            'asset_id' => $source->media_asset_id,
                            'attempt_id' => $attemptId,
                            'stage' => $stage,
                        ]);
                        $process->stop(5);

                        return [self::SUPERSEDED_EXIT_CODE, $diagnostics];
                    }
                    $lastHeartbeat = $now;
                }

                usleep(250_000);
                $process->checkTimeout();
            }
        } catch (ProcessTimedOutException $exception) {
            $process->stop(3);
            $diagnostics = $this->boundedDiagnostics(
                $diagnostics."\nFFmpeg timed out after {$timeout} seconds: ".$exception->getMessage()
            );
            $this->heartbeat($source, $stage, $stageProgress, $diagnostics, $attemptId);

            return [124, $diagnostics];
        }

        $tail = $process->getIncrementalErrorOutput().$process->getIncrementalOutput();
        $diagnostics = $this->boundedDiagnostics($diagnostics.$tail);
        $exitCode = $process->getExitCode() ?? 1;
        $this->heartbeat($source, $stage, $exitCode === 0 ? 100 : $stageProgress, $diagnostics, $attemptId);

        return [$exitCode, $diagnostics];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function sanitizedCommand(array $command): string
    {
        return implode(' ', array_map(static function (string $argument): string {
            $sanitized = preg_replace(
                '~(https?://)([^/@\s]+):([^/@\s]+)@~i',
                '$1[redacted]@',
                $argument,
            ) ?: $argument;
            if (filter_var($sanitized, FILTER_VALIDATE_URL)) {
                $parts = parse_url($sanitized);
                $sanitized = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
                    .(isset($parts['port']) ? ':'.$parts['port'] : '')
                    .($parts['path'] ?? '');
            }

            return str_contains($sanitized, ' ') || $sanitized === ''
                ? '"'.str_replace('"', '\\"', $sanitized).'"'
                : $sanitized;
        }, $command));
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readProgress(string $buffer, float $durationSeconds, int $currentProgress): array
    {
        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return [$buffer, $currentProgress];
        }

        $complete = substr($buffer, 0, $lastNewline + 1);
        $remainder = substr($buffer, $lastNewline + 1);
        preg_match_all('/^(?:out_time_us|out_time_ms)=(\d+)$/m', $complete, $matches);
        $last = $matches[1] !== [] ? (int) end($matches[1]) : 0;
        if ($last > 0 && $durationSeconds > 0) {
            $seconds = $last / 1_000_000;
            $currentProgress = max($currentProgress, min(99, (int) floor(($seconds / $durationSeconds) * 100)));
        }

        return [$remainder, $currentProgress];
    }

    /**
     * @return bool false when this attempt has been superseded (0 rows
     *              matched processing_attempt_id) and the caller should stop.
     */
    private function heartbeat(
        MediaSource $source,
        string $stage,
        int $stageProgress,
        string $diagnostics,
        ?string $attemptId
    ): bool {
        $overallProgress = match ($stage) {
            'faststarting' => 10 + (int) floor($stageProgress * 0.68),
            'compressing' => 10 + (int) floor($stageProgress * 0.68),
            default => str_starts_with($stage, 'hls_')
                ? 85 + (int) floor($stageProgress * 0.10)
                : min(99, $stageProgress),
        };

        $query = MediaSource::query()->whereKey($source->id);
        if ($attemptId !== null) {
            $query->where('processing_attempt_id', $attemptId);
        }

        $affected = $query->update([
            'processing_stage' => $stage,
            'processing_stage_progress' => max(0, min(100, $stageProgress)),
            'processing_heartbeat_at' => now(),
            'processing_diagnostics' => $diagnostics !== '' ? $diagnostics : null,
            'progress_percent' => max(0, min(99, $overallProgress)),
            'last_progress_at' => now(),
        ]);
        $stillCurrent = $attemptId === null || $affected > 0;
        if ($stillCurrent) {
            ProcessingLiveness::touch($source->id);
        }

        return $stillCurrent;
    }

    private function boundedDiagnostics(string $value): string
    {
        $maxBytes = max(1000, (int) config('cdn.ffmpeg_diagnostics_max_bytes', 12000));
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line): bool {
            $trimmed = trim($line);
            if ($trimmed === '') {
                return false;
            }

            return preg_match('/^(frame|fps|stream_\d+_\d+_q|bitrate|total_size|out_time_(?:us|ms)|out_time|dup_frames|drop_frames|speed|progress)=/', $trimmed) !== 1;
        }));
        $clean = implode("\n", $lines);

        return strlen($clean) > $maxBytes ? substr($clean, -$maxBytes) : $clean;
    }
}

<?php

namespace App\Services;

class MediaBinaryDetector
{
    /** @var array<string, ?string> */
    private array $detected = [];

    public function ffmpeg(): ?string
    {
        return $this->detect('ffmpeg', (string) config('cdn.ffmpeg_binary', ''));
    }

    public function ffprobe(): ?string
    {
        return $this->detect('ffprobe', (string) config('cdn.ffprobe_binary', ''));
    }

    public function requireFfmpeg(): string
    {
        $path = $this->ffmpeg();
        if (! $path) {
            throw new \RuntimeException('FFmpeg binary was not found. Set FFMPEG_BIN or install ffmpeg in the Docker image/server.');
        }

        return $path;
    }

    public function requireFfprobe(): string
    {
        $path = $this->ffprobe();
        if (! $path) {
            throw new \RuntimeException('FFprobe binary was not found. Set FFPROBE_BIN or install ffprobe in the Docker image/server.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $ffmpeg = $this->ffmpeg();
        $ffprobe = $this->ffprobe();

        return [
            'ffmpeg' => [
                'configured_path' => (string) config('cdn.ffmpeg_binary', ''),
                'detected_path' => $ffmpeg,
                'available' => $ffmpeg !== null,
                'version' => $ffmpeg ? $this->version($ffmpeg) : null,
            ],
            'ffprobe' => [
                'configured_path' => (string) config('cdn.ffprobe_binary', ''),
                'detected_path' => $ffprobe,
                'available' => $ffprobe !== null,
                'version' => $ffprobe ? $this->version($ffprobe) : null,
            ],
        ];
    }

    private function detect(string $binary, string $configured): ?string
    {
        if (array_key_exists($binary, $this->detected)) {
            return $this->detected[$binary];
        }

        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $candidates = array_merge($candidates, match ($binary) {
            'ffmpeg' => ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'],
            'ffprobe' => ['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe'],
            default => [],
        });

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if ($this->isRunnable($candidate)) {
                return $this->detected[$binary] = $candidate;
            }
        }

        $shellCandidate = $this->shellLookup($binary);
        if ($shellCandidate && $this->isRunnable($shellCandidate)) {
            return $this->detected[$binary] = $shellCandidate;
        }

        return $this->detected[$binary] = null;
    }

    private function isRunnable(string $path): bool
    {
        return $path !== '' && is_file($path) && is_executable($path);
    }

    private function shellLookup(string $binary): ?string
    {
        if (! in_array($binary, ['ffmpeg', 'ffprobe'], true)) {
            return null;
        }

        $output = [];
        $exitCode = 1;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
        $candidate = trim((string) ($output[0] ?? ''));

        return $exitCode === 0 && $candidate !== '' ? $candidate : null;
    }

    private function version(string $path): ?string
    {
        $output = [];
        $exitCode = 1;
        @exec(escapeshellarg($path) . ' -version 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }

        return isset($output[0]) ? mb_substr(trim((string) $output[0]), 0, 240) : null;
    }
}

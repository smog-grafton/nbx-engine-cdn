<?php

namespace App\Services;

class VideoProbeService
{
    /**
     * @return array<string, mixed>
     */
    public function probe(string $absolutePath): array
    {
        $ffprobe = app(MediaBinaryDetector::class)->ffprobe();
        if (! $ffprobe || ! is_file($absolutePath)) {
            return [];
        }

        $cmd = implode(' ', [
            escapeshellarg($ffprobe),
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            escapeshellarg($absolutePath),
            '2>&1',
        ]);

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }

        $payload = json_decode(implode("\n", $output), true);
        if (! is_array($payload)) {
            return [];
        }

        $streams = is_array($payload['streams'] ?? null) ? $payload['streams'] : [];
        $format = is_array($payload['format'] ?? null) ? $payload['format'] : [];
        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }
            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        return array_filter([
            'container' => isset($format['format_name']) ? (string) $format['format_name'] : null,
            'video_codec' => is_array($video) ? (string) ($video['codec_name'] ?? '') ?: null : null,
            'audio_codec' => is_array($audio) ? (string) ($audio['codec_name'] ?? '') ?: null : null,
            'width' => is_array($video) && isset($video['width']) ? (int) $video['width'] : null,
            'height' => is_array($video) && isset($video['height']) ? (int) $video['height'] : null,
            'duration' => isset($format['duration']) ? (float) $format['duration'] : null,
            'duration_seconds' => isset($format['duration']) ? (int) round((float) $format['duration']) : null,
            'bitrate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'frame_rate' => is_array($video) ? $this->normalizeFrameRate((string) ($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? '')) : null,
            'file_size' => is_file($absolutePath) ? (int) filesize($absolutePath) : null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function normalizeFrameRate(string $value): ?float
    {
        if ($value === '' || $value === '0/0') {
            return null;
        }

        if (str_contains($value, '/')) {
            [$numerator, $denominator] = array_map('floatval', explode('/', $value, 2));
            return $denominator > 0 ? round($numerator / $denominator, 3) : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

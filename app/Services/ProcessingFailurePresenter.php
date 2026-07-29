<?php

namespace App\Services;

use App\Models\MediaSource;

class ProcessingFailurePresenter
{
    /**
     * @return array{code:string,message:string,support_reference:string,retryable:bool,action_required:bool}|null
     */
    public function forSource(MediaSource $source): ?array
    {
        $internal = trim((string) (
            $source->failure_reason
            ?: $source->optimize_error
            ?: $source->last_error
            ?: $source->hls_worker_last_error
        ));
        if ($internal === '') {
            return null;
        }

        $metadata = (array) ($source->source_metadata ?? []);
        $persistedCode = trim((string) data_get($metadata, 'nbx.error_code', ''));
        $code = $persistedCode !== '' ? $persistedCode : $this->classify($internal);

        [$message, $retryable, $actionRequired] = match ($code) {
            'SOURCE_DOWNLOAD_FAILED' => [
                'NBX could not finish downloading the submitted source. Confirm that the link is still accessible, then retry.',
                true,
                true,
            ],
            'SOURCE_FILE_INVALID' => [
                'The submitted file could not be read as a complete video. Upload another copy or contact support.',
                false,
                true,
            ],
            'UNSUPPORTED_VIDEO_FORMAT' => [
                'This video format could not be prepared automatically. Upload an MP4 version or contact support.',
                false,
                true,
            ],
            'STORAGE_UPLOAD_FAILED' => [
                'The movie was processed, but final storage did not complete. The processed file was preserved and storage upload can be retried.',
                true,
                false,
            ],
            'PORTAL_SYNC_FAILED' => [
                'The movie was processed successfully, but publication has not completed. Synchronization can be retried without uploading the video again.',
                true,
                false,
            ],
            default => [
                'NBX could not prepare this movie for playback. The technical team can use the reference below to inspect the failure.',
                true,
                false,
            ],
        };

        return [
            'code' => $code,
            'message' => $message,
            'support_reference' => strtoupper(substr(hash('sha256', implode('|', [
                (string) ($source->external_job_id ?: 'source-'.$source->id),
                (string) $source->id,
                (string) ($source->processing_attempt_id ?: $source->processing_revision),
                $internal,
            ])), 0, 12)),
            'retryable' => $retryable,
            'action_required' => $actionRequired,
        ];
    }

    private function classify(string $message): string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'final storage'),
            str_contains($normalized, 'multipart'),
            str_contains($normalized, 'stored object verification') => 'STORAGE_UPLOAD_FAILED',

            str_contains($normalized, 'portal sync'),
            str_contains($normalized, 'discovery request') => 'PORTAL_SYNC_FAILED',

            str_contains($normalized, 'download'),
            str_contains($normalized, 'remote fetch'),
            str_contains($normalized, 'curl error') => 'SOURCE_DOWNLOAD_FAILED',

            str_contains($normalized, 'moov atom'),
            str_contains($normalized, 'invalid data'),
            str_contains($normalized, 'could not be probed'),
            str_contains($normalized, 'complete video file') => 'SOURCE_FILE_INVALID',

            str_contains($normalized, 'unsupported'),
            str_contains($normalized, 'unknown encoder'),
            str_contains($normalized, 'pixel format') => 'UNSUPPORTED_VIDEO_FORMAT',

            default => 'PROCESSING_FAILED',
        };
    }
}

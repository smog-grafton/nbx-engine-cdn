<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupNbxUploadSessions extends Command
{
    protected $signature = 'nbx:cleanup-upload-sessions';

    protected $description = 'Remove expired resumable-upload chunk directories.';

    public function handle(): int
    {
        $root = storage_path('app/'.trim((string) config('nbx.upload_session_dir', 'nbx/upload-sessions'), '/'));
        if (! is_dir($root)) {
            $this->info('No temporary upload sessions found.');

            return self::SUCCESS;
        }
        $cutoff = now()->subMinutes(max(5, (int) config('nbx.upload_session_ttl_minutes', 60)) + 30)->getTimestamp();
        $deleted = 0;
        foreach (File::directories($root) as $directory) {
            $modifiedAt = File::lastModified($directory);
            if ($modifiedAt < $cutoff && File::deleteDirectory($directory)) {
                $deleted++;
            }
        }
        $this->info("Deleted {$deleted} expired upload-session director".($deleted === 1 ? 'y.' : 'ies.'));

        return self::SUCCESS;
    }
}

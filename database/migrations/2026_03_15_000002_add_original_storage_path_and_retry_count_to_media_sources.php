<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            // Track the true original source file path.
            // Once set, this path should never be overwritten – it's the forensic record
            // of where the file came from before any compression took place.
            if (! Schema::hasColumn('media_sources', 'original_storage_path')) {
                $table->string('original_storage_path')->nullable()->after('storage_path')
                    ->comment('Immutable path to the true original file before any compression/faststart');
            }

            // Track how many times optimization has been retried for a source.
            // Used to cap infinite retry loops in the scheduler.
            if (! Schema::hasColumn('media_sources', 'optimize_retry_count')) {
                $table->unsignedSmallInteger('optimize_retry_count')->default(0)->after('optimize_error');
            }
        });

        // Back-fill original_storage_path for existing rows where storage_path is set
        // but original_storage_path is null and the file has NOT already been updated to
        // a _play variant (meaning storage_path IS the original).
        // For rows where storage_path ends in _play.mp4 we cannot recover the original path.
        DB::table('media_sources')
            ->whereNotNull('storage_path')
            ->whereNull('original_storage_path')
            ->whereRaw("storage_path NOT LIKE '%_play.mp4'")
            ->whereRaw("storage_path NOT LIKE '%_play_%'")
            ->update(['original_storage_path' => DB::raw('storage_path')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            if (Schema::hasColumn('media_sources', 'original_storage_path')) {
                $table->dropColumn('original_storage_path');
            }
            if (Schema::hasColumn('media_sources', 'optimize_retry_count')) {
                $table->dropColumn('optimize_retry_count');
            }
        });
    }
};

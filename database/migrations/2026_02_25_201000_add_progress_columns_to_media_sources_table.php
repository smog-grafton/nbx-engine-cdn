<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_sources', 'progress_percent')) {
                $table->unsignedTinyInteger('progress_percent')->nullable()->after('external_job_id');
            }

            if (! Schema::hasColumn('media_sources', 'bytes_downloaded')) {
                $table->unsignedBigInteger('bytes_downloaded')->nullable()->after('progress_percent');
            }

            if (! Schema::hasColumn('media_sources', 'bytes_total')) {
                $table->unsignedBigInteger('bytes_total')->nullable()->after('bytes_downloaded');
            }

            if (! Schema::hasColumn('media_sources', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('bytes_total');
            }

            if (! Schema::hasColumn('media_sources', 'last_progress_at')) {
                $table->timestamp('last_progress_at')->nullable()->after('started_at');
            }

            if (! Schema::hasColumn('media_sources', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('last_progress_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            $columnsToDrop = [];

            foreach ([
                'progress_percent',
                'bytes_downloaded',
                'bytes_total',
                'started_at',
                'last_progress_at',
                'completed_at',
            ] as $column) {
                if (Schema::hasColumn('media_sources', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

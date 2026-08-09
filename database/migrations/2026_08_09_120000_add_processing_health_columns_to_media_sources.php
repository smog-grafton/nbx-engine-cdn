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
            if (! Schema::hasColumn('media_sources', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('processing_attempt_started_at');
            }
            if (! Schema::hasColumn('media_sources', 'processing_worker_id')) {
                $table->string('processing_worker_id', 120)->nullable()->after('processing_started_at');
            }
            if (! Schema::hasColumn('media_sources', 'ffmpeg_pid')) {
                $table->unsignedBigInteger('ffmpeg_pid')->nullable()->after('processing_worker_id');
            }
            if (! Schema::hasColumn('media_sources', 'processed_seconds')) {
                $table->decimal('processed_seconds', 14, 3)->nullable()->after('ffmpeg_pid');
            }
            if (! Schema::hasColumn('media_sources', 'current_output_size_bytes')) {
                $table->unsignedBigInteger('current_output_size_bytes')->nullable()->after('processed_seconds');
            }
            if (! Schema::hasColumn('media_sources', 'output_size_observed_at')) {
                $table->timestamp('output_size_observed_at')->nullable()->after('current_output_size_bytes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'processing_started_at',
                'processing_worker_id',
                'ffmpeg_pid',
                'processed_seconds',
                'current_output_size_bytes',
                'output_size_observed_at',
            ], fn (string $column): bool => Schema::hasColumn('media_sources', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

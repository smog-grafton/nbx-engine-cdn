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
            if (! Schema::hasColumn('media_sources', 'processing_stage')) {
                $table->string('processing_stage', 40)->nullable()->after('optimize_status');
            }
            if (! Schema::hasColumn('media_sources', 'processing_attempt_id')) {
                $table->uuid('processing_attempt_id')->nullable()->index()->after('processing_stage');
            }
            if (! Schema::hasColumn('media_sources', 'processing_stage_progress')) {
                $table->unsignedTinyInteger('processing_stage_progress')->nullable()->after('processing_attempt_id');
            }
            if (! Schema::hasColumn('media_sources', 'processing_heartbeat_at')) {
                $table->timestamp('processing_heartbeat_at')->nullable()->after('processing_stage_progress');
            }
            if (! Schema::hasColumn('media_sources', 'processing_diagnostics')) {
                $table->text('processing_diagnostics')->nullable()->after('processing_heartbeat_at');
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
                'processing_stage',
                'processing_attempt_id',
                'processing_stage_progress',
                'processing_heartbeat_at',
                'processing_diagnostics',
            ], fn (string $column): bool => Schema::hasColumn('media_sources', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

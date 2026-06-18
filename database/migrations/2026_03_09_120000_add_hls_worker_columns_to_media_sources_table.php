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
            if (! Schema::hasColumn('media_sources', 'hls_worker_status')) {
                $table->string('hls_worker_status', 32)->nullable()->after('qualities_json');
            }
            if (! Schema::hasColumn('media_sources', 'hls_worker_artifact_url')) {
                $table->string('hls_worker_artifact_url', 2048)->nullable()->after('hls_worker_status');
            }
            if (! Schema::hasColumn('media_sources', 'hls_worker_artifact_expires_at')) {
                $table->timestamp('hls_worker_artifact_expires_at')->nullable()->after('hls_worker_artifact_url');
            }
            if (! Schema::hasColumn('media_sources', 'hls_worker_last_error')) {
                $table->text('hls_worker_last_error')->nullable()->after('hls_worker_artifact_expires_at');
            }
            if (! Schema::hasColumn('media_sources', 'hls_worker_external_id')) {
                $table->string('hls_worker_external_id', 64)->nullable()->after('hls_worker_last_error');
            }
            if (! Schema::hasColumn('media_sources', 'hls_worker_quality_status')) {
                $table->string('hls_worker_quality_status', 32)->nullable()->after('hls_worker_external_id');
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
            foreach (['hls_worker_status', 'hls_worker_artifact_url', 'hls_worker_artifact_expires_at', 'hls_worker_last_error', 'hls_worker_external_id', 'hls_worker_quality_status'] as $col) {
                if (Schema::hasColumn('media_sources', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

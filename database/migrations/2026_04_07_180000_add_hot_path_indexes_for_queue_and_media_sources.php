<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->index(
                ['queue', 'reserved_at', 'available_at'],
                'jobs_queue_reserved_available_index'
            );
        });

        Schema::table('media_sources', function (Blueprint $table): void {
            $table->index(
                ['status', 'optimize_status', 'updated_at'],
                'media_sources_status_optimize_updated_index'
            );

            $table->index(
                ['status', 'updated_at'],
                'media_sources_status_updated_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->dropIndex('jobs_queue_reserved_available_index');
        });

        Schema::table('media_sources', function (Blueprint $table): void {
            $table->dropIndex('media_sources_status_optimize_updated_index');
            $table->dropIndex('media_sources_status_updated_index');
        });
    }
};

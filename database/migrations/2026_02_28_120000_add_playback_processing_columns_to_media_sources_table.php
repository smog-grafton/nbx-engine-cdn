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
            if (! Schema::hasColumn('media_sources', 'is_faststart')) {
                $table->boolean('is_faststart')->default(false)->after('last_attempt_host');
            }

            if (! Schema::hasColumn('media_sources', 'optimize_status')) {
                $table->enum('optimize_status', ['pending', 'processing', 'ready', 'failed'])
                    ->nullable()
                    ->after('is_faststart');
            }

            if (! Schema::hasColumn('media_sources', 'optimized_path')) {
                $table->string('optimized_path')->nullable()->after('optimize_status');
            }

            if (! Schema::hasColumn('media_sources', 'optimize_error')) {
                $table->text('optimize_error')->nullable()->after('optimized_path');
            }

            if (! Schema::hasColumn('media_sources', 'optimized_at')) {
                $table->timestamp('optimized_at')->nullable()->after('optimize_error');
            }

            if (! Schema::hasColumn('media_sources', 'playback_type')) {
                $table->enum('playback_type', ['mp4', 'hls'])->nullable()->after('optimized_at');
            }

            if (! Schema::hasColumn('media_sources', 'hls_master_path')) {
                $table->string('hls_master_path')->nullable()->after('playback_type');
            }

            if (! Schema::hasColumn('media_sources', 'qualities_json')) {
                $table->json('qualities_json')->nullable()->after('hls_master_path');
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
                'is_faststart',
                'optimize_status',
                'optimized_path',
                'optimize_error',
                'optimized_at',
                'playback_type',
                'hls_master_path',
                'qualities_json',
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


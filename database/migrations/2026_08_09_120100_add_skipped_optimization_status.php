<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_sources') || ! Schema::hasColumn('media_sources', 'optimize_status')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            $table->enum('optimize_status', ['pending', 'processing', 'ready', 'failed', 'skipped'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources') || ! Schema::hasColumn('media_sources', 'optimize_status')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            $table->enum('optimize_status', ['pending', 'processing', 'ready', 'failed'])
                ->nullable()
                ->change();
        });
    }
};

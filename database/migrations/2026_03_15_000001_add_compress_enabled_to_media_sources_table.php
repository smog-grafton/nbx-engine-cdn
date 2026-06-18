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
            if (! Schema::hasColumn('media_sources', 'compress_enabled')) {
                $table->boolean('compress_enabled')->default(true)->after('is_faststart');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            if (Schema::hasColumn('media_sources', 'compress_enabled')) {
                $table->dropColumn('compress_enabled');
            }
        });
    }
};

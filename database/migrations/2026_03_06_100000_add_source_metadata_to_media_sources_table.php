<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('media_sources', 'source_metadata')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table) {
            $table->json('source_metadata')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media_sources', 'source_metadata')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table) {
            $table->dropColumn('source_metadata');
        });
    }
};

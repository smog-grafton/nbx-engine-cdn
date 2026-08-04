<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table) {
            $table->string('storage_target_key', 64)->nullable()->after('storage_disk');
        });

        // Existing rows have no explicit target: interpret them as the
        // legacy bucket (contabo_nbx / "nbx") per the compatibility rules —
        // no object keys/URLs are rewritten, only this new column is filled.
        DB::table('media_sources')
            ->whereNull('storage_target_key')
            ->update(['storage_target_key' => 'contabo_nbx']);

        Schema::table('media_sources', function (Blueprint $table) {
            $table->index('storage_target_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table) {
            $table->dropIndex(['storage_target_key']);
            $table->dropColumn('storage_target_key');
        });
    }
};

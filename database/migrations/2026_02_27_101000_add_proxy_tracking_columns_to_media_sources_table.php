<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_sources', 'last_error')) {
                $table->text('last_error')->nullable()->after('failure_reason');
            }

            if (! Schema::hasColumn('media_sources', 'last_attempt_host')) {
                $table->string('last_attempt_host')->nullable()->after('last_error');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE media_sources MODIFY status ENUM('pending','downloading','processing','proxying','uploading','ready','failed') DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_sources')) {
            return;
        }

        Schema::table('media_sources', function (Blueprint $table): void {
            $columns = [];

            foreach (['last_error', 'last_attempt_host'] as $column) {
                if (Schema::hasColumn('media_sources', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE media_sources MODIFY status ENUM('pending','downloading','processing','ready','failed') DEFAULT 'pending'"
            );
        }
    }
};


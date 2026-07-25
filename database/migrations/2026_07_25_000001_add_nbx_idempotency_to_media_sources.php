<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique()->after('external_job_id');
            $table->unsignedInteger('processing_revision')->default(1)->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'processing_revision']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table) {
            // Set once per attempt, in MediaSourceService::queuePlaybackProcessing(),
            // and never touched again until the next attempt. Deliberately
            // separate from processing_heartbeat_at (which moves constantly)
            // and started_at (already used for the fetch/upload stage) so an
            // "estimated time remaining" can be computed from a stable anchor:
            // elapsed = now() - processing_attempt_started_at.
            $table->timestamp('processing_attempt_started_at')->nullable()->after('processing_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table) {
            $table->dropColumn('processing_attempt_started_at');
        });
    }
};

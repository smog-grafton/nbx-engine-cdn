<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_fetch_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_source_id')->unique()->constrained('media_sources')->cascadeOnDelete();
            $table->text('original_url');
            $table->text('final_url')->nullable();
            $table->unsignedBigInteger('expected_size')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->string('strategy', 32)->default('ranged');
            $table->string('status', 32)->default('probing')->index();
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remote_fetch_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remote_fetch_session_id')->constrained('remote_fetch_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('start_byte');
            $table->unsignedBigInteger('end_byte');
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->string('checksum', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['remote_fetch_session_id', 'start_byte'], 'remote_fetch_parts_session_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_fetch_parts');
        Schema::dropIfExists('remote_fetch_sessions');
    }
};

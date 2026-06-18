<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_sources')) {
            return;
        }

        Schema::create('media_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_asset_id');
            $table->enum('source_type', ['url', 'embed', 'upload', 'remote_fetch']);
            $table->text('source_url')->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->enum('status', ['pending', 'downloading', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->string('external_job_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('media_asset_id')
                ->references('id')
                ->on('media_assets')
                ->cascadeOnDelete();

            $table->index('media_asset_id');
            $table->index('source_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_sources');
    }
};


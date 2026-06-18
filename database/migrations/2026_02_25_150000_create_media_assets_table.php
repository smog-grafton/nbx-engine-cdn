<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_assets')) {
            return;
        }

        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['movie', 'episode', 'generic'])->default('generic');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'importing', 'ready', 'failed', 'disabled'])->default('draft');
            $table->enum('visibility', ['public', 'unlisted'])->default('public');
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};


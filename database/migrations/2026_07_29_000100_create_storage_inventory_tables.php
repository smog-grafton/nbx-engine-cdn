<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_inventory_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('storage_disk', 64)->default('contabo');
            $table->string('storage_bucket', 191);
            $table->string('prefix', 512)->default('');
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedBigInteger('object_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);
            $table->text('continuation_token')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('storage_inventory_objects', function (Blueprint $table): void {
            $table->id();
            $table->string('object_hash', 64)->unique();
            $table->string('storage_disk', 64)->default('contabo')->index();
            $table->string('storage_bucket', 191)->index();
            $table->text('object_key');
            $table->string('object_prefix', 512)->default('')->index();
            $table->string('filename', 512);
            $table->string('extension', 24)->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->default(0)->index();
            $table->string('etag', 191)->nullable()->index();
            $table->string('content_type', 191)->nullable();
            $table->timestamp('object_last_modified_at')->nullable()->index();
            $table->string('storage_layout', 48)->default('unknown')->index();
            $table->string('logical_asset_key', 191)->index();
            $table->string('media_role', 48)->default('unknown')->index();
            $table->uuid('media_asset_id')->nullable()->index();
            $table->unsignedBigInteger('media_source_id')->nullable()->index();
            $table->unsignedBigInteger('portal_source_id')->nullable()->index();
            $table->string('portal_sourceable_type')->nullable();
            $table->unsignedBigInteger('portal_sourceable_id')->nullable()->index();
            $table->boolean('is_manifest_member')->default(false)->index();
            $table->boolean('is_duplicate_candidate')->default(false)->index();
            $table->string('duplicate_group_hash', 64)->nullable()->index();
            $table->string('duplicate_evidence', 32)->nullable();
            $table->string('content_sha256', 64)->nullable()->index();
            $table->timestamp('checksum_verified_at')->nullable()->index();
            $table->string('classification', 48)->default('unresolved')->index();
            $table->string('confidence', 16)->default('low')->index();
            $table->text('classification_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('storage_inventory_runs')->nullOnDelete();
            // useCurrent(): strict-mode MySQL (the MySQL 8 default) rejects a
            // NOT NULL timestamp column with no default at CREATE TABLE time
            // (error 1067). The application always overwrites both columns
            // explicitly on every insert/upsert (StorageInventoryService::objectRow()),
            // so this default is never actually relied on — it only exists
            // to satisfy strict mode.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent()->index();
            $table->timestamp('missing_since')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['storage_bucket', 'logical_asset_key', 'classification'],
                'storage_inventory_bucket_group_class_index'
            );
            $table->index(
                ['storage_layout', 'portal_sourceable_type', 'portal_sourceable_id'],
                'storage_inventory_portal_hint_index'
            );
        });

        Schema::create('storage_cleanup_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->string('logical_asset_key', 191)->index();
            $table->unsignedInteger('object_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->string('risk_level', 16)->default('high')->index();
            $table->text('reason')->nullable();
            $table->timestamp('grace_expires_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('storage_cleanup_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_cleanup_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_inventory_object_id')->constrained()->cascadeOnDelete();
            $table->string('proposed_action', 32)->default('review_only');
            $table->string('status', 24)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['storage_cleanup_plan_id', 'storage_inventory_object_id'],
                'storage_cleanup_plan_object_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_cleanup_plan_items');
        Schema::dropIfExists('storage_cleanup_plans');
        Schema::dropIfExists('storage_inventory_objects');
        Schema::dropIfExists('storage_inventory_runs');
    }
};

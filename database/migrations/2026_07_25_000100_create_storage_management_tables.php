<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('storage_permissions')->nullable()->after('password');
        });

        DB::table('users')->update([
            'storage_permissions' => json_encode([
                'storage.view',
                'storage.reconcile',
                'storage.delete.original',
                'storage.delete.faststart',
                'storage.delete.hls',
                'storage.delete.asset',
                'storage.delete.orphan',
                'storage.manage.direct',
            ]),
        ]);

        Schema::create('storage_object_references', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_key', 64)->unique();
            $table->uuid('media_asset_id')->nullable()->index();
            $table->unsignedBigInteger('media_source_id')->nullable()->index();
            $table->unsignedBigInteger('portal_source_id')->nullable()->index();
            $table->string('portal_sourceable_type')->nullable();
            $table->unsignedBigInteger('portal_sourceable_id')->nullable();
            $table->string('storage_disk', 64)->default('contabo');
            $table->string('storage_bucket', 191);
            $table->text('object_key');
            $table->text('object_url')->nullable();
            $table->string('media_role', 48)->default('unknown')->index();
            $table->boolean('is_external_direct')->default(false)->index();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 24)->default('unknown')->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('deleted_at_storage')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['storage_bucket', 'media_role', 'is_active'],
                'storage_references_bucket_role_active_index'
            );
            $table->index(
                ['portal_sourceable_type', 'portal_sourceable_id'],
                'storage_references_portal_owner_index'
            );
        });

        Schema::create('storage_action_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('media_api_token_id')->nullable()->constrained('media_api_tokens')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('action', 64)->index();
            $table->string('target_type', 48);
            $table->string('target_id', 191)->nullable();
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_bucket', 191)->nullable();
            $table->text('object_key')->nullable();
            $table->unsignedBigInteger('bytes_freed')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_action_audits');
        Schema::dropIfExists('storage_object_references');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('storage_permissions');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreign('created_by_platform_user_id', 'm1_tenant_creator_fk')
                ->references('user_id')->on('platform_staff')->restrictOnDelete();
        });

        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('logo_object_key')->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->jsonb('onboarding_json')->default('{}');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT m1_settings_accent CHECK (accent_color IS NULL OR accent_color ~ '^#[0-9a-fA-F]{6}$')");
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT m1_settings_onboarding_object CHECK (jsonb_typeof(onboarding_json) = 'object')");

        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('action', 120);
            $table->string('target_type', 120);
            $table->string('target_id', 64)->nullable();
            $table->uuid('request_id')->nullable();
            $table->jsonb('metadata_json')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('tenant_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('actor_membership_id')->nullable();
            $table->string('action', 120);
            $table->string('target_type', 120);
            $table->string('target_id', 64)->nullable();
            $table->uuid('request_id')->nullable();
            $table->jsonb('metadata_json')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('ALTER TABLE tenant_audit_logs ADD CONSTRAINT m1_audit_member_same_tenant FOREIGN KEY (tenant_id, actor_membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE tenant_audit_logs ADD CONSTRAINT m1_audit_metadata_object CHECK (jsonb_typeof(metadata_json) = 'object')");
        DB::statement("ALTER TABLE platform_audit_logs ADD CONSTRAINT m1_platform_audit_metadata_object CHECK (jsonb_typeof(metadata_json) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_audit_logs');
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('tenant_settings');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropForeign('m1_tenant_creator_fk'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('proposed_local_username', 64)->nullable();
            $table->string('proposed_local_username_normalized', 64)->nullable();
            $table->foreignId('proposed_role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->unsignedBigInteger('created_by_membership_id')->nullable();
            $table->unsignedBigInteger('created_by_platform_user_id')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->foreign('created_by_platform_user_id', 'm1_invite_platform_actor_fk')
                ->references('user_id')->on('platform_staff')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_member_fk FOREIGN KEY (tenant_id, membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_actor_fk FOREIGN KEY (tenant_id, created_by_membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_purpose CHECK (purpose IN ('activate_new','join_existing','bootstrap_admin'))");
        DB::statement("ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_status CHECK (status IN ('pending','consumed','revoked'))");
        DB::statement('ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_xor_actor CHECK ((created_by_membership_id IS NOT NULL) <> (created_by_platform_user_id IS NOT NULL))');
        DB::statement('ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_target CHECK (membership_id IS NOT NULL OR (proposed_local_username_normalized IS NOT NULL AND proposed_role_id IS NOT NULL))');
        DB::statement('ALTER TABLE account_invitations ADD CONSTRAINT m1_invite_consumed_time CHECK (status <> \'consumed\' OR consumed_at IS NOT NULL)');
        DB::statement("CREATE UNIQUE INDEX m1_one_pending_invite ON account_invitations (tenant_id, membership_id, purpose) WHERE status = 'pending' AND membership_id IS NOT NULL");

        Schema::create('account_recovery_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('initiated_by_membership_id')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('scope', 30);
            $table->string('purpose', 40)->default('password_reset');
            $table->string('status', 30)->default('requested');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'created_at']);
        });
        DB::statement('ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_actor_fk FOREIGN KEY (tenant_id, initiated_by_membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_scope CHECK (scope IN ('self_email','school_assisted','platform_manual'))");
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_status CHECK (status IN ('requested','verified','token_issued','consumed','denied','revoked'))");
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_tenant_scope CHECK ((scope = 'school_assisted' AND tenant_id IS NOT NULL) OR (scope <> 'school_assisted' AND tenant_id IS NULL))");
        DB::statement('ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_token_expiry CHECK (token_hash IS NULL OR expires_at IS NOT NULL)');
        DB::statement('ALTER TABLE account_recovery_requests ADD CONSTRAINT m1_recovery_member_tenant CHECK (initiated_by_membership_id IS NULL OR tenant_id IS NOT NULL)');

        Schema::create('tenant_provisioning_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id');
            $table->char('idempotency_key_hash', 64);
            $table->char('payload_digest', 64);
            $table->unsignedBigInteger('tenant_id')->nullable()->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('status', 20)->default('processing');
            $table->string('failure_code', 100)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_user_id', 'idempotency_key_hash'], 'm1_provision_idempotent');
            $table->index(['actor_user_id', 'created_at']);
            $table->foreign('actor_user_id')->references('user_id')->on('platform_staff')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE tenant_provisioning_requests ADD CONSTRAINT m1_provision_status CHECK (status IN ('processing','succeeded','failed'))");
        DB::statement("ALTER TABLE tenant_provisioning_requests ADD CONSTRAINT m1_provision_success_target CHECK (status <> 'succeeded' OR tenant_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provisioning_requests');
        Schema::dropIfExists('account_recovery_requests');
        Schema::dropIfExists('account_invitations');
    }
};

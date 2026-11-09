<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('local_username', 64);
            $table->string('local_username_normalized', 64);
            $table->string('display_name', 150);
            $table->string('status', 20)->default('invited');
            $table->unsignedInteger('auth_version')->default(1);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'local_username_normalized'], 'm1_membership_username');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT m1_membership_status CHECK (status IN ('invited','active','suspended','left'))");
        DB::statement('ALTER TABLE tenant_memberships ADD CONSTRAINT m1_membership_auth_version CHECK (auth_version >= 1)');

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_system')->default(true);
            $table->timestampsTz();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('description')->nullable();
            $table->string('scope', 20);
            $table->timestampsTz();
            $table->unique(['id', 'scope']);
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT m1_permission_scope CHECK (scope IN ('tenant','platform'))");
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->string('permission_scope', 20)->default('tenant');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            // Composite FK is declared below to prevent bypass at the DB level.
        });
        DB::statement("ALTER TABLE role_permissions ADD CONSTRAINT m1_tenant_permissions_only CHECK (permission_scope = 'tenant')");
        DB::statement('ALTER TABLE role_permissions ADD CONSTRAINT m1_permission_scope_fk FOREIGN KEY (permission_id, permission_scope) REFERENCES permissions(id, scope) ON DELETE RESTRICT');

        Schema::create('membership_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['tenant_id', 'membership_id', 'role_id'], 'm1_membership_roles_pk');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE membership_roles ADD CONSTRAINT m1_member_role_same_tenant FOREIGN KEY (tenant_id, membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenant_memberships');
    }
};

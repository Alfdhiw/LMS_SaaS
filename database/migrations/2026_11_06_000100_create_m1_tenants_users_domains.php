<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique();
            $table->string('name', 150);
            $table->string('email', 254)->nullable();
            $table->string('email_normalized', 254)->nullable()->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('auth_version')->default(1);
            $table->boolean('must_change_password')->default(false);
            $table->timestampTz('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT m1_users_status CHECK (status IN ('pending','active','suspended'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT m1_users_active_password CHECK (status <> 'active' OR password IS NOT NULL)");
        DB::statement('ALTER TABLE users ADD CONSTRAINT m1_users_auth_version CHECK (auth_version >= 1)');

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique();
            $table->string('slug', 63)->unique();
            $table->string('name', 200);
            $table->string('status', 20)->default('pending');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            // The FK is added in batch M1-C after platform_staff exists.
            $table->unsignedBigInteger('created_by_platform_user_id')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT m1_tenants_status CHECK (status IN ('pending','active','suspended','archived'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT m1_tenants_slug CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$')");

        Schema::create('platform_staff', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->string('role', 40)->default('super_admin');
            $table->string('status', 20)->default('active');
            $table->timestampTz('appointed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT m1_staff_role CHECK (role IN ('super_admin'))");
        DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT m1_staff_status CHECK (status IN ('active','suspended'))");

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('kind', 30)->default('default');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT m1_domain_status CHECK (status IN ('pending','active','revoked'))");
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT m1_domain_kind CHECK (kind IN ('default','custom'))");
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT m1_active_domain_verified CHECK (status <> 'active' OR verified_at IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX m1_one_primary_active_domain ON tenant_domains (tenant_id) WHERE is_primary = TRUE AND status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('platform_staff');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('users');
    }
};

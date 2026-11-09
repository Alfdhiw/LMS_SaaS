<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PostgreSQL-only schema contract checks for the M1 draft.
 * These tests do not assert that auth/provisioning features are implemented.
 */
class M1DatabaseContractTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Test User'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'name' => $name,
            'password' => Hash::make('M1TestPassword!234'),
            'status' => 'active',
        ]);
    }

    private function tenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'slug' => $slug,
            'name' => 'Sekolah Dummy '.$slug,
        ]);
    }

    private function membership(int $tenant, int $user, string $username): int
    {
        return (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenant,
            'user_id' => $user,
            'local_username' => $username,
            'local_username_normalized' => strtolower($username),
            'display_name' => 'Guru Dummy',
            'status' => 'active',
        ]);
    }

    public function test_m1_core_tables_exist(): void
    {
        foreach ([
            'users', 'tenants', 'platform_staff', 'tenant_domains', 'tenant_memberships',
            'roles', 'permissions', 'role_permissions', 'membership_roles',
            'tenant_settings', 'tenant_audit_logs', 'platform_audit_logs',
            'account_invitations', 'account_recovery_requests', 'tenant_provisioning_requests',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_same_username_is_valid_in_different_schools(): void
    {
        $a = $this->tenant('sekolah-a');
        $b = $this->tenant('sekolah-b');
        $this->membership($a, $this->user('Guru A'), 'guru01');
        $this->membership($b, $this->user('Guru B'), 'guru01');
        $this->assertSame(2, DB::table('tenant_memberships')->count());
    }

    public function test_platform_permission_is_not_grantable_to_school_role(): void
    {
        $role = DB::table('roles')->insertGetId(['key' => 'school_admin', 'display_name' => 'Admin Sekolah']);
        $permission = DB::table('permissions')->insertGetId(['key' => 'platform.tenants.create', 'scope' => 'platform']);
        $this->expectException(QueryException::class);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
    }

    public function test_composite_foreign_key_rejects_member_of_another_tenant(): void
    {
        $a = $this->tenant('kelas-a');
        $b = $this->tenant('kelas-b');
        $membership = $this->membership($a, $this->user(), 'guru02');
        $role = DB::table('roles')->insertGetId(['key' => 'teacher', 'display_name' => 'Guru']);
        $this->expectException(QueryException::class);
        DB::table('membership_roles')->insert(['tenant_id' => $b, 'membership_id' => $membership, 'role_id' => $role]);
    }

    public function test_multiple_primary_active_domains_are_rejected(): void
    {
        $tenant = $this->tenant('domain-a');
        foreach (['a.example.test', 'b.example.test'] as $host) {
            if ($host === 'b.example.test') {
                $this->expectException(QueryException::class);
            }
            DB::table('tenant_domains')->insert([
                'tenant_id' => $tenant,
                'hostname' => $host,
                'kind' => 'default',
                'status' => 'active',
                'verified_at' => now(),
                'is_primary' => true,
            ]);
        }
    }

    public function test_user_cannot_be_active_without_password(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'public_ulid' => (string) Str::ulid(),
            'name' => 'Akun Tanpa Sandi',
            'password' => null,
            'status' => 'active',
        ]);
    }

    public function test_invitation_requires_exactly_one_creator(): void
    {
        $tenant = $this->tenant('invited-a');
        $membership = $this->membership($tenant, $this->user(), 'siswa01');
        $this->expectException(QueryException::class);
        DB::table('account_invitations')->insert([
            'public_ulid' => (string) Str::ulid(),
            'tenant_id' => $tenant,
            'membership_id' => $membership,
            'purpose' => 'activate_new',
            'token_hash' => hash('sha256', random_bytes(32)),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_provisioning_idempotency_key_is_unique_per_actor(): void
    {
        $actor = $this->user('Operator Platform');
        DB::table('platform_staff')->insert(['user_id' => $actor, 'role' => 'super_admin', 'status' => 'active']);
        $payload = [
            'actor_user_id' => $actor,
            'idempotency_key_hash' => hash('sha256', 'submission-1'),
            'payload_digest' => hash('sha256', 'payload-1'),
            'status' => 'processing',
        ];
        DB::table('tenant_provisioning_requests')->insert($payload);
        $this->expectException(QueryException::class);
        DB::table('tenant_provisioning_requests')->insert($payload);
    }
}

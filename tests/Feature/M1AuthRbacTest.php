<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Services\CentralMfaService;
use App\Modules\Identity\Services\PermissionChecker;
use App\Modules\Identity\Services\PlatformPermissionChecker;
use App\Modules\Tenancy\Services\TenantResolver;
use App\Support\Auth\AuthSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class M1AuthRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--no-interaction' => true,
        ]);
    }

    public function test_same_username_on_two_tenant_hosts_authenticates_separate_users(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $schoolB = $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');
        $userA = $this->user('Siswa A', 'Password-A!234');
        $userB = $this->user('Siswa B', 'Password-B!234');
        $membershipA = $this->membership($schoolA, $userA, 'siswa01');
        $membershipB = $this->membership($schoolB, $userB, 'siswa01');

        $this->postWithCsrf('http://sekolah-a.platform.test/login', [
            'username' => 'SISWA01',
            'password' => 'Password-A!234',
        ])->assertRedirect('/dashboard');

        $this->postWithCsrf('http://sekolah-b.platform.test/login', [
            'username' => 'siswa01',
            'password' => 'Password-B!234',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $schoolA,
            'actor_user_id' => $userA,
            'actor_membership_id' => $membershipA,
            'action' => 'auth.login.succeeded',
        ]);
        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $schoolB,
            'actor_user_id' => $userB,
            'actor_membership_id' => $membershipB,
            'action' => 'auth.login.succeeded',
        ]);
    }

    public function test_one_global_user_has_independent_roles_per_membership(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $schoolB = $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');
        $user = $this->user('Guru Multi Sekolah', 'Password!234');
        $membershipA = $this->membership($schoolA, $user, 'guru01');
        $membershipB = $this->membership($schoolB, $user, 'guru01');
        $this->assignRole($schoolA, $membershipA, 'teacher');
        $this->assignRole($schoolB, $membershipB, 'student');

        $resolver = app(TenantResolver::class);
        $contextA = $resolver->resolve('sekolah-a.platform.test');
        $contextB = $resolver->resolve('sekolah-b.platform.test');
        $checker = app(PermissionChecker::class);
        $userModel = User::query()->findOrFail($user);

        $this->assertNotNull($contextA);
        $this->assertNotNull($contextB);
        $this->assertTrue($checker->allows($userModel, $contextA, $membershipA, 'learning.create'));
        $this->assertFalse($checker->allows($userModel, $contextB, $membershipB, 'learning.create'));
        $this->assertTrue($checker->allows($userModel, $contextB, $membershipB, 'grades.view_own_published'));
        $this->assertFalse($checker->allows($userModel, $contextB, $membershipB, 'platform.tenants.view'));
    }

    public function test_central_login_requires_verified_active_platform_staff(): void
    {
        $teacher = $this->user('Teacher', 'Password!234', 'teacher@example.test');

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'teacher@example.test',
            'password' => 'Password!234',
        ])->assertSessionHasErrors('credentials');

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'auth.login.failed',
        ]);

        $staff = $this->user('Platform Staff', 'Platform!234', 'staff@example.test');
        DB::table('platform_staff')->insert([
            'user_id' => $staff,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'staff@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');

        $this->get('http://admin.platform.test/dashboard')->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $staff,
            'action' => 'auth.login.succeeded',
        ]);
        $this->assertNotSame($teacher, $staff);
    }

    public function test_central_dashboard_rejects_unmapped_platform_permission(): void
    {
        $staff = $this->user('Platform Staff', 'Platform!234', 'staff@example.test');
        DB::table('platform_staff')->insert([
            'user_id' => $staff,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'staff@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');

        config(['saas.platform_role_permissions.super_admin' => []]);

        $this->get('http://admin.platform.test/dashboard')->assertForbidden();
    }

    public function test_platform_permission_mapping_is_separate_from_tenant_roles(): void
    {
        $staff = $this->user('Platform Staff', 'Platform!234', 'staff@example.test');
        DB::table('platform_staff')->insert([
            'user_id' => $staff,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $checker = app(PlatformPermissionChecker::class);
        $user = User::query()->findOrFail($staff);

        foreach (config('saas.platform_role_permissions.super_admin') as $permission) {
            $this->assertTrue($checker->allows($user, $permission));
        }

        $this->assertFalse($checker->allows($user, 'school.view'));
        $this->assertFalse($checker->allows($user, 'platform.permission.not_defined'));
    }

    public function test_central_mfa_enrollment_and_single_use_recovery_code(): void
    {
        config(['saas.central_mfa_required' => true]);
        $staff = $this->user('Platform Staff', 'Platform!234', 'staff@example.test');
        DB::table('platform_staff')->insert([
            'user_id' => $staff,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'staff@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/mfa/setup');

        $setup = $this->get('http://admin.platform.test/mfa/setup')->assertOk();
        preg_match('/Secret manual: <code>([A-Z2-7]+)<\/code>/', $setup->getContent(), $secretMatch);
        $this->assertNotEmpty($secretMatch[1] ?? null);

        $code = app(CentralMfaService::class)->currentCode($secretMatch[1]);
        $this->postWithCsrf('http://admin.platform.test/mfa/setup', ['code' => $code])
            ->assertRedirect('http://admin.platform.test/dashboard');

        $dashboard = $this->get('http://admin.platform.test/dashboard')->assertOk();
        preg_match('/<li><code>([A-F0-9]+)<\/code>/', $dashboard->getContent(), $recoveryMatch);
        $this->assertNotEmpty($recoveryMatch[1] ?? null);

        $this->postWithCsrf('http://admin.platform.test/logout')->assertRedirect('http://admin.platform.test/login');
        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'staff@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/mfa/challenge');

        $this->postWithCsrf('http://admin.platform.test/mfa/challenge', ['code' => $recoveryMatch[1]])
            ->assertRedirect('http://admin.platform.test/dashboard');

        $remainingCodes = json_decode(
            (string) DB::table('platform_mfa_credentials')->where('user_id', $staff)->value('recovery_code_hashes'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertCount(7, $remainingCodes);
    }

    public function test_central_mfa_reset_requires_specific_platform_permission(): void
    {
        $actor = $this->user('Platform Operator', 'Platform!234', 'operator@example.test');
        $target = $this->user('Platform Target', 'Target!234', 'target@example.test');
        DB::table('platform_staff')->insert([
            ['user_id' => $actor, 'role' => 'super_admin', 'status' => 'active'],
            ['user_id' => $target, 'role' => 'super_admin', 'status' => 'active'],
        ]);

        $secret = app(CentralMfaService::class)->newSecret();
        $this->assertIsArray(app(CentralMfaService::class)->enroll(
            $target,
            $secret,
            app(CentralMfaService::class)->currentCode($secret),
        ));

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'operator@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');

        config(['saas.platform_role_permissions.super_admin' => ['platform.tenants.view']]);

        $targetPublicUlid = (string) DB::table('users')->where('id', $target)->value('public_ulid');
        $this->postWithCsrf('http://admin.platform.test/security/mfa/'.$targetPublicUlid.'/reset', [
            'reason' => 'device_lost',
        ])->assertForbidden();

        $this->assertDatabaseHas('platform_mfa_credentials', ['user_id' => $target]);
        $this->assertDatabaseHas('users', ['id' => $target, 'auth_version' => 1]);
    }

    public function test_central_mfa_reset_is_audited_and_requires_reenrollment(): void
    {
        $actor = $this->user('Platform Operator', 'Platform!234', 'operator@example.test');
        $target = $this->user('Platform Target', 'Target!234', 'target@example.test');
        DB::table('platform_staff')->insert([
            ['user_id' => $actor, 'role' => 'super_admin', 'status' => 'active'],
            ['user_id' => $target, 'role' => 'super_admin', 'status' => 'active'],
        ]);

        $secret = app(CentralMfaService::class)->newSecret();
        $this->assertIsArray(app(CentralMfaService::class)->enroll(
            $target,
            $secret,
            app(CentralMfaService::class)->currentCode($secret),
        ));

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'operator@example.test',
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');

        $targetPublicUlid = (string) DB::table('users')->where('id', $target)->value('public_ulid');
        $this->postWithCsrf('http://admin.platform.test/security/mfa/'.$targetPublicUlid.'/reset', [
            'reason' => 'device_lost',
        ])->assertRedirect('http://admin.platform.test/dashboard');

        $this->assertDatabaseMissing('platform_mfa_credentials', ['user_id' => $target]);
        $this->assertDatabaseHas('users', ['id' => $target, 'auth_version' => 2]);

        $audit = DB::table('platform_audit_logs')
            ->where('action', 'auth.mfa.reset')
            ->where('actor_user_id', $actor)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertNotSame($targetPublicUlid, $audit->target_id);
        $this->assertSame('device_lost', json_decode((string) $audit->metadata_json, true)['reason']);

        config(['saas.central_mfa_required' => true]);

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'target@example.test',
            'password' => 'Target!234',
        ])->assertRedirect('http://admin.platform.test/mfa/setup');
    }

    public function test_session_binding_rejects_cross_tenant_and_cross_area_access(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $schoolB = $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');
        $user = $this->user('Guru Multi Sekolah', 'Password!234', 'guru@example.test');
        $membershipA = $this->membership($schoolA, $user, 'guru01');
        $this->membership($schoolB, $user, 'guru01');

        $session = $this->tenantSession('sekolah-a.platform.test', $schoolA, $membershipA, $user);

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->get('http://sekolah-b.platform.test/dashboard')
            ->assertRedirect('/login');

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->get('http://admin.platform.test/dashboard')
            ->assertRedirect('http://admin.platform.test/login');
    }

    public function test_auth_version_and_membership_status_revoke_existing_session(): void
    {
        $school = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $user = $this->user('Guru', 'Password!234');
        $membership = $this->membership($school, $user, 'guru01');
        $this->assignRole($school, $membership, 'teacher');
        $session = $this->tenantSession('sekolah-a.platform.test', $school, $membership, $user);

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->get('http://sekolah-a.platform.test/dashboard')
            ->assertOk();

        DB::table('tenant_memberships')->where('id', $membership)->update(['auth_version' => 2]);

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->get('http://sekolah-a.platform.test/dashboard')
            ->assertRedirect('/login');
    }

    public function test_logout_is_post_only_requires_csrf_and_invalidates_session(): void
    {
        $school = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $user = $this->user('Guru', 'Password!234');
        $membership = $this->membership($school, $user, 'guru01');
        $session = $this->tenantSession('sekolah-a.platform.test', $school, $membership, $user);

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->get('http://sekolah-a.platform.test/logout')
            ->assertStatus(405);

        $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->post('http://sekolah-a.platform.test/logout')
            ->assertStatus(419);

        $response = $this->actingAs(User::query()->findOrFail($user), 'web')
            ->withSession($session)
            ->postWithCsrf('http://sekolah-a.platform.test/logout');

        $response->assertRedirect('/login');
        $this->assertFalse(auth('web')->check());
        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $school,
            'actor_user_id' => $user,
            'action' => 'auth.logout',
        ]);
    }

    public function test_login_returns_429_after_identifier_abuse(): void
    {
        $this->tenantWithDomain('sekolah-rate', 'sekolah-rate.platform.test');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postWithCsrf('http://sekolah-rate.platform.test/login', [
                'username' => 'unknown-rate-user',
                'password' => 'Wrong!234',
            ])->assertStatus(302);
        }

        $this->postWithCsrf('http://sekolah-rate.platform.test/login', [
            'username' => 'unknown-rate-user',
            'password' => 'Wrong!234',
        ])->assertStatus(429);
    }

    public function test_unknown_host_cannot_fallback_to_login(): void
    {
        $this->get('http://unknown.platform.test/login')->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSession(string $host, int $tenantId, int $membershipId, int $userId): array
    {
        return [
            AuthSession::AREA => 'tenant',
            AuthSession::HOST => $host,
            AuthSession::USER_ID => $userId,
            AuthSession::TENANT_ID => $tenantId,
            AuthSession::MEMBERSHIP_ID => $membershipId,
            AuthSession::USER_AUTH_VERSION => 1,
            AuthSession::MEMBERSHIP_AUTH_VERSION => 1,
            AuthSession::MFA_VERIFIED => false,
        ];
    }

    private function tenantWithDomain(string $slug, string $hostname): int
    {
        $tenantId = (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'slug' => $slug,
            'name' => 'Sekolah Dummy '.$slug,
            'status' => 'active',
        ]);

        DB::table('tenant_domains')->insert([
            'tenant_id' => $tenantId,
            'hostname' => $hostname,
            'kind' => 'default',
            'status' => 'active',
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        return $tenantId;
    }

    private function user(
        string $name,
        string $password,
        ?string $email = null,
        string $status = 'active',
    ): int {
        return (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'email_normalized' => $email === null ? null : mb_strtolower($email, 'UTF-8'),
            'email_verified_at' => $email === null ? null : now(),
            'password' => Hash::make($password),
            'status' => $status,
            'auth_version' => 1,
        ]);
    }

    private function membership(int $tenantId, int $userId, string $username): int
    {
        return (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'local_username' => $username,
            'local_username_normalized' => mb_strtolower($username, 'UTF-8'),
            'display_name' => 'User Sintetis',
            'status' => 'active',
            'auth_version' => 1,
        ]);
    }

    private function assignRole(int $tenantId, int $membershipId, string $role): void
    {
        DB::table('membership_roles')->insert([
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'role_id' => DB::table('roles')->where('key', $role)->value('id'),
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function postWithCsrf(string $url, array $data = []): TestResponse
    {
        $token = 'm1-test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($url, [...$data, '_token' => $token]);
    }
}

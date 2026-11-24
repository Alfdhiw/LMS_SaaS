<?php

namespace Tests\Runtime;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Runs only through the isolated Docker harness, never against the normal test server. */
final class M1RuntimeRoleIntegrationTest extends TestCase
{
    private const CSRF = 'synthetic-runtime-csrf';

    private const RUNTIME_PASSWORD = 'synthetic-runtime-only';

    public function test_rejects_an_incorrect_isolation_marker_before_any_migration(): void
    {
        $original = getenv('M1_RUNTIME_TEST_MARKER');
        if (! is_string($original) || ! preg_match('/^[a-f0-9]{32}$/D', $original)) {
            throw new \RuntimeException('The isolated test harness marker is missing.');
        }
        $wrong = str_repeat($original[0] === 'a' ? 'b' : 'a', 32);
        putenv('M1_RUNTIME_TEST_MARKER='.$wrong);
        try {
            $this->assertIsolatedEmptyDatabase();
            $this->fail('A mismatched isolation marker must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Refusing runtime integration test outside a fresh, marked database.', $exception->getMessage());
        } finally {
            putenv('M1_RUNTIME_TEST_MARKER='.$original);
        }
        $this->assertIsolatedEmptyDatabase();
    }

    public function test_real_runtime_login_provisioning_audit_and_tenant_isolation(): void
    {
        $this->assertIsolatedEmptyDatabase();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        $this->seed(RolePermissionSeeder::class);

        // These fixtures are created by the disposable test owner before switching connections.
        $schoolA = $this->tenant('runtime-a');
        $schoolB = $this->tenant('runtime-b');
        $userA = $this->user('Synthetic School A', 'Synthetic-A!234');
        $userB = $this->user('Synthetic School B', 'Synthetic-B!234');
        $membershipA = $this->membership($schoolA, $userA);
        $membershipB = $this->membership($schoolB, $userB);
        $staff = $this->user('Synthetic Platform Staff', 'Synthetic-Staff!234', 'runtime-staff@example.test');
        DB::table('platform_staff')->insert(['user_id' => $staff, 'role' => 'super_admin', 'status' => 'active']);

        $this->prepareRuntimeRole();
        config([
            'database.connections.pgsql.username' => 'lms_runtime',
            'database.connections.pgsql.password' => self::RUNTIME_PASSWORD,
            // MFA is tested elsewhere; disabling it here is limited to this in-memory test app.
            'saas.central_mfa_required' => false,
        ]);
        DB::purge('pgsql');
        $role = DB::selectOne('select current_user as current_role, session_user as session_role, current_database() as database_name');
        $this->assertSame('lms_runtime', $role->current_role);
        $this->assertSame('lms_runtime', $role->session_role);
        $this->assertSame('lms_saas_test', $role->database_name);
        $this->assertSame(0, (int) DB::selectOne("select pg_has_role(current_user, 'lms_user', 'MEMBER')::int as member")->member);

        // Passwords and identities here are synthetic and exist only in the temporary database.
        $this->postWithCsrf('http://runtime-b.platform.test/login', [
            'username' => 'admin', 'password' => 'Synthetic-A!234',
        ])->assertSessionHasErrors('credentials');
        $this->postWithCsrf('http://runtime-a.platform.test/login', [
            'username' => 'admin', 'password' => 'Synthetic-A!234',
        ])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $schoolA, 'actor_user_id' => $userA,
            'actor_membership_id' => $membershipA, 'action' => 'auth.login.succeeded',
        ]);
        $this->get('http://runtime-a.platform.test/dashboard')->assertOk();
        $this->get('http://runtime-b.platform.test/dashboard')->assertRedirect('/login');
        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $membershipB, 'tenant_id' => $schoolB, 'user_id' => $userB, 'status' => 'active', 'auth_version' => 1,
        ]);
        $this->get('http://runtime-b.platform.test/audit')->assertNotFound();
        $this->postWithCsrf('http://runtime-b.platform.test/login', [
            'username' => 'admin', 'password' => 'Synthetic-B!234',
        ])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $schoolB, 'actor_user_id' => $userB,
            'actor_membership_id' => $membershipB, 'action' => 'auth.login.succeeded',
        ]);
        $this->get('http://runtime-b.platform.test/dashboard')->assertOk();
        $this->get('http://runtime-a.platform.test/dashboard')->assertRedirect('/login');

        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => 'runtime-staff@example.test', 'password' => 'Synthetic-Staff!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');
        $this->assertDatabaseHas('platform_audit_logs', ['actor_user_id' => $staff, 'action' => 'auth.login.succeeded']);

        Mail::fake();
        $payload = [
            'school_name' => 'Sekolah Runtime Sintetis', 'slug' => 'runtime-provisioned',
            'timezone' => 'Asia/Jakarta', 'admin_name' => 'Admin Sintetis',
            'admin_email' => 'runtime-admin@example.test', 'admin_username' => 'bootstrap-admin',
        ];
        $data = $this->postCentral('/tenants', $payload, 'runtime-provisioning-key')->assertCreated()->json('data');
        $tenantId = (int) DB::table('tenants')->where('public_ulid', $data['tenant_public_ulid'])->value('id');
        $this->assertGreaterThan(0, $tenantId);
        $this->assertDatabaseHas('platform_audit_logs', [
            'tenant_id' => $tenantId, 'actor_user_id' => $staff, 'action' => 'tenant.create.succeeded',
        ]);
        $this->postCentral('/tenants', $payload, 'runtime-provisioning-key')->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);
        $this->assertSame(1, DB::table('tenants')->where('slug', 'runtime-provisioned')->count());

        $activation = '/activate/'.$data['invitation_public_ulid'].'/'.$data['invitation_token'];
        $this->get('http://runtime-b.platform.test'.$activation)->assertNotFound();
        $this->postWithCsrf('http://runtime-provisioned.platform.test'.$activation, [
            'password' => 'Synthetic-Admin!234', 'password_confirmation' => 'Synthetic-Admin!234',
        ])->assertRedirect('/login');
        $this->assertDatabaseHas('tenant_audit_logs', ['tenant_id' => $tenantId, 'action' => 'tenant.admin_activated']);
        $this->assertDatabaseHas('account_invitations', ['public_ulid' => $data['invitation_public_ulid'], 'status' => 'consumed']);
        $this->postWithCsrf('http://runtime-provisioned.platform.test'.$activation, [
            'password' => 'Synthetic-Admin!234', 'password_confirmation' => 'Synthetic-Admin!234',
        ])->assertNotFound();

        // Existing active-school credentials exercise the new path as the non-owner role.
        $this->postWithCsrf('http://runtime-a.platform.test/login', [
            'username' => 'admin', 'password' => 'Synthetic-A!234',
        ])->assertRedirect('/dashboard');
        $this->postWithCsrf('http://runtime-a.platform.test/password/change', [
            'current_password' => 'Synthetic-A!234', 'password' => 'Synthetic-Changed!234',
            'password_confirmation' => 'Synthetic-Changed!234',
        ])->assertRedirect('/login');
        $this->assertDatabaseHas('users', ['id' => $userA, 'auth_version' => 2]);
        $this->assertTrue(Hash::check('Synthetic-Changed!234', DB::table('users')->where('id', $userA)->value('password')));
        $this->assertDatabaseHas('tenant_audit_logs', ['tenant_id' => $schoolA, 'action' => 'auth.password.changed']);
        $this->assertDatabaseHas('users', ['id' => $userB, 'auth_version' => 1]);

        $this->assertAuditMutationsDenied();
    }

    private function assertIsolatedEmptyDatabase(): void
    {
        $marker = getenv('M1_RUNTIME_TEST_MARKER');
        if (getenv('M1_RUNTIME_INTEGRATION') !== '1' || ! is_string($marker)
            || ! preg_match('/^[a-f0-9]{32}$/D', $marker) || ! app()->environment('testing')) {
            throw new \RuntimeException('Refusing runtime integration test without an isolated test marker.');
        }
        $server = DB::selectOne("select current_database() as name, current_user as owner, current_setting('m1.runtime_test_marker', true) as marker");
        if ($server->name !== 'lms_saas_test' || $server->owner !== 'lms_user' || $server->marker !== $marker
            || DB::selectOne("select count(*) as total from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind in ('r','p','v','m','S')")->total != 0
            || DB::selectOne("select count(*) as total from pg_roles where rolname='lms_runtime'")->total != 0) {
            throw new \RuntimeException('Refusing runtime integration test outside a fresh, marked database.');
        }
    }

    private function prepareRuntimeRole(): void
    {
        $sql = file_get_contents(base_path('database/security/m1_audit_runtime_grants.sql'));
        $guard = "current_database() <> 'lms_saas'";
        if (! is_string($sql) || substr_count($sql, $guard) !== 1) {
            throw new \RuntimeException('Audit grant source does not match the isolated test contract.');
        }
        $sql = preg_replace('/^\\\\set ON_ERROR_STOP on\R/', '', $sql, 1, $count);
        if ($sql === null || $count !== 1) {
            throw new \RuntimeException('Audit grant source is missing its psql guard.');
        }
        DB::statement("CREATE ROLE lms_runtime LOGIN PASSWORD 'synthetic-runtime-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
        $sql = str_replace($guard, "current_database() <> 'lms_saas_test'", $sql);
        DB::unprepared($sql);
    }

    private function assertAuditMutationsDenied(): void
    {
        foreach (['platform_audit_logs', 'tenant_audit_logs'] as $table) {
            foreach (["UPDATE $table SET id = id WHERE false", "DELETE FROM $table WHERE false"] as $query) {
                try {
                    DB::statement($query);
                    $this->fail('Runtime unexpectedly modified or was allowed to modify an audit table.');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', (string) $exception->getCode());
                }
            }
            $privileges = DB::selectOne('select has_table_privilege(current_user, ?, ?)::int as allowed', ["public.$table", 'TRUNCATE']);
            $this->assertSame(0, (int) $privileges->allowed);
        }
    }

    private function tenant(string $slug): int
    {
        $id = (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'slug' => $slug, 'name' => 'Sekolah Sintetis '.$slug, 'status' => 'active',
        ]);
        DB::table('tenant_domains')->insert([
            'tenant_id' => $id, 'hostname' => $slug.'.platform.test', 'kind' => 'default',
            'status' => 'active', 'verified_at' => now(), 'is_primary' => true,
        ]);

        return $id;
    }

    private function user(string $name, string $password, ?string $email = null): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'name' => $name, 'email' => $email,
            'email_normalized' => $email, 'email_verified_at' => $email === null ? null : now(),
            'password' => Hash::make($password), 'status' => 'active', 'auth_version' => 1,
        ]);
    }

    private function membership(int $tenantId, int $userId): int
    {
        $id = (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'local_username' => 'admin',
            'local_username_normalized' => 'admin', 'display_name' => 'Admin Sintetis', 'status' => 'active', 'auth_version' => 1,
        ]);
        DB::table('membership_roles')->insert([
            'tenant_id' => $tenantId, 'membership_id' => $id,
            'role_id' => DB::table('roles')->where('key', 'school_admin')->value('id'),
        ]);

        return $id;
    }

    private function postWithCsrf(string $url, array $data): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF])->post($url, [...$data, '_token' => self::CSRF]);
    }

    private function postCentral(string $path, array $data, string $key): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF])->withHeader('Accept', 'application/json')
            ->withHeader('Idempotency-Key', $key)->post('http://admin.platform.test'.$path, [...$data, '_token' => self::CSRF]);
    }
}

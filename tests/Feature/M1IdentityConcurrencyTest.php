<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class M1IdentityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_self_email_reset_consumes_exactly_once(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $host] = $this->tenant();
        $user = $this->user('race-reset@example.test');
        $this->member($tenant, $user, 'teacher', 'teacher');
        $id = (string) Str::ulid();
        $token = bin2hex(random_bytes(32));
        DB::table('account_recovery_requests')->insert([
            'public_ulid' => $id, 'user_id' => $user, 'scope' => 'self_email', 'purpose' => 'password_reset',
            'status' => 'token_issued', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
        ]);
        $this->race('users', $user, ['action' => 'recovery', 'host' => $host, 'id' => $id, 'token' => $token]);
        $this->assertSame(2, (int) DB::table('users')->where('id', $user)->value('auth_version'));
        $this->assertSame('consumed', DB::table('account_recovery_requests')->where('public_ulid', $id)->value('status'));
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('action', 'recovery.self_email.consumed')->count());
    }

    public function test_parallel_existing_account_join_creates_one_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $host] = $this->tenant();
        $admin = $this->user('race-admin@example.test');
        $adminMember = $this->member($tenant, $admin, 'admin', 'school_admin');
        $user = $this->user('race-join@example.test');
        $id = (string) Str::ulid();
        $token = bin2hex(random_bytes(32));
        DB::table('account_invitations')->insert([
            'public_ulid' => $id, 'tenant_id' => $tenant, 'purpose' => 'join_existing', 'status' => 'pending',
            'proposed_local_username' => 'teacher', 'proposed_local_username_normalized' => 'teacher',
            'proposed_role_id' => DB::table('roles')->where('key', 'teacher')->value('id'),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(1), 'created_by_membership_id' => $adminMember,
        ]);
        $this->race('tenants', $tenant, ['action' => 'join', 'host' => $host, 'id' => $id, 'token' => $token, 'email' => 'race-join@example.test']);
        $this->assertSame(1, DB::table('tenant_memberships')->where('tenant_id', $tenant)->where('user_id', $user)->count());
        $this->assertSame('consumed', DB::table('account_invitations')->where('public_ulid', $id)->value('status'));
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('action', 'membership.join_invitation.consumed')->count());
    }

    public function test_parallel_school_recovery_consumes_exactly_once(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $host] = $this->tenant();
        $user = $this->user('race-school@example.test');
        DB::table('users')->where('id', $user)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($tenant, $user, 'student', 'student');
        $id = (string) Str::ulid();
        $token = bin2hex(random_bytes(32));
        DB::table('account_recovery_requests')->insert([
            'public_ulid' => $id, 'tenant_id' => $tenant, 'user_id' => $user,
            'scope' => 'school_assisted', 'purpose' => 'password_reset', 'status' => 'token_issued',
            'token_hash' => hash('sha256', $token), 'verified_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        $this->race('users', $user, ['action' => 'school-recovery', 'host' => $host, 'id' => $id, 'token' => $token]);
        $this->assertSame(2, (int) DB::table('users')->where('id', $user)->value('auth_version'));
        $this->assertSame('consumed', DB::table('account_recovery_requests')->where('public_ulid', $id)->value('status'));
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('action', 'recovery.school_assisted.consumed')->count());
    }

    public function test_parallel_school_approvals_share_redis_and_only_one_succeeds(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $host] = $this->tenant();
        $admin = $this->user('race-school-admin@example.test');
        $membership = $this->member($tenant, $admin, 'admin', 'school_admin');
        $student = $this->user('race-school-student@example.test');
        DB::table('users')->where('id', $student)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($tenant, $student, 'student', 'student');
        $id = (string) Str::ulid();
        $commitment = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('account_recovery_requests')->insert([
            'public_ulid' => $id, 'tenant_id' => $tenant, 'user_id' => $student, 'scope' => 'school_assisted',
            'purpose' => 'password_reset', 'status' => 'requested', 'expires_at' => now()->addMinutes(30),
        ]);
        Cache::store('redis')->put('tenant:'.$tenant.':school-recovery:'.$id, $commitment, now()->addMinutes(30));
        $this->race('tenants', $tenant, [
            'action' => 'school-approve', 'host' => $host, 'id' => $id, 'actor' => $admin, 'membership' => $membership,
        ]);
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $id, 'status' => 'token_issued', 'token_hash' => $commitment]);
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('action', 'recovery.school_assisted.approved')->count());
        $this->assertNull(Cache::store('redis')->get('tenant:'.$tenant.':school-recovery:'.$id));
    }

    private function race(string $table, int $id, array $payload): void
    {
        $db = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'CACHE_STORE' => $payload['action'] === 'school-approve' ? 'redis' : 'array',
        ];
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, 'tests/Support/m1_identity_concurrency_worker.php'], base_path(), $env, json_encode($payload, JSON_THROW_ON_ERROR), 60);
                $process->start();
                $workers[] = $process;
            }
            $blocked = 0;
            for ($i = 0; $i < 300; $i++) {
                // pg_stat_activity snapshots are cached for the current transaction.
                DB::statement('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::table('pg_stat_activity')->where('application_name', 'm1_pr5_identity_race')->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                if (! $workers[0]->isRunning() || ! $workers[1]->isRunning()) {
                    break;
                }
                usleep(100000);
            }
            $details = implode(' | ', array_map(fn (Process $worker): string => $worker->getErrorOutput().' '.$worker->getOutput(), $workers));
            $this->assertSame(2, $blocked, 'Both independent processes must reach the contested row lock: '.$details);
        } finally {
            DB::rollBack();
        }
        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $outcomes[] = trim($worker->getOutput());
        }
        sort($outcomes);
        $this->assertSame(['consumed', 'rejected:404'], $outcomes);
    }

    private function tenant(): array
    {
        $slug = 'race-'.Str::lower(Str::random(8));
        $id = DB::table('tenants')->insertGetId(['public_ulid' => (string) Str::ulid(), 'slug' => $slug, 'name' => 'Dummy', 'status' => 'active']);
        $host = $slug.'.platform.test';
        DB::table('tenant_domains')->insert(['tenant_id' => $id, 'hostname' => $host, 'kind' => 'default', 'status' => 'active', 'verified_at' => now(), 'is_primary' => true]);

        return [(int) $id, $host];
    }

    private function user(string $email): int
    {
        return (int) DB::table('users')->insertGetId(['public_ulid' => (string) Str::ulid(), 'name' => 'Dummy', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now(), 'password' => Hash::make('ValidPassword!234'), 'status' => 'active', 'auth_version' => 1]);
    }

    private function member(int $tenant, int $user, string $username, string $role): int
    {
        $id = DB::table('tenant_memberships')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'local_username' => $username, 'local_username_normalized' => $username, 'display_name' => 'Dummy', 'status' => 'active']);
        DB::table('membership_roles')->insert(['tenant_id' => $tenant, 'membership_id' => $id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);

        return (int) $id;
    }
}

<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Auth\AuthSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait M1Fixtures
{
    protected function fixtureTenant(string $slug): int
    {
        $id = (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'slug' => $slug, 'name' => 'Sekolah Sintetis '.$slug, 'status' => 'active',
        ]);
        DB::table('tenant_domains')->insert([
            'tenant_id' => $id, 'hostname' => $slug.'.platform.test', 'kind' => 'default', 'status' => 'active',
            'verified_at' => now(), 'is_primary' => true,
        ]);

        return $id;
    }

    protected function fixtureUser(string $email = 'synthetic-user@example.test'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'name' => 'Akun Sintetis', 'email' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password' => Hash::make('OriginalPassword!234'), 'status' => 'active', 'auth_version' => 1,
        ]);
    }

    protected function fixtureMember(int $tenant, int $user, string $role = 'school_admin'): int
    {
        $id = (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'local_username' => 'member'.$user,
            'local_username_normalized' => 'member'.$user, 'display_name' => 'Anggota Sintetis', 'status' => 'active', 'auth_version' => 1,
        ]);
        DB::table('membership_roles')->insert([
            'tenant_id' => $tenant, 'membership_id' => $id, 'role_id' => DB::table('roles')->where('key', $role)->value('id'),
        ]);

        return $id;
    }

    protected function fixtureSession(int $user, ?int $tenant = null, ?int $member = null, ?string $host = null): array
    {
        return [
            AuthSession::AREA => $tenant === null ? 'central' : 'tenant',
            AuthSession::HOST => $host ?? 'admin.platform.test', AuthSession::USER_ID => $user,
            AuthSession::TENANT_ID => $tenant, AuthSession::MEMBERSHIP_ID => $member,
            AuthSession::USER_AUTH_VERSION => (int) DB::table('users')->where('id', $user)->value('auth_version'),
            AuthSession::MEMBERSHIP_AUTH_VERSION => $member === null ? null : (int) DB::table('tenant_memberships')->where('id', $member)->value('auth_version'),
            AuthSession::MFA_VERIFIED => true,
        ];
    }

    protected function useFixtureSession(int $user, array $session): void
    {
        $this->actingAs(User::query()->findOrFail($user), 'web')->withSession($session);
    }

    protected function fixturePost(string $url, array $data = []): TestResponse
    {
        return $this->withSession(['_token' => 'csrf-synthetic-m1'])->withHeader('Accept', 'application/json')
            ->post($url, [...$data, '_token' => 'csrf-synthetic-m1']);
    }
}

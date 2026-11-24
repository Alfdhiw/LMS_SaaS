<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\M1Fixtures;
use Tests\TestCase;

final class M1PasswordChangeTest extends TestCase
{
    use M1Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['saas.central_mfa_required' => false]);
    }

    public function test_own_password_change_revokes_tenant_and_central_sessions_without_changing_other_users(): void
    {
        $a = $this->fixtureTenant('password-a');
        $b = $this->fixtureTenant('password-b');
        $user = $this->fixtureUser();
        $other = $this->fixtureUser('other-synthetic@example.test');
        $memberA = $this->fixtureMember($a, $user);
        $memberB = $this->fixtureMember($b, $user, 'teacher');
        DB::table('platform_staff')->insert(['user_id' => $user, 'role' => 'super_admin', 'status' => 'active']);
        DB::table('account_recovery_requests')->insert([
            'public_ulid' => (string) Str::ulid(), 'user_id' => $user, 'scope' => 'self_email',
            'purpose' => 'password_reset', 'status' => 'requested', 'expires_at' => now()->addMinutes(30),
        ]);
        $oldA = $this->fixtureSession($user, $a, $memberA, 'password-a.platform.test');
        $oldB = $this->fixtureSession($user, $b, $memberB, 'password-b.platform.test');
        $oldCentral = $this->fixtureSession($user);
        $otherBefore = (array) DB::table('users')->find($other);
        $this->useFixtureSession($user, $oldA);
        $form = $this->get('http://password-a.platform.test/password/change')->assertOk();
        $this->assertStringContainsString('no-store', $form->headers->get('Cache-Control'));
        $this->fixturePost('http://password-a.platform.test/password/change', $this->passwords())->assertRedirect('/login');
        $this->assertFalse(auth('web')->check());
        $this->assertTrue(Hash::check('ChangedPassword!234', DB::table('users')->where('id', $user)->value('password')));
        $this->assertDatabaseHas('users', ['id' => $user, 'auth_version' => 2, 'must_change_password' => false]);
        $this->assertDatabaseHas('tenant_memberships', ['id' => $memberA, 'auth_version' => 1]);
        $this->assertDatabaseHas('tenant_memberships', ['id' => $memberB, 'auth_version' => 1]);
        $this->assertDatabaseHas('account_recovery_requests', ['user_id' => $user, 'status' => 'revoked']);
        $this->assertSame($otherBefore, (array) DB::table('users')->find($other));
        foreach ([[$oldA, 'password-a.platform.test'], [$oldB, 'password-b.platform.test'], [$oldCentral, 'admin.platform.test']] as [$session, $host]) {
            $this->useFixtureSession($user, $session);
            $this->get('http://'.$host.'/dashboard')->assertRedirect();
            $this->assertFalse(auth('web')->check());
        }
        $audit = DB::table('tenant_audit_logs')->where('action', 'auth.password.changed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($a, (int) $audit->tenant_id);
        $this->assertSame($memberA, (int) $audit->actor_membership_id);
        foreach ($this->passwords() as $secret) {
            $this->assertStringNotContainsString($secret, $audit->metadata_json);
        }
    }

    public function test_wrong_current_password_does_not_mutate_or_flash_credentials(): void
    {
        [$user, $host] = $this->signedInTenant();
        $before = (array) DB::table('users')->find($user);
        $this->withSession(['_token' => 'csrf-pw'])->post('http://'.$host.'/password/change', [
            ...$this->passwords(), 'current_password' => 'WrongPassword!234', '_token' => 'csrf-pw',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame($before, (array) DB::table('users')->find($user));
        foreach (['current_password', 'password', 'password_confirmation'] as $key) {
            $this->assertNull(session()->getOldInput($key));
        }
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_target_or_tenant_injection_and_invalid_passwords_leave_database_unchanged(): void
    {
        [$user, $host] = $this->signedInTenant();
        $before = (array) DB::table('users')->find($user);
        foreach ([['user_id' => 999], ['tenant_id' => 999], ['password' => 'short'], ['password_confirmation' => 'Mismatch!234']] as $override) {
            $this->fixturePost('http://'.$host.'/password/change', [...$this->passwords(), ...$override])->assertUnprocessable();
            $this->assertSame($before, (array) DB::table('users')->find($user));
        }
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_forced_change_blocks_other_protected_actions_but_allows_change_and_logout(): void
    {
        [$user, $host] = $this->signedInTenant();
        DB::table('users')->where('id', $user)->update(['must_change_password' => true]);
        $this->get('http://'.$host.'/dashboard')->assertRedirect('/password/change');
        $this->fixturePost('http://'.$host.'/members/invitations', [])->assertStatus(423);
        $this->get('http://'.$host.'/password/change')->assertOk();
        $this->fixturePost('http://'.$host.'/password/change', $this->passwords())->assertRedirect('/login');
        $this->assertDatabaseHas('users', ['id' => $user, 'must_change_password' => false, 'auth_version' => 2]);
    }

    public function test_bcrypt_byte_limit_rejects_unicode_truncation_without_mutation(): void
    {
        config(['hashing.driver' => 'bcrypt']);
        [$user, $host] = $this->signedInTenant();
        $before = (array) DB::table('users')->find($user);
        $long = str_repeat('é', 40);
        $this->fixturePost('http://'.$host.'/password/change', [
            ...$this->passwords(), 'password' => $long, 'password_confirmation' => $long,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($before, (array) DB::table('users')->find($user));
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_equivalent_legacy_bcrypt_password_cannot_be_reused_with_a_different_suffix(): void
    {
        config(['hashing.driver' => 'bcrypt']);
        [$user, $host] = $this->signedInTenant();
        $prefix = str_repeat('a', 72);
        DB::table('users')->where('id', $user)->update(['password' => Hash::make($prefix)]);
        $before = (array) DB::table('users')->find($user);
        $this->fixturePost('http://'.$host.'/password/change', [
            'current_password' => $prefix.'legacy-suffix', 'password' => $prefix, 'password_confirmation' => $prefix,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($before, (array) DB::table('users')->find($user));
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_central_change_requires_mfa_and_creates_only_platform_audit(): void
    {
        $user = $this->fixtureUser();
        DB::table('platform_staff')->insert(['user_id' => $user, 'role' => 'super_admin', 'status' => 'active']);
        config(['saas.central_mfa_required' => true]);
        $session = $this->fixtureSession($user);
        $this->useFixtureSession($user, [...$session, 'auth.mfa_verified' => false]);
        $this->fixturePost('http://admin.platform.test/password/change', $this->passwords())->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user, 'auth_version' => 1]);
        $this->useFixtureSession($user, $session);
        $this->fixturePost('http://admin.platform.test/password/change', $this->passwords())->assertRedirect('/login');
        $this->assertDatabaseHas('users', ['id' => $user, 'auth_version' => 2]);
        $this->assertDatabaseHas('platform_audit_logs', ['actor_user_id' => $user, 'action' => 'auth.password.changed']);
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_unknown_host_and_cross_host_session_cannot_change_password(): void
    {
        [$user] = $this->signedInTenant();
        $this->fixtureTenant('password-outside');
        $before = (array) DB::table('users')->find($user);
        $this->fixturePost('http://password-outside.platform.test/password/change', $this->passwords())->assertRedirect('/login');
        $this->fixturePost('http://unknown.platform.test/password/change', $this->passwords())->assertNotFound();
        $this->assertSame($before, (array) DB::table('users')->find($user));
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_password_change_requires_real_csrf_and_rejects_http_in_production(): void
    {
        [$user, $host] = $this->signedInTenant();
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $this->post('http://'.$host.'/password/change', $this->passwords())->assertStatus(419);
        $this->app->instance('env', 'production');
        $this->fixturePost('http://'.$host.'/password/change', $this->passwords())->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $user, 'auth_version' => 1]);
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_rate_limit_prevents_further_password_guesses_without_mutation(): void
    {
        [$user, $host] = $this->signedInTenant();
        for ($i = 0; $i < 5; $i++) {
            $this->fixturePost('http://'.$host.'/password/change', [...$this->passwords(), 'current_password' => 'WrongPassword!234'])->assertUnprocessable();
        }
        $this->fixturePost('http://'.$host.'/password/change', $this->passwords())->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $user, 'auth_version' => 1]);
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_password_and_recovery_changes(): void
    {
        [$user, $host] = $this->signedInTenant();
        DB::table('account_recovery_requests')->insert([
            'public_ulid' => (string) Str::ulid(), 'user_id' => $user, 'scope' => 'self_email',
            'purpose' => 'password_reset', 'status' => 'requested', 'expires_at' => now()->addMinutes(30),
        ]);
        $before = (array) DB::table('users')->find($user);
        DB::connection()->beforeExecuting(function ($query): void {
            if (str_starts_with($query, 'insert into "tenant_audit_logs"')) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->fixturePost('http://'.$host.'/password/change', $this->passwords());
            $this->fail('Expected synthetic audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, (array) DB::table('users')->find($user));
        $this->assertDatabaseHas('account_recovery_requests', ['user_id' => $user, 'status' => 'requested']);
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    private function signedInTenant(): array
    {
        $tenant = $this->fixtureTenant('password-home');
        $user = $this->fixtureUser();
        $member = $this->fixtureMember($tenant, $user);
        $host = 'password-home.platform.test';
        $this->useFixtureSession($user, $this->fixtureSession($user, $tenant, $member, $host));

        return [$user, $host];
    }

    private function passwords(): array
    {
        return ['current_password' => 'OriginalPassword!234', 'password' => 'ChangedPassword!234', 'password_confirmation' => 'ChangedPassword!234'];
    }
}

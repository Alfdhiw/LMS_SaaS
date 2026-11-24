<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Support\M1Fixtures;
use Tests\TestCase;

final class M1SecurityClosureTest extends TestCase
{
    use M1Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['saas.central_mfa_required' => false, 'saas.invitation_delivery.mailer' => null]);
    }

    public function test_invitation_insert_failure_rolls_back_all_previously_created_bundle_rows(): void
    {
        $this->centralActor();
        $before = $this->businessCounts();
        $observed = [];
        DB::connection()->beforeExecuting(function ($query) use (&$observed): void {
            if (str_starts_with($query, 'insert into "account_invitations"')) {
                $observed = [
                    'tenant' => DB::table('tenants')->where('slug', 'failure-school')->count(),
                    'domain' => DB::table('tenant_domains')->where('hostname', 'failure-school.platform.test')->count(),
                    'settings' => DB::table('tenant_settings')->count(),
                    'pending_user' => DB::table('users')->where('status', 'pending')->count(),
                    'member' => DB::table('tenant_memberships')->where('status', 'invited')->count(),
                ];
                throw new RuntimeException('Synthetic invitation insert failure');
            }
        });
        $this->provision('failure-school')->assertStatus(500)->assertJsonPath('failure_code', 'provisioning_failed');
        $this->assertSame(['tenant' => 1, 'domain' => 1, 'settings' => 1, 'pending_user' => 1, 'member' => 1], $observed);
        $this->assertSame($before, $this->businessCounts());
        $this->assertDatabaseHas('tenant_provisioning_requests', ['status' => 'failed', 'failure_code' => 'provisioning_failed']);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'tenant.create.failed']);
        $this->assertDatabaseMissing('platform_audit_logs', ['action' => 'tenant.create.succeeded']);
    }

    public function test_mail_transport_failure_is_audited_and_resend_recovers_without_reprovisioning(): void
    {
        $this->centralActor();
        Log::spy();
        $actualMailer = Mail::getFacadeRoot();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SYNTHETIC_SECRET_IN_TRANSPORT_ERROR'));
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $first = $this->provision('mail-failure')->assertCreated()->assertJsonPath('data.invitation_delivery', 'failed')->json('data');
        $this->assertDatabaseHas('tenants', ['slug' => 'mail-failure', 'status' => 'pending']);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'tenant.invitation.delivery_failed']);
        $counts = $this->businessCounts();
        Mail::swap($actualMailer);
        Mail::fake();
        $second = $this->fixturePost('http://admin.platform.test/tenants/'.$first['tenant_public_ulid'].'/invitation/resend', [
            'invitation_public_ulid' => $first['invitation_public_ulid'],
        ])->assertOk()->assertJsonPath('data.invitation_delivery', 'sent')->json('data');
        $this->assertSame($first['tenant_public_ulid'], $second['tenant_public_ulid']);
        $this->assertNotSame($first['invitation_token'], $second['invitation_token']);
        $this->assertSame($counts, $this->businessCounts());
        $this->get('http://mail-failure.platform.test/activate/'.$first['invitation_public_ulid'].'/'.$first['invitation_token'])->assertNotFound();
        $this->get('http://mail-failure.platform.test/activate/'.$second['invitation_public_ulid'].'/'.$second['invitation_token'])->assertOk();
        $audit = DB::table('platform_audit_logs')->pluck('metadata_json')->implode(' ');
        $this->assertStringNotContainsString('SYNTHETIC_SECRET_IN_TRANSPORT_ERROR', $audit);
        $this->assertStringNotContainsString($first['invitation_token'], $audit);
        $this->assertStringNotContainsString($second['invitation_token'], $audit);
        Log::shouldNotHaveReceived('error');
    }

    public function test_domain_collision_and_reserved_central_slug_cannot_leave_a_partial_tenant(): void
    {
        $this->centralActor();
        $other = $this->fixtureTenant('existing-school');
        DB::table('tenant_domains')->insert([
            'tenant_id' => $other, 'hostname' => 'new-school.platform.test', 'kind' => 'default',
            'status' => 'active', 'verified_at' => now(), 'is_primary' => false,
        ]);
        $before = $this->businessCounts();
        $this->provision('new-school')->assertStatus(409)->assertJsonPath('failure_code', 'domain_collision');
        $this->assertSame($before, $this->businessCounts());
        $this->provision('admin')->assertUnprocessable();
        $this->assertSame($before, $this->businessCounts());
        $this->assertDatabaseMissing('tenants', ['slug' => 'admin']);
    }

    public function test_invitation_with_both_creators_is_rejected_and_preserves_existing_rows(): void
    {
        $staff = $this->centralActor();
        $tenant = $this->fixtureTenant('xor-school');
        $member = $this->fixtureMember($tenant, $staff);
        $before = $this->businessCounts();
        try {
            DB::transaction(fn () => DB::table('account_invitations')->insert([
                'public_ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'membership_id' => $member,
                'purpose' => 'activate_new', 'token_hash' => hash('sha256', random_bytes(32)), 'status' => 'pending',
                'expires_at' => now()->addHour(), 'created_by_membership_id' => $member, 'created_by_platform_user_id' => $staff,
            ]));
            $this->fail('Expected creator XOR rejection.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame($before, $this->businessCounts());
    }

    public function test_expired_ordinary_member_invitation_is_rejected_without_state_changes(): void
    {
        $tenant = $this->fixtureTenant('expired-member');
        $admin = $this->fixtureUser();
        $member = $this->fixtureMember($tenant, $admin);
        $this->useFixtureSession($admin, $this->fixtureSession($admin, $tenant, $member, 'expired-member.platform.test'));
        $invitation = $this->fixturePost('http://expired-member.platform.test/members/invitations', [
            'name' => 'Siswa Sintetis', 'username' => 'synthetic-student', 'role' => 'student',
        ])->assertCreated()->json('data');
        $this->travel(73)->hours();
        $before = $this->businessState();
        $this->fixturePost('http://expired-member.platform.test/invitations/activate', [
            ...$invitation, 'password' => 'SyntheticStudent!234', 'password_confirmation' => 'SyntheticStudent!234',
        ])->assertNotFound();
        $this->assertSame($before, $this->businessState());
    }

    public function test_suspension_rejects_an_existing_protected_session_without_mutating_other_tenant(): void
    {
        $a = $this->fixtureTenant('session-a');
        $b = $this->fixtureTenant('session-b');
        $user = $this->fixtureUser();
        $memberA = $this->fixtureMember($a, $user);
        $memberB = $this->fixtureMember($b, $user);
        $oldA = $this->fixtureSession($user, $a, $memberA, 'session-a.platform.test');
        $oldB = $this->fixtureSession($user, $b, $memberB, 'session-b.platform.test');
        $this->centralActor();
        $ulid = DB::table('tenants')->where('id', $a)->value('public_ulid');
        $this->fixturePost('http://admin.platform.test/tenants/'.$ulid.'/suspend', [
            'expected_status' => 'active', 'reason' => 'synthetic_security_test',
        ])->assertOk();
        $before = $this->businessState();
        $this->useFixtureSession($user, $oldA);
        $this->get('http://session-a.platform.test/dashboard')->assertNotFound();
        $this->fixturePost('http://session-a.platform.test/members/'.DB::table('users')->where('id', $user)->value('public_ulid').'/roles', ['roles' => ['student']])->assertNotFound();
        $this->assertSame($before, $this->businessState());
        $this->useFixtureSession($user, $oldB);
        $this->get('http://session-b.platform.test/dashboard')->assertOk();
        $this->assertDatabaseHas('tenant_memberships', ['id' => $memberB, 'auth_version' => 1, 'status' => 'active']);
    }

    public function test_global_suspension_and_left_membership_reject_stale_sessions_without_writes(): void
    {
        $tenant = $this->fixtureTenant('disabled-session');
        $user = $this->fixtureUser();
        $member = $this->fixtureMember($tenant, $user);
        $old = $this->fixtureSession($user, $tenant, $member, 'disabled-session.platform.test');
        DB::table('users')->where('id', $user)->update(['status' => 'suspended']);
        $before = $this->businessState();
        $this->useFixtureSession($user, $old);
        $this->get('http://disabled-session.platform.test/dashboard')->assertRedirect('/login');
        $this->assertSame($before, $this->businessState());
        DB::table('users')->where('id', $user)->update(['status' => 'active']);
        DB::table('tenant_memberships')->where('id', $member)->update(['status' => 'left']);
        $before = $this->businessState();
        $this->useFixtureSession($user, $old);
        $this->get('http://disabled-session.platform.test/dashboard')->assertRedirect('/login');
        $this->assertSame($before, $this->businessState());
    }

    public function test_role_change_rejects_old_session_in_a_without_revoking_membership_b(): void
    {
        $a = $this->fixtureTenant('role-session-a');
        $b = $this->fixtureTenant('role-session-b');
        $admin = $this->fixtureUser('school-admin-synthetic@example.test');
        $adminMember = $this->fixtureMember($a, $admin);
        $user = $this->fixtureUser();
        $memberA = $this->fixtureMember($a, $user, 'teacher');
        $memberB = $this->fixtureMember($b, $user, 'teacher');
        $oldA = $this->fixtureSession($user, $a, $memberA, 'role-session-a.platform.test');
        $oldB = $this->fixtureSession($user, $b, $memberB, 'role-session-b.platform.test');
        $this->useFixtureSession($admin, $this->fixtureSession($admin, $a, $adminMember, 'role-session-a.platform.test'));
        $ulid = DB::table('users')->where('id', $user)->value('public_ulid');
        $this->fixturePost('http://role-session-a.platform.test/members/'.$ulid.'/roles', ['roles' => ['student']])->assertOk();
        $before = $this->businessState();
        $this->useFixtureSession($user, $oldA);
        $this->get('http://role-session-a.platform.test/dashboard')->assertRedirect('/login');
        $this->assertSame($before, $this->businessState());
        $this->useFixtureSession($user, $oldB);
        $this->get('http://role-session-b.platform.test/dashboard')->assertOk();
        $this->assertDatabaseHas('tenant_memberships', ['id' => $memberB, 'auth_version' => 1]);
    }

    private function centralActor(): int
    {
        $actor = $this->fixtureUser('central-synthetic@example.test');
        DB::table('platform_staff')->insert(['user_id' => $actor, 'role' => 'super_admin', 'status' => 'active']);
        $this->useFixtureSession($actor, $this->fixtureSession($actor));

        return $actor;
    }

    private function provision(string $slug): TestResponse
    {
        return $this->withHeader('Idempotency-Key', 'synthetic-provision-'.$slug)->fixturePost('http://admin.platform.test/tenants', [
            'school_name' => 'Sekolah Sintetis', 'slug' => $slug, 'timezone' => 'Asia/Jakarta',
            'admin_name' => 'Admin Sintetis', 'admin_email' => $slug.'@example.test', 'admin_username' => 'admin',
        ]);
    }

    private function businessCounts(): array
    {
        $result = [];
        foreach (['tenants', 'tenant_domains', 'tenant_settings', 'users', 'tenant_memberships', 'membership_roles', 'account_invitations'] as $table) {
            $result[$table] = DB::table($table)->count();
        }

        return $result;
    }

    private function businessState(): array
    {
        $result = [];
        foreach (['tenants', 'tenant_domains', 'tenant_settings', 'users', 'tenant_memberships', 'account_invitations', 'account_recovery_requests', 'tenant_audit_logs', 'platform_audit_logs'] as $table) {
            $result[$table] = DB::table($table)->orderBy($table === 'tenant_settings' ? 'tenant_id' : 'id')->get()->toJson();
        }
        $result['membership_roles'] = DB::table('membership_roles')->orderBy('tenant_id')->orderBy('membership_id')->orderBy('role_id')->get()->toJson();

        return $result;
    }
}

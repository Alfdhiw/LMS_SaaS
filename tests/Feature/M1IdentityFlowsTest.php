<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Mail\SelfRecoveryMail;
use App\Support\Auth\AuthSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class M1IdentityFlowsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cache.limiter' => 'array']);
        app()->forgetInstance(RateLimiter::class);
    }

    public function test_existing_account_join_requires_invitation_host_password_and_creates_only_scoped_membership(): void
    {
        $a = $this->tenant('join-a');
        $b = $this->tenant('join-b');
        $admin = $this->user('admin-join@example.test');
        $adminMember = $this->member($b, $admin, 'admin', 'school_admin');
        $teacher = $this->user('teacher-join@example.test');
        $this->member($a, $teacher, 'teacher-a', 'teacher');
        $this->authenticateTenant($b, $admin, $adminMember, 'join-b.platform.test');
        $issued = $this->jsonPost('join-b', '/members/invitations/existing', ['username' => 'teacher-b', 'role' => 'teacher'])->assertCreated()->json('data');
        $this->assertDatabaseHas('account_invitations', ['public_ulid' => $issued['invitation_id'], 'target_user_id' => null, 'membership_id' => null]);
        $this->assertDatabaseMissing('tenant_memberships', ['tenant_id' => $b, 'user_id' => $teacher]);
        auth()->guard('web')->logout();
        $data = ['invitation_id' => $issued['invitation_id'], 'token' => $issued['token'], 'email' => 'teacher-join@example.test', 'password' => 'WrongPassword!234'];
        $this->jsonPost('join-b', '/invitations/join', $data)->assertNotFound();
        $data['password'] = 'ValidPassword!234';
        $this->jsonPost('join-a', '/invitations/join', $data)->assertNotFound();
        $this->jsonPost('join-b', '/invitations/join', $data)->assertOk();
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $b, 'user_id' => $teacher, 'local_username_normalized' => 'teacher-b', 'status' => 'active']);
        $this->assertSame(2, DB::table('tenant_memberships')->where('user_id', $teacher)->count());
        $this->assertDatabaseHas('account_invitations', ['public_ulid' => $issued['invitation_id'], 'status' => 'consumed', 'target_user_id' => $teacher]);
        $this->jsonPost('join-b', '/invitations/join', $data)->assertNotFound();
        $this->assertDatabaseHas('tenant_audit_logs', ['tenant_id' => $b, 'action' => 'membership.join_invitation.consumed']);
    }

    public function test_email_recovery_is_generic_single_use_and_revokes_global_sessions(): void
    {
        Mail::fake();
        $a = $this->tenant('email-a');
        $b = $this->tenant('email-b');
        $teacher = $this->user('teacher-reset@example.test');
        $memberA = $this->member($a, $teacher, 'teacher-a', 'teacher');
        $memberB = $this->member($b, $teacher, 'teacher-b', 'student');
        $unknown = $this->jsonPost('email-a', '/password/forgot', ['email' => 'missing@example.test'])->assertOk()->json('message');
        $known = $this->jsonPost('email-a', '/password/forgot', ['email' => 'teacher-reset@example.test'])->assertOk()->json('message');
        $this->assertSame($unknown, $known);
        Mail::assertSent(SelfRecoveryMail::class, 1);
        $mail = Mail::sent(SelfRecoveryMail::class)->first();
        parse_str((string) parse_url($mail->resetUrl, PHP_URL_FRAGMENT), $fragment);
        $this->assertStringContainsString('email-a.platform.test/password/reset#', $mail->resetUrl);
        $record = DB::table('account_recovery_requests')->where('public_ulid', $fragment['id'])->first();
        $this->assertNotSame($fragment['token'], $record->token_hash);
        $this->assertNull($record->tenant_id);
        $payload = ['recovery_id' => $fragment['id'], 'token' => $fragment['token'], 'password' => 'ChangedPassword!234', 'password_confirmation' => 'ChangedPassword!234'];
        $this->jsonPost('email-a', '/password/reset', $payload)->assertOk();
        $this->jsonPost('email-a', '/password/reset', $payload)->assertNotFound();
        $this->assertSame(2, (int) DB::table('users')->where('id', $teacher)->value('auth_version'));
        $this->assertTrue(Hash::check('ChangedPassword!234', (string) DB::table('users')->where('id', $teacher)->value('password')));
        $this->authenticateTenant($a, $teacher, $memberA, 'email-a.platform.test');
        $this->get('http://email-a.platform.test/dashboard')->assertRedirect('/login');
        $this->authenticateTenant($b, $teacher, $memberB, 'email-b.platform.test');
        $this->get('http://email-b.platform.test/dashboard')->assertRedirect('/login');
        $audit = DB::table('tenant_audit_logs')->where('target_id', $fragment['id'])->pluck('metadata_json')->implode(' ');
        $this->assertStringNotContainsString($fragment['token'], $audit);
    }

    public function test_email_recovery_denies_platform_staff_and_unverified_addresses(): void
    {
        Mail::fake();
        $tenant = $this->tenant('email-deny');
        $staff = $this->user('staff-recovery@example.test');
        $this->member($tenant, $staff, 'staff', 'teacher');
        DB::table('platform_staff')->insert(['user_id' => $staff, 'role' => 'super_admin', 'status' => 'active']);
        $unverified = $this->user('unverified@example.test');
        DB::table('users')->where('id', $unverified)->update(['email_verified_at' => null]);
        $this->member($tenant, $unverified, 'unverified', 'student');
        $this->jsonPost('email-deny', '/password/forgot', ['email' => 'staff-recovery@example.test'])->assertOk();
        $this->jsonPost('email-deny', '/password/forgot', ['email' => 'unverified@example.test'])->assertOk();
        Mail::assertNothingSent();
    }

    public function test_school_recovery_never_exposes_student_secret_to_admin_and_requires_approval(): void
    {
        $tenant = $this->tenant('assisted-flow');
        $admin = $this->user('assisted-admin@example.test');
        $adminMember = $this->member($tenant, $admin, 'admin', 'school_admin');
        $student = $this->user('assisted-student@example.test');
        DB::table('users')->where('id', $student)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($tenant, $student, 'student', 'student');
        $studentPage = $this->get('http://assisted-flow.platform.test/recovery/school-assisted')->assertOk()->assertSee('Bantuan password siswa tanpa email');
        $this->assertStringContainsString('no-store', (string) $studentPage->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $studentPage->headers->get('Referrer-Policy'));
        $secret = bin2hex(random_bytes(32));
        $id = $this->jsonPost('assisted-flow', '/recovery/school-assisted/request', [
            'username' => 'student', 'commitment' => hash('sha256', $secret),
        ])->assertOk()->json('recovery_id');
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $id, 'status' => 'requested', 'token_hash' => null]);
        $payload = ['recovery_id' => $id, 'token' => $secret, 'password' => 'ChangedPassword!234', 'password_confirmation' => 'ChangedPassword!234'];
        $this->jsonPost('assisted-flow', '/recovery/school-assisted', $payload)->assertNotFound();
        $this->authenticateTenant($tenant, $admin, $adminMember, 'assisted-flow.platform.test');
        $this->get('http://assisted-flow.platform.test/members/recovery')->assertOk()->assertSee('Verifikasi pemulihan siswa');
        $studentUlid = (string) DB::table('users')->where('id', $student)->value('public_ulid');
        $this->jsonPost('assisted-flow', '/members/'.$studentUlid.'/school-assisted-recovery', [
            'identity_verified_offline' => true,
        ])->assertNotFound();
        $preview = $this->getJson('http://assisted-flow.platform.test/members/recovery/'.$id)->assertOk()->json('data');
        $this->assertSame(['recovery_id', 'username', 'display_name'], array_keys($preview));
        $this->assertSame('student', $preview['username']);
        $approved = $this->jsonPost('assisted-flow', '/members/recovery/'.$id.'/approve', ['identity_verified_offline' => true])->assertOk();
        $this->assertSame(['status' => 'approved'], $approved->json());
        $this->assertStringNotContainsString($secret, $approved->getContent());
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $id, 'status' => 'token_issued', 'token_hash' => hash('sha256', $secret)]);
        auth()->guard('web')->logout();
        $this->jsonPost('assisted-flow', '/recovery/school-assisted', [...$payload, 'token' => str_repeat('a', 64)])->assertNotFound();
        $this->jsonPost('assisted-flow', '/recovery/school-assisted', $payload)->assertOk();
        $this->jsonPost('assisted-flow', '/recovery/school-assisted', $payload)->assertNotFound();
        $this->assertSame(2, (int) DB::table('users')->where('id', $student)->value('auth_version'));
        $this->assertTrue(Hash::check('ChangedPassword!234', (string) DB::table('users')->where('id', $student)->value('password')));
        $audit = DB::table('tenant_audit_logs')->where('target_id', $id)->pluck('metadata_json')->implode(' ');
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsString(hash('sha256', $secret), $audit);
    }

    public function test_school_recovery_cache_loss_and_membership_changes_fail_closed(): void
    {
        $tenant = $this->tenant('assisted-cache');
        $other = $this->tenant('assisted-other');
        $admin = $this->user('cache-admin@example.test');
        $adminMember = $this->member($tenant, $admin, 'admin', 'school_admin');
        $student = $this->user('cache-student@example.test');
        DB::table('users')->where('id', $student)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($tenant, $student, 'student', 'student');
        $secret = bin2hex(random_bytes(32));
        $input = ['username' => 'student', 'commitment' => hash('sha256', $secret)];
        $id = $this->jsonPost('assisted-cache', '/recovery/school-assisted/request', $input)->assertOk()->json('recovery_id');
        $this->authenticateTenant($tenant, $admin, $adminMember, 'assisted-cache.platform.test');
        Cache::forget('tenant:'.$tenant.':school-recovery:'.$id);
        $this->jsonPost('assisted-cache', '/members/recovery/'.$id.'/approve', ['identity_verified_offline' => true])->assertStatus(410);
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $id, 'status' => 'requested', 'token_hash' => null]);
        auth()->guard('web')->logout();
        $second = $this->jsonPost('assisted-cache', '/recovery/school-assisted/request', $input)->assertOk()->json('recovery_id');
        $historical = $this->member($other, $student, 'student-other', 'student');
        DB::table('tenant_memberships')->where('id', $historical)->update(['status' => 'left']);
        $this->authenticateTenant($tenant, $admin, $adminMember, 'assisted-cache.platform.test');
        $this->jsonPost('assisted-cache', '/members/recovery/'.$second.'/approve', ['identity_verified_offline' => true])->assertForbidden();
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $second, 'status' => 'requested', 'token_hash' => null]);
    }

    public function test_school_recovery_denies_staff_and_does_not_disclose_unknown_user(): void
    {
        $tenant = $this->tenant('assisted-deny');
        $staff = $this->user('assisted-staff@example.test');
        DB::table('users')->where('id', $staff)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($tenant, $staff, 'staff', 'student');
        DB::table('platform_staff')->insert(['user_id' => $staff, 'role' => 'super_admin', 'status' => 'active']);
        $input = ['username' => 'staff', 'commitment' => hash('sha256', str_repeat('a', 64))];
        $staffResponse = $this->jsonPost('assisted-deny', '/recovery/school-assisted/request', $input)->assertOk()->json();
        $unknownResponse = $this->jsonPost('assisted-deny', '/recovery/school-assisted/request', [...$input, 'username' => 'unknown'])->assertOk()->json();
        $this->assertSame(array_keys($staffResponse), array_keys($unknownResponse));
        $this->assertSame($staffResponse['message'], $unknownResponse['message']);
        $this->assertDatabaseMissing('account_recovery_requests', ['user_id' => $staff]);
    }

    public function test_school_recovery_admin_access_is_tenant_scoped_and_permission_checked(): void
    {
        $a = $this->tenant('assisted-scope-a');
        $b = $this->tenant('assisted-scope-b');
        $student = $this->user('scope-student@example.test');
        DB::table('users')->where('id', $student)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $studentMember = $this->member($a, $student, 'student', 'student');
        $adminB = $this->user('scope-admin@example.test');
        $adminMemberB = $this->member($b, $adminB, 'admin', 'school_admin');
        $token = bin2hex(random_bytes(32));
        $id = $this->jsonPost('assisted-scope-a', '/recovery/school-assisted/request', [
            'username' => 'student', 'commitment' => hash('sha256', $token),
        ])->assertOk()->json('recovery_id');
        $this->authenticateTenant($b, $adminB, $adminMemberB, 'assisted-scope-b.platform.test');
        $this->getJson('http://assisted-scope-b.platform.test/members/recovery/'.$id)->assertNotFound();
        $this->jsonPost('assisted-scope-b', '/members/recovery/'.$id.'/approve', ['identity_verified_offline' => true])->assertNotFound();
        auth()->guard('web')->logout();
        $this->authenticateTenant($a, $student, $studentMember, 'assisted-scope-a.platform.test');
        $this->getJson('http://assisted-scope-a.platform.test/members/recovery/'.$id)->assertForbidden();
        $this->jsonPost('assisted-scope-a', '/members/recovery/'.$id.'/approve', ['identity_verified_offline' => true])->assertForbidden();
        $this->assertDatabaseHas('account_recovery_requests', ['public_ulid' => $id, 'status' => 'requested', 'token_hash' => null]);
    }

    public function test_school_recovery_rechecks_eligibility_at_consume_and_rejects_expiry(): void
    {
        $a = $this->tenant('assisted-expiry');
        $b = $this->tenant('assisted-history');
        $admin = $this->user('expiry-admin@example.test');
        $adminMember = $this->member($a, $admin, 'admin', 'school_admin');
        $student = $this->user('expiry-student@example.test');
        DB::table('users')->where('id', $student)->update(['email' => null, 'email_normalized' => null, 'email_verified_at' => null]);
        $this->member($a, $student, 'student', 'student');
        $token = bin2hex(random_bytes(32));
        $id = $this->jsonPost('assisted-expiry', '/recovery/school-assisted/request', [
            'username' => 'student', 'commitment' => hash('sha256', $token),
        ])->assertOk()->json('recovery_id');
        $this->authenticateTenant($a, $admin, $adminMember, 'assisted-expiry.platform.test');
        $this->jsonPost('assisted-expiry', '/members/recovery/'.$id.'/approve', ['identity_verified_offline' => true])->assertOk();
        auth()->guard('web')->logout();
        $payload = ['recovery_id' => $id, 'token' => $token, 'password' => 'ChangedPassword!234', 'password_confirmation' => 'ChangedPassword!234'];
        $other = $this->member($b, $student, 'student-history', 'student');
        DB::table('tenant_memberships')->where('id', $other)->update(['status' => 'left']);
        $this->jsonPost('assisted-expiry', '/recovery/school-assisted', $payload)->assertForbidden();
        DB::table('account_recovery_requests')->where('public_ulid', $id)->update(['expires_at' => now()->subMinute()]);
        $this->jsonPost('assisted-expiry', '/recovery/school-assisted', $payload)->assertNotFound();
        $this->assertSame(1, (int) DB::table('users')->where('id', $student)->value('auth_version'));
    }

    public function test_offline_invitation_ui_is_protected_and_token_is_only_shown_in_no_store_response(): void
    {
        $tenant = $this->tenant('invite-ui');
        $admin = $this->user('invite-ui-admin@example.test');
        $membership = $this->member($tenant, $admin, 'admin', 'school_admin');
        $this->authenticateTenant($tenant, $admin, $membership, 'invite-ui.platform.test');
        $this->get('http://invite-ui.platform.test/members/manage')->assertOk()->assertSee('Akun baru tanpa email')->assertSee('akun yang sudah ada');
        $response = $this->withSession(['_token' => 'csrf-pr5'])->post('http://invite-ui.platform.test/members/invitations', [
            '_token' => 'csrf-pr5', '_interface' => 'web', 'name' => 'Siswa Dummy', 'username' => 'student', 'role' => 'student',
        ]);
        $response->assertCreated()->assertSee('Kode undangan sekali pakai');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->get('http://invite-ui.platform.test/invitations/accept')->assertOk()->assertSee('Akun yang sudah ada');
        $this->get('http://invite-ui.platform.test/password/forgot')->assertOk()->assertSee('Email');
        $this->get('http://invite-ui.platform.test/password/reset')->assertOk()->assertSee('recovery-token');
    }

    public function test_email_recovery_rejects_expired_token_and_delivery_is_not_sent_before_outer_commit(): void
    {
        Mail::fake();
        $tenant = $this->tenant('email-expiry');
        $user = $this->user('expiry@example.test');
        $this->member($tenant, $user, 'teacher', 'teacher');
        DB::beginTransaction();
        try {
            $this->jsonPost('email-expiry', '/password/forgot', ['email' => 'expiry@example.test'])->assertOk();
            Mail::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::table('account_recovery_requests')->where('user_id', $user)->count());
        $this->jsonPost('email-expiry', '/password/forgot', ['email' => 'expiry@example.test'])->assertOk();
        $mail = Mail::sent(SelfRecoveryMail::class)->first();
        $this->assertNotNull($mail);
        parse_str((string) parse_url($mail->resetUrl, PHP_URL_FRAGMENT), $fragment);
        DB::table('account_recovery_requests')->where('public_ulid', $fragment['id'])->update(['expires_at' => now()->subMinute()]);
        $this->jsonPost('email-expiry', '/password/reset', [
            'recovery_id' => $fragment['id'], 'token' => $fragment['token'],
            'password' => 'ChangedPassword!234', 'password_confirmation' => 'ChangedPassword!234',
        ])->assertNotFound();
        $this->assertSame(1, (int) DB::table('users')->where('id', $user)->value('auth_version'));
    }

    private function tenant(string $slug): int
    {
        $id = DB::table('tenants')->insertGetId(['public_ulid' => (string) Str::ulid(), 'slug' => $slug, 'name' => 'Sekolah Dummy', 'status' => 'active']);
        DB::table('tenant_domains')->insert(['tenant_id' => $id, 'hostname' => $slug.'.platform.test', 'kind' => 'default', 'status' => 'active', 'verified_at' => now(), 'is_primary' => true]);

        return (int) $id;
    }

    private function user(string $email): int
    {
        return (int) DB::table('users')->insertGetId(['public_ulid' => (string) Str::ulid(), 'name' => 'User Dummy', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now(), 'password' => Hash::make('ValidPassword!234'), 'status' => 'active', 'auth_version' => 1]);
    }

    private function member(int $tenant, int $user, string $username, string $role): int
    {
        $id = DB::table('tenant_memberships')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'local_username' => $username, 'local_username_normalized' => $username, 'display_name' => 'User Dummy', 'status' => 'active', 'auth_version' => 1]);
        DB::table('membership_roles')->insert(['tenant_id' => $tenant, 'membership_id' => $id, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);

        return (int) $id;
    }

    private function authenticateTenant(int $tenant, int $user, int $membership, string $host): void
    {
        $this->actingAs(User::query()->findOrFail($user), 'web')->withSession([
            AuthSession::AREA => 'tenant', AuthSession::HOST => $host, AuthSession::USER_ID => $user,
            AuthSession::TENANT_ID => $tenant, AuthSession::MEMBERSHIP_ID => $membership,
            AuthSession::USER_AUTH_VERSION => 1, AuthSession::MEMBERSHIP_AUTH_VERSION => 1,
        ]);
    }

    private function jsonPost(string $slug, string $path, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'csrf-pr5'])->withHeader('Accept', 'application/json')
            ->post('http://'.$slug.'.platform.test'.$path, [...$data, '_token' => 'csrf-pr5']);
    }
}

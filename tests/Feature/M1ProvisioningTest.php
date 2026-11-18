<?php

namespace Tests\Feature;

use App\Modules\Platform\Mail\BootstrapAdminInvitation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class M1ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const CSRF_TOKEN = 'm1-provisioning-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--no-interaction' => true,
        ]);
        config(['saas.central_mfa_required' => false]);
    }

    public function test_provisioning_is_atomic_and_creates_pending_tenant_bundle(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $response = $this->provision('sekolah-a', 'provision-key-0001', [
            'tenant_id' => 999999,
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $tenantId = DB::table('tenants')->where('public_ulid', $data['tenant_public_ulid'])->value('id');
        $membership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->first();

        $this->assertSame('pending', $data['tenant_status']);
        $this->assertNotEmpty($data['invitation_token']);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'slug' => 'sekolah-a',
            'status' => 'pending',
            'created_by_platform_user_id' => $actor,
        ]);
        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenantId,
            'hostname' => 'sekolah-a.platform.test',
            'status' => 'active',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenantId]);
        $this->assertSame('invited', $membership->status);
        $this->assertDatabaseHas('account_invitations', [
            'tenant_id' => $tenantId,
            'purpose' => 'bootstrap_admin',
            'status' => 'pending',
            'created_by_platform_user_id' => $actor,
        ]);
        $this->assertDatabaseHas('tenant_provisioning_requests', [
            'actor_user_id' => $actor,
            'status' => 'succeeded',
            'tenant_id' => $tenantId,
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'tenant.create.succeeded',
            'actor_user_id' => $actor,
            'tenant_id' => $tenantId,
        ]);
        $audit = DB::table('platform_audit_logs')
            ->where('action', 'tenant.create.succeeded')
            ->where('tenant_id', $tenantId)
            ->first();
        $this->assertStringNotContainsString($data['invitation_token'], (string) $audit->metadata_json);
    }

    public function test_same_idempotency_key_replays_without_creating_second_tenant(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $first = $this->provision('sekolah-replay', 'provision-key-0002');
        $second = $this->provision('sekolah-replay', 'provision-key-0002');

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('data.idempotent_replay', true);
        $this->assertCount(1, DB::table('tenants')->where('slug', 'sekolah-replay')->get());
        $this->assertArrayNotHasKey('invitation_token', $second->json('data'));
    }

    public function test_provisioning_delivers_bootstrap_invitation_after_commit(): void
    {
        Mail::fake();
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $response = $this->provision('sekolah-mail', 'provision-key-mail');

        $response->assertCreated()->assertJsonPath('data.invitation_delivery', 'sent');
        Mail::assertSent(BootstrapAdminInvitation::class, function (BootstrapAdminInvitation $mail): bool {
            return $mail->displayName === 'Admin sekolah-mail'
                && str_contains($mail->activationUrl, 'sekolah-mail.platform.test/activate/');
        });
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'tenant.invitation.delivery_sent',
        ]);
    }

    public function test_resend_rotates_invitation_token_without_reprovisioning(): void
    {
        Mail::fake();
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        $first = $this->provision('sekolah-resend', 'provision-key-resend')->assertCreated()->json('data');

        $second = $this->postCentral('/tenants/'.$first['tenant_public_ulid'].'/invitation/resend', [
            'invitation_public_ulid' => $first['invitation_public_ulid'],
        ])->assertOk()->json('data');

        $this->assertNotSame($first['invitation_token'], $second['invitation_token']);
        $this->assertSame($first['tenant_public_ulid'], $second['tenant_public_ulid']);
        $this->assertCount(1, DB::table('tenants')->where('slug', 'sekolah-resend')->get());
        Mail::assertSent(BootstrapAdminInvitation::class, 2);

        $oldPath = '/activate/'.$first['invitation_public_ulid'].'/'.$first['invitation_token'];
        $this->get('http://sekolah-resend.platform.test'.$oldPath)->assertNotFound();
        $newPath = '/activate/'.$second['invitation_public_ulid'].'/'.$second['invitation_token'];
        $this->get('http://sekolah-resend.platform.test'.$newPath)->assertOk();
    }

    public function test_central_tenant_directory_has_browser_ui(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $this->get('http://admin.platform.test/tenants')
            ->assertOk()
            ->assertSee('Provision tenant')
            ->assertSee('Sekolah terdaftar');
    }

    public function test_same_idempotency_key_with_different_payload_is_rejected(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $this->provision('sekolah-first', 'provision-key-0003')->assertCreated();
        $this->provision('sekolah-second', 'provision-key-0003')
            ->assertStatus(409)
            ->assertJsonPath('failure_code', 'idempotency_payload_mismatch');

        $this->assertDatabaseMissing('tenants', ['slug' => 'sekolah-second']);
    }

    public function test_slug_collision_rolls_back_and_records_failed_request(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);

        $this->provision('sekolah-collision', 'provision-key-0004')->assertCreated();
        $this->provision('sekolah-collision', 'provision-key-0005')
            ->assertStatus(409)
            ->assertJsonPath('failure_code', 'slug_collision');

        $this->assertSame(1, DB::table('tenants')->where('slug', 'sekolah-collision')->count());
        $this->assertDatabaseHas('tenant_provisioning_requests', [
            'actor_user_id' => $actor,
            'status' => 'failed',
            'failure_code' => 'slug_collision',
        ]);
    }

    public function test_activation_is_bound_to_host_and_single_use(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        $first = $this->provision('sekolah-activation-a', 'provision-key-0006')->assertCreated()->json('data');
        $this->provision('sekolah-activation-b', 'provision-key-0007')->assertCreated();

        $path = '/activate/'.$first['invitation_public_ulid'].'/'.$first['invitation_token'];
        $this->get('http://sekolah-activation-b.platform.test'.$path)->assertNotFound();
        $this->get('http://sekolah-activation-a.platform.test'.$path)->assertOk();
        $this->postWithCsrf('http://sekolah-activation-a.platform.test'.$path, [
            'password' => 'AdminPassword!234',
            'password_confirmation' => 'AdminPassword!234',
        ])->assertRedirect('/login');

        $tenantId = (int) DB::table('tenants')->where('slug', 'sekolah-activation-a')->value('id');
        $membership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->first();
        $this->assertDatabaseHas('users', ['id' => $membership->user_id, 'status' => 'active']);
        $this->assertDatabaseHas('tenant_memberships', ['id' => $membership->id, 'status' => 'active']);
        $this->assertDatabaseHas('account_invitations', [
            'public_ulid' => $first['invitation_public_ulid'],
            'status' => 'consumed',
        ]);
        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenantId,
            'action' => 'tenant.admin_activated',
        ]);

        $this->postWithCsrf('http://sekolah-activation-a.platform.test'.$path, [
            'password' => 'AdminPassword!234',
            'password_confirmation' => 'AdminPassword!234',
        ])->assertNotFound();
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'status' => 'pending']);
    }

    public function test_expired_activation_token_is_rejected(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        $data = $this->provision('sekolah-expired', 'provision-key-0008')->assertCreated()->json('data');
        DB::table('account_invitations')
            ->where('public_ulid', $data['invitation_public_ulid'])
            ->update(['expires_at' => now()->subMinute()]);

        $this->get('http://sekolah-expired.platform.test/activate/'.$data['invitation_public_ulid'].'/'.$data['invitation_token'])
            ->assertNotFound();
    }

    public function test_lifecycle_requires_ready_admin_and_revokes_membership_sessions(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        $data = $this->provision('sekolah-lifecycle', 'provision-key-0009')->assertCreated()->json('data');

        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/activate', [
            'expected_status' => 'pending',
            'reason' => 'readiness_check',
        ])->assertStatus(422)->assertJsonPath('failure_code', 'readiness_admin_not_active');

        $path = '/activate/'.$data['invitation_public_ulid'].'/'.$data['invitation_token'];
        $this->postWithCsrf('http://sekolah-lifecycle.platform.test'.$path, [
            'password' => 'AdminPassword!234',
            'password_confirmation' => 'AdminPassword!234',
        ])->assertRedirect('/login');

        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/activate', [
            'expected_status' => 'pending',
            'reason' => 'readiness_check',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $tenantId = (int) DB::table('tenants')->where('public_ulid', $data['tenant_public_ulid'])->value('id');
        $membership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->first();
        $this->assertSame(2, (int) $membership->auth_version);

        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/suspend', [
            'expected_status' => 'active',
            'reason' => 'security_review',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(3, (int) DB::table('tenant_memberships')->where('id', $membership->id)->value('auth_version'));
        $this->get('http://sekolah-lifecycle.platform.test/login')->assertNotFound();

        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/restore', [
            'expected_status' => 'active',
            'reason' => 'security_review',
        ])->assertStatus(409)->assertJsonPath('failure_code', 'stale_tenant_status');
        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/restore', [
            'expected_status' => 'suspended',
            'reason' => 'security_review',
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('platform_audit_logs', [
            'tenant_id' => $tenantId,
            'action' => 'tenant.transitioned',
        ]);
    }

    public function test_provisioning_and_transition_routes_require_platform_permissions(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        config(['saas.platform_role_permissions.super_admin' => ['platform.tenants.view']]);

        $this->provision('sekolah-forbidden', 'provision-key-0010')->assertForbidden();
        $this->assertDatabaseMissing('tenants', ['slug' => 'sekolah-forbidden']);
    }

    public function test_archive_is_rejected_until_offboarding_checklist_is_complete(): void
    {
        $actor = $this->platformActor();
        $this->loginCentral($actor);
        $data = $this->provision('sekolah-archive', 'provision-key-0011')->assertCreated()->json('data');
        $path = '/activate/'.$data['invitation_public_ulid'].'/'.$data['invitation_token'];
        $this->postWithCsrf('http://sekolah-archive.platform.test'.$path, [
            'password' => 'AdminPassword!234',
            'password_confirmation' => 'AdminPassword!234',
        ])->assertRedirect('/login');
        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/activate', [
            'expected_status' => 'pending',
            'reason' => 'readiness_check',
        ])->assertOk();

        $this->postCentral('/tenants/'.$data['tenant_public_ulid'].'/archive', [
            'expected_status' => 'active',
            'reason' => 'offboarding_requested',
        ])->assertStatus(422)->assertJsonPath('failure_code', 'archive_checklist_incomplete');
        $this->assertDatabaseHas('tenants', [
            'public_ulid' => $data['tenant_public_ulid'],
            'status' => 'active',
        ]);
    }

    private function platformActor(): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'name' => 'Platform Operator',
            'email' => 'operator-'.Str::lower(Str::random(8)).'@example.test',
            'email_normalized' => 'operator-'.Str::lower(Str::random(8)).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('Platform!234'),
            'status' => 'active',
            'auth_version' => 1,
        ]);
        $email = DB::table('users')->where('id', $userId)->value('email');
        DB::table('users')->where('id', $userId)->update(['email_normalized' => $email]);
        DB::table('platform_staff')->insert([
            'user_id' => $userId,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        return $userId;
    }

    private function loginCentral(int $actorId): void
    {
        $user = DB::table('users')->where('id', $actorId)->first();
        $this->postWithCsrf('http://admin.platform.test/login', [
            'email' => $user->email,
            'password' => 'Platform!234',
        ])->assertRedirect('http://admin.platform.test/dashboard');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function provision(string $slug, string $idempotencyKey, array $extra = []): TestResponse
    {
        return $this->postCentral('/tenants', [
            'school_name' => 'Sekolah Dummy '.$slug,
            'slug' => $slug,
            'timezone' => 'Asia/Jakarta',
            'admin_name' => 'Admin '.$slug,
            'admin_email' => 'admin-'.$slug.'@example.test',
            'admin_username' => 'admin-'.$slug,
            ...$extra,
        ], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postCentral(string $path, array $data = [], ?string $idempotencyKey = null): TestResponse
    {
        $request = $this->withSession(['_token' => self::CSRF_TOKEN])
            ->withHeader('Accept', 'application/json');

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        return $request->post('http://admin.platform.test'.$path, [
            ...$data,
            '_token' => self::CSRF_TOKEN,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postWithCsrf(string $url, array $data = []): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF_TOKEN])
            ->post($url, [...$data, '_token' => self::CSRF_TOKEN]);
    }
}

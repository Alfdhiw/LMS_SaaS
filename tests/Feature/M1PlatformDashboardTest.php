<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class M1PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['saas.central_mfa_required' => false]);
    }

    public function test_dashboard_aggregates_real_tenant_data_without_exposing_student_or_failure_payload(): void
    {
        $actor = $this->platformActor();
        $pending = $this->tenant('pending-school', 'pending');
        $this->tenant('active-school', 'active');
        $this->tenant('suspended-school', 'suspended');
        $old = $this->tenant('archived-school', 'archived');
        DB::table('tenants')->where('id', $old)->update(['created_at' => now()->subDays(35)]);
        DB::table('account_invitations')->insert([
            'public_ulid' => (string) Str::ulid(), 'tenant_id' => $pending,
            'proposed_local_username_normalized' => 'admin', 'proposed_role_id' => DB::table('roles')->where('key', 'school_admin')->value('id'),
            'purpose' => 'bootstrap_admin', 'status' => 'pending', 'token_hash' => hash('sha256', 'synthetic-token'),
            'expires_at' => now()->addDay(), 'created_by_platform_user_id' => $actor->id,
        ]);
        DB::table('tenant_provisioning_requests')->insert([
            'actor_user_id' => $actor->id, 'idempotency_key_hash' => hash('sha256', 'dummy-key'),
            'payload_digest' => hash('sha256', 'dummy-payload'), 'status' => 'failed',
            'failure_code' => 'not_a_whitelisted_secret', 'created_at' => now(),
        ]);
        DB::table('users')->insert([
            'public_ulid' => (string) Str::ulid(), 'name' => 'STUDENT_PRIVATE_DUMMY',
            'status' => 'active', 'password' => Hash::make('SyntheticPassword!234'), 'auth_version' => 1,
        ]);
        $this->audit((int) $actor->id, $pending, 'tenant.create.succeeded', 'PRIVATE');

        $this->centralSession($actor);
        $response = $this->get('http://admin.platform.test/dashboard')->assertOk()
            ->assertSee('Dashboard platform')->assertDontSee('STUDENT_PRIVATE_DUMMY')
            ->assertDontSee('not_a_whitelisted_secret');
        $response->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 4
            && $summary['statuses'] === ['pending' => 1, 'active' => 1, 'suspended' => 1, 'archived' => 1]
            && $summary['new_last_30_days'] === 3 && $summary['pending_invitations'] === 1
            && $summary['last_failure']['code'] === 'other');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $directory = $this->getJson('http://admin.platform.test/tenants?q=pending-school')->assertOk()
            ->assertJsonPath('data.0.bootstrap_status', 'pending');
        $this->assertNotNull($directory->json('data.0.last_operation_at'));
    }

    public function test_directory_search_filter_and_pagination_are_server_side(): void
    {
        $actor = $this->platformActor();
        for ($i = 0; $i < 17; $i++) {
            $this->tenant('dummy-school-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i === 0 ? 'pending' : 'active');
        }
        $this->tenant('special-school', 'active', 'Nama_Literal');
        $this->centralSession($actor);
        $first = $this->getJson('http://admin.platform.test/tenants')->assertOk()
            ->assertJsonPath('meta.total', 18)->assertJsonPath('meta.last_page', 2);
        $this->assertCount(15, $first->json('data'));
        $this->getJson('http://admin.platform.test/tenants?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('http://admin.platform.test/tenants?status=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'dummy-school-00');
        $this->getJson('http://admin.platform.test/tenants?q=%5F')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'special-school');
        $this->getJson('http://admin.platform.test/tenants?status=invalid')->assertUnprocessable();
    }

    public function test_directory_uses_one_active_primary_when_pending_primary_also_exists(): void
    {
        $actor = $this->platformActor();
        $tenant = $this->tenant('multi-domain', 'active');
        $ulid = (string) DB::table('tenants')->where('id', $tenant)->value('public_ulid');
        DB::table('tenant_domains')->insert([
            ['tenant_id' => $tenant, 'hostname' => 'active.platform.test', 'kind' => 'default', 'status' => 'active', 'verified_at' => now(), 'is_primary' => true],
            ['tenant_id' => $tenant, 'hostname' => 'pending.platform.test', 'kind' => 'custom', 'status' => 'pending', 'verified_at' => null, 'is_primary' => true],
        ]);
        $this->centralSession($actor);
        $this->getJson('http://admin.platform.test/tenants')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hostname', 'active.platform.test');
        $this->getJson('http://admin.platform.test/tenants/'.$ulid)->assertOk()
            ->assertJsonPath('data.hostname', 'active.platform.test');
    }

    public function test_audit_filters_are_authorized_paginated_and_never_return_metadata_or_target_id(): void
    {
        $actor = $this->platformActor();
        $tenantA = $this->tenant('audit-a', 'active');
        $tenantB = $this->tenant('audit-b', 'active');
        $tenantUlid = (string) DB::table('tenants')->where('id', $tenantA)->value('public_ulid');
        $this->audit($actor->id, $tenantA, 'tenant.create.failed', 'SECRET_TOKEN_DUMMY');
        $this->audit($actor->id, $tenantB, 'tenant.create.succeeded', 'PRIVATE_STUDENT_DUMMY');
        $this->centralSession($actor);
        $query = http_build_query([
            'tenant' => $tenantUlid, 'actor' => $actor->public_ulid, 'action' => 'tenant.create.failed',
            'from' => now()->toDateString(), 'to' => now()->toDateString(), 'result' => 'failed',
        ]);
        $response = $this->getJson('http://admin.platform.test/audit?'.$query)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'tenant.create.failed')
            ->assertJsonPath('data.0.tenant_ulid', $tenantUlid);
        $this->assertArrayNotHasKey('metadata_json', $response->json('data.0'));
        $this->assertArrayNotHasKey('target_id', $response->json('data.0'));
        $response->assertDontSee('SECRET_TOKEN_DUMMY')->assertDontSee('PRIVATE_STUDENT_DUMMY');
        $this->get('http://admin.platform.test/audit?tenant='.$tenantUlid)->assertOk()
            ->assertSee('Audit platform')->assertDontSee('SECRET_TOKEN_DUMMY');
        $this->getJson('http://admin.platform.test/audit?to='.now()->toDateString())->assertOk();
        $this->getJson('http://admin.platform.test/audit?from=2026-01-02&to=2026-01-01')->assertUnprocessable();
        for ($i = 0; $i < 25; $i++) {
            $this->audit($actor->id, $tenantA, 'tenant.transitioned', 'HIDDEN');
        }
        $this->getJson('http://admin.platform.test/audit')->assertOk()->assertJsonPath('meta.total', 27)->assertJsonCount(25, 'data');
        $this->getJson('http://admin.platform.test/audit?page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_audit_denies_missing_permission_and_tenant_host(): void
    {
        $actor = $this->platformActor();
        $tenant = $this->tenant('tenant-host', 'active');
        DB::table('tenant_domains')->insert([
            'tenant_id' => $tenant, 'hostname' => 'tenant-host.platform.test', 'kind' => 'default',
            'status' => 'active', 'verified_at' => now(), 'is_primary' => true,
        ]);
        $this->centralSession($actor);
        config(['saas.platform_role_permissions.super_admin' => ['platform.tenants.view']]);
        $this->get('http://admin.platform.test/audit')->assertForbidden();
        $this->get('http://tenant-host.platform.test/audit')->assertNotFound();
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_audit_result_filter_recognizes_delivery_suffixes(): void
    {
        $actor = $this->platformActor();
        $tenant = $this->tenant('audit-result', 'active');
        $this->audit($actor->id, $tenant, 'tenant.invitation.delivery_failed', 'PRIVATE');
        $this->audit($actor->id, $tenant, 'tenant.invitation.delivery_sent', 'PRIVATE');
        $this->centralSession($actor);
        $this->getJson('http://admin.platform.test/audit?result=failed')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.result', 'failed');
        $this->getJson('http://admin.platform.test/audit?result=succeeded')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.result', 'succeeded');
    }

    public function test_audit_writer_rejects_nested_secret_fields_and_rolls_back_the_transaction(): void
    {
        $actor = $this->platformActor();
        try {
            DB::transaction(function () use ($actor): void {
                $this->tenant('must-roll-back', 'pending');
                app(AuthAuditLogger::class)->platform(
                    request: Request::create('http://admin.platform.test/', 'POST'),
                    action: 'test.sensitive',
                    actorUserId: (int) $actor->id,
                    metadata: ['context' => ['invitation_token' => 'SYNTHETIC_SECRET']],
                );
            });
            $this->fail('Sensitive metadata must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Sensitive audit metadata field is not allowed.', $exception->getMessage());
        }
        $this->assertDatabaseMissing('tenants', ['slug' => 'must-roll-back']);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_metadata_update_is_authorized_scoped_audited_and_cannot_change_slug_or_status(): void
    {
        $actor = $this->platformActor();
        $a = $this->tenant('metadata-a', 'active');
        $b = $this->tenant('metadata-b', 'active');
        $ulid = (string) DB::table('tenants')->where('id', $a)->value('public_ulid');
        $this->centralSession($actor);
        $this->patchJson('http://admin.platform.test/tenants/'.$ulid, [
            'name' => 'Sekolah Updated', 'timezone' => 'Asia/Makassar', 'slug' => 'hijacked',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('tenants', ['id' => $a, 'slug' => 'metadata-a', 'name' => 'Sekolah Dummy metadata-a']);
        $this->patchJson('http://admin.platform.test/tenants/'.$ulid, [
            'name' => 'Sekolah Updated', 'timezone' => 'Not/A_Zone',
        ])->assertUnprocessable();
        $this->patchJson('http://admin.platform.test/tenants/'.$ulid, [
            'name' => 'Sekolah Updated', 'timezone' => 'Asia/Makassar',
        ])->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $a, 'slug' => 'metadata-a', 'name' => 'Sekolah Updated', 'timezone' => 'Asia/Makassar', 'status' => 'active']);
        $this->assertDatabaseHas('tenants', ['id' => $b, 'slug' => 'metadata-b', 'name' => 'Sekolah Dummy metadata-b']);
        $archived = $this->tenant('metadata-archived', 'archived');
        $archivedUlid = (string) DB::table('tenants')->where('id', $archived)->value('public_ulid');
        $this->patchJson('http://admin.platform.test/tenants/'.$archivedUlid, [
            'name' => 'Forbidden Archived Change', 'timezone' => 'Asia/Jakarta',
        ])->assertStatus(409);
        $this->assertDatabaseHas('tenants', ['id' => $archived, 'name' => 'Sekolah Dummy metadata-archived', 'status' => 'archived']);
        $record = DB::table('platform_audit_logs')->where('action', 'tenant.metadata.updated')->first();
        $this->assertNotNull($record);
        $this->assertSame($a, (int) $record->tenant_id);
        $this->assertStringNotContainsString('Sekolah Updated', (string) $record->metadata_json);
        $this->patchJson('http://admin.platform.test/tenants/'.$ulid, [
            'name' => 'Sekolah Updated', 'timezone' => 'Asia/Makassar',
        ])->assertOk();
        $this->assertSame(1, DB::table('platform_audit_logs')->where('action', 'tenant.metadata.updated')->count());
        config(['saas.platform_role_permissions.super_admin' => ['platform.tenants.view']]);
        $this->patchJson('http://admin.platform.test/tenants/'.$ulid, [
            'name' => 'Forbidden', 'timezone' => 'Asia/Jakarta',
        ])->assertForbidden();
        $this->assertDatabaseHas('tenants', ['id' => $a, 'name' => 'Sekolah Updated', 'timezone' => 'Asia/Makassar']);
        $this->assertSame(1, DB::table('platform_audit_logs')->where('action', 'tenant.metadata.updated')->count());
    }

    public function test_three_synthetic_tenant_hosts_and_central_are_separated(): void
    {
        $actor = $this->platformActor();
        foreach (['one', 'two', 'three'] as $slug) {
            $id = $this->tenant('host-'.$slug, 'active');
            DB::table('tenant_domains')->insert([
                'tenant_id' => $id, 'hostname' => 'host-'.$slug.'.platform.test', 'kind' => 'default',
                'status' => 'active', 'verified_at' => now(), 'is_primary' => true,
            ]);
            $this->get('http://host-'.$slug.'.platform.test/login')->assertOk()->assertSee('Login Sekolah');
            $this->get('http://host-'.$slug.'.platform.test/audit')->assertNotFound();
        }
        $this->centralSession($actor);
        $this->get('http://admin.platform.test/dashboard')->assertOk()->assertSee('Dashboard platform')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 3);
    }

    private function platformActor(): User
    {
        $email = 'operator-'.Str::lower(Str::random(8)).'@example.test';
        $id = DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'name' => 'Operator Dummy',
            'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password' => Hash::make('SyntheticPassword!234'), 'status' => 'active', 'auth_version' => 1,
        ]);
        DB::table('platform_staff')->insert(['user_id' => $id, 'role' => 'super_admin', 'status' => 'active']);

        return User::query()->findOrFail($id);
    }

    private function centralSession(User $actor): void
    {
        $this->actingAs($actor, 'web')->withSession([
            AuthSession::AREA => 'central', AuthSession::HOST => 'admin.platform.test',
            AuthSession::USER_ID => $actor->id, AuthSession::TENANT_ID => null, AuthSession::MEMBERSHIP_ID => null,
            AuthSession::USER_AUTH_VERSION => 1,
        ]);
    }

    private function tenant(string $slug, string $status, ?string $name = null): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'slug' => $slug,
            'name' => $name ?? 'Sekolah Dummy '.$slug, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audit(int $actor, int $tenant, string $action, string $sensitive): void
    {
        DB::table('platform_audit_logs')->insert([
            'actor_user_id' => $actor, 'tenant_id' => $tenant, 'action' => $action,
            'target_type' => 'tenant', 'target_id' => $sensitive,
            'metadata_json' => json_encode(['token' => $sensitive, 'email' => 'private@example.test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}

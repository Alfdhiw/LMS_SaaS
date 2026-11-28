<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Academic\Policies\ProfileEligibilityPolicy;
use App\Modules\Identity\Contracts\MembershipEligibilityReader;
use App\Modules\Identity\Data\MembershipSelection;
use App\Modules\Identity\Services\SchoolProfileWorkflow;
use App\Modules\Tenancy\Contracts\SchoolProfiles;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Auth\AuthSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\M2FoundationFixtures;
use Tests\TestCase;

final class M2AdaptersTest extends TestCase
{
    use M2FoundationFixtures;
    use RefreshDatabase;

    public function test_membership_reader_is_tenant_scoped_and_observes_revocation_without_caching(): void
    {
        $a = $this->context('adapter-a');
        $b = $this->context('adapter-b');
        $member = $this->foundationMember($a->tenantId, 'siswa-a');
        $foreign = $this->foundationMember($b->tenantId, 'siswa-b');
        $userId = DB::table('tenant_memberships')->where('id', $member)->value('user_id');
        DB::table('users')->where('id', $userId)->update(['status' => 'active', 'password' => 'synthetic-hash']);
        DB::table('tenant_memberships')->where('id', $member)->update(['status' => 'active']);
        $roleId = DB::table('roles')->insertGetId(['key' => 'student', 'display_name' => 'Siswa']);
        DB::table('membership_roles')->insert(['tenant_id' => $a->tenantId, 'membership_id' => $member, 'role_id' => $roleId]);

        $reader = app(MembershipEligibilityReader::class);
        $selection = new MembershipSelection([$member, $foreign]);
        $facts = $reader->read($a, $selection);
        $this->assertSame([$member], array_keys($facts));
        $policy = app(ProfileEligibilityPolicy::class);
        $this->assertTrue($policy->mayActivate($facts[$member], $a->tenantId, 'student'));
        $this->assertFalse($policy->mayActivate($facts[$member], $b->tenantId, 'student'));
        $this->assertFalse($policy->mayActivate($facts[$member], $a->tenantId, 'teacher'));
        DB::table('membership_roles')->where('tenant_id', $a->tenantId)->where('membership_id', $member)->delete();
        $this->assertFalse($policy->mayActivate($reader->read($a, $selection)[$member], $a->tenantId, 'student'));
        DB::table('membership_roles')->insert(['tenant_id' => $a->tenantId, 'membership_id' => $member, 'role_id' => $roleId]);
        DB::table('users')->where('id', $userId)->update(['status' => 'suspended', 'auth_version' => 2]);
        $this->assertFalse($policy->mayActivate($reader->read($a, $selection)[$member], $a->tenantId, 'student'));
        DB::table('users')->where('id', $userId)->update(['status' => 'active']);
        DB::table('tenant_memberships')->where('id', $member)->update(['status' => 'suspended', 'auth_version' => 2]);
        $fresh = $reader->read($a, $selection)[$member];
        $this->assertFalse($policy->mayActivate($fresh, $a->tenantId, 'student'));
        $this->assertFalse($fresh->matchesVersions(1, 1));
        $this->assertSame([], $reader->read($a, new MembershipSelection([])));
    }

    public function test_school_profile_updates_only_own_allowlisted_fields_and_transaction_rolls_back(): void
    {
        $a = $this->context('profile-a');
        $b = $this->context('profile-b');
        $profiles = app(SchoolProfiles::class);
        $this->assertSame($a->tenantId, $profiles->read($a)->tenantId);
        $beforeB = $profiles->read($b);
        DB::transaction(function () use ($profiles, $a): void {
            $profiles->update($a, new SchoolProfileUpdate('Sekolah A Baru', 'UTC', '#abcdef'));
        });
        $updated = $profiles->read($a);
        $this->assertSame('Sekolah A Baru', $updated->name);
        $this->assertSame('UTC', $updated->timezone);
        $this->assertSame('#abcdef', $updated->accentColor);
        $this->assertEquals($beforeB, $profiles->read($b));
        try {
            DB::transaction(function () use ($profiles, $a): void {
                $profiles->update($a, new SchoolProfileUpdate('Rollback', 'UTC', null));
                throw new \RuntimeException('synthetic audit failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame('Sekolah A Baru', $profiles->read($a)->name);

        $forged = new TenantContext($a->tenantId, $a->tenantPublicUlid, $a->tenantSlug, 'active', $b->domainId, $b->hostname, 'active');
        try {
            DB::transaction(fn () => $profiles->update($forged, new SchoolProfileUpdate('Forbidden', 'UTC', null)));
            $this->fail('A domain belonging to another tenant must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame('Sekolah A Baru', $profiles->read($a)->name);
        $this->assertEquals($beforeB, $profiles->read($b));
    }

    public function test_school_profile_workflow_checks_permission_host_versions_and_writes_audit(): void
    {
        $a = $this->context('workflow-a');
        $b = $this->context('workflow-b');
        $member = $this->foundationMember($a->tenantId, 'admin');
        $userId = (int) DB::table('tenant_memberships')->where('id', $member)->value('user_id');
        DB::table('users')->where('id', $userId)->update(['status' => 'active', 'password' => 'synthetic-hash']);
        DB::table('tenant_memberships')->where('id', $member)->update(['status' => 'active']);
        $role = DB::table('roles')->insertGetId(['key' => 'school_admin', 'display_name' => 'Admin']);
        DB::table('membership_roles')->insert(['tenant_id' => $a->tenantId, 'membership_id' => $member, 'role_id' => $role]);
        foreach (['school.view', 'school.update'] as $permission) {
            $id = DB::table('permissions')->insertGetId(['key' => $permission, 'scope' => 'tenant']);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        $session = new Store('synthetic', new ArraySessionHandler(120));
        $session->start();
        $session->put([
            AuthSession::AREA => 'tenant', AuthSession::HOST => $a->hostname, AuthSession::TENANT_ID => $a->tenantId,
            AuthSession::USER_ID => $userId, AuthSession::MEMBERSHIP_ID => $member,
            AuthSession::USER_AUTH_VERSION => 1, AuthSession::MEMBERSHIP_AUTH_VERSION => 1,
        ]);
        $request = Request::create('http://'.$a->hostname.'/school', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => User::query()->find($userId));
        app(TenantContextStore::class)->set($a);
        $workflow = app(SchoolProfileWorkflow::class);
        $this->assertSame($a->tenantId, $workflow->read($request)->tenantId);
        $workflow->update($request, new SchoolProfileUpdate('Diperbarui', 'UTC', null));
        $this->assertSame('Diperbarui', DB::table('tenants')->where('id', $a->tenantId)->value('name'));
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('tenant_id', $a->tenantId)->where('action', 'school.profile_updated')->count());
        $this->assertNotSame('Diperbarui', DB::table('tenants')->where('id', $b->tenantId)->value('name'));

        DB::statement("CREATE FUNCTION pg_temp.m2_reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END $$");
        DB::statement('CREATE TRIGGER m2_reject_audit BEFORE INSERT ON tenant_audit_logs FOR EACH ROW EXECUTE FUNCTION pg_temp.m2_reject_audit()');
        try {
            $workflow->update($request, new SchoolProfileUpdate('Gagal Audit', 'UTC', null));
            $this->fail('Audit failure must roll back profile updates.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic audit failure', $exception->getMessage());
        }
        DB::statement('DROP TRIGGER m2_reject_audit ON tenant_audit_logs');
        $this->assertSame('Diperbarui', DB::table('tenants')->where('id', $a->tenantId)->value('name'));

        foreach (['version', 'host', 'permission'] as $revoke) {
            if ($revoke === 'version') {
                DB::table('tenant_memberships')->where('id', $member)->update(['auth_version' => 2]);
            } elseif ($revoke === 'host') {
                DB::table('tenant_memberships')->where('id', $member)->update(['auth_version' => 1]);
                $request->headers->set('host', $b->hostname);
            } else {
                $request->headers->set('host', $a->hostname);
                DB::table('role_permissions')->where('role_id', $role)->delete();
            }
            try {
                $workflow->update($request, new SchoolProfileUpdate('Terlarang', 'UTC', null));
                $this->fail('Invalid session or permission must be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame('Diperbarui', DB::table('tenants')->where('id', $a->tenantId)->value('name'));
        $this->assertSame(1, DB::table('tenant_audit_logs')->where('tenant_id', $a->tenantId)->where('action', 'school.profile_updated')->count());
    }

    private function context(string $slug): TenantContext
    {
        $tenant = $this->foundationTenant($slug);
        $ulid = DB::table('tenants')->where('id', $tenant)->value('public_ulid');
        $hostname = $slug.'.platform.test';
        $domain = DB::table('tenant_domains')->insertGetId([
            'tenant_id' => $tenant, 'hostname' => $hostname, 'status' => 'active', 'verified_at' => now(),
        ]);
        DB::table('tenant_settings')->insert(['tenant_id' => $tenant, 'onboarding_json' => '{}']);

        return new TenantContext($tenant, $ulid, $slug, 'active', (int) $domain, $hostname, 'active');
    }
}

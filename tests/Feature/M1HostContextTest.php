<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTenantHost;
use App\Modules\Tenancy\Data\TenantContext;
use App\Modules\Tenancy\Services\TenantResolver;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class M1HostContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_active_hosts_resolve_to_separate_immutable_contexts(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $schoolB = $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');

        $resolver = app(TenantResolver::class);
        $contextA = $resolver->resolve('sekolah-a.platform.test');
        $contextB = $resolver->resolve('sekolah-b.platform.test');

        $this->assertInstanceOf(TenantContext::class, $contextA);
        $this->assertInstanceOf(TenantContext::class, $contextB);
        $this->assertSame($schoolA, $contextA->tenantId);
        $this->assertSame($schoolB, $contextB->tenantId);
        $this->assertNotSame($contextA->tenantId, $contextB->tenantId);
        $this->assertTrue((new \ReflectionClass(TenantContext::class))->isReadOnly());
    }

    public function test_tenant_context_is_cleared_between_requests(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $schoolB = $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');
        $middleware = app(RequireTenantHost::class);
        $store = app(TenantContextStore::class);

        $contextFromA = $middleware->handle(
            $this->requestForHost('sekolah-a.platform.test'),
            static fn () => response()->json(['tenant_id' => $store->require()->tenantId]),
        );

        $this->assertSame($schoolA, $contextFromA->getData(true)['tenant_id']);
        $this->assertNull($store->current());

        $contextFromB = $middleware->handle(
            $this->requestForHost('sekolah-b.platform.test'),
            static fn () => response()->json(['tenant_id' => $store->require()->tenantId]),
        );

        $this->assertSame($schoolB, $contextFromB->getData(true)['tenant_id']);
        $this->assertNull($store->current());
    }

    public function test_forwarded_host_and_custom_tenant_input_cannot_override_direct_host(): void
    {
        $schoolA = $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');
        $this->tenantWithDomain('sekolah-b', 'sekolah-b.platform.test');

        $request = Request::create('/tenant/sekolah-b', 'GET', [
            'tenant_id' => 'sekolah-b',
        ], [], [], [
            'HTTP_HOST' => 'sekolah-a.platform.test',
            'HTTP_X_FORWARDED_HOST' => 'sekolah-b.platform.test',
            'HTTP_X_TENANT_ID' => 'sekolah-b',
        ]);

        $context = app(TenantResolver::class)->resolveFromRequest($request);

        $this->assertInstanceOf(TenantContext::class, $context);
        $this->assertSame($schoolA, $context->tenantId);
        $this->assertSame('sekolah-a.platform.test', $context->hostname);
    }

    public function test_unknown_revoked_unverified_and_pending_hosts_have_no_fallback(): void
    {
        $this->tenantWithDomain('pending-domain', 'pending-domain.platform.test', domainStatus: 'pending');
        $this->tenantWithDomain('revoked-domain', 'revoked-domain.platform.test', domainStatus: 'revoked');
        $this->tenantWithDomain('unverified-domain', 'unverified-domain.platform.test', domainStatus: 'pending', verifiedAt: null);
        $this->tenantWithDomain('pending-tenant', 'pending-tenant.platform.test', tenantStatus: 'pending');

        foreach ([
            'unknown.platform.test',
            'pending-domain.platform.test',
            'revoked-domain.platform.test',
            'unverified-domain.platform.test',
            'pending-tenant.platform.test',
            'localhost',
        ] as $host) {
            $this->get('http://'.$host.'/tenant')
                ->assertNotFound();
        }
    }

    public function test_valid_tenant_host_reaches_only_protected_placeholder(): void
    {
        $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');

        $this->get('http://sekolah-a.platform.test/tenant')
            ->assertForbidden();
    }

    public function test_central_host_is_allowlisted_and_never_matches_localhost_or_tenant_host(): void
    {
        $this->tenantWithDomain('sekolah-a', 'sekolah-a.platform.test');

        $this->get('http://'.config('saas.central_host').'/central')
            ->assertForbidden();

        $this->get('http://localhost/central')
            ->assertNotFound();

        $this->get('http://sekolah-a.platform.test/central')
            ->assertNotFound();
    }

    private function tenantWithDomain(
        string $slug,
        string $hostname,
        string $tenantStatus = 'active',
        string $domainStatus = 'active',
        ?string $verifiedAt = 'now',
    ): int {
        $tenantId = (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'slug' => $slug,
            'name' => 'Sekolah Dummy '.$slug,
            'status' => $tenantStatus,
        ]);

        DB::table('tenant_domains')->insert([
            'tenant_id' => $tenantId,
            'hostname' => $hostname,
            'kind' => 'default',
            'status' => $domainStatus,
            'verified_at' => $verifiedAt === null ? null : now(),
            'is_primary' => true,
        ]);

        return $tenantId;
    }

    private function requestForHost(string $host): Request
    {
        return Request::create('/', 'GET', server: ['HTTP_HOST' => $host]);
    }
}

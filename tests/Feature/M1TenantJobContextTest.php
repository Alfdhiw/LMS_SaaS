<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantContextStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Support\M1Fixtures;
use Tests\Support\TenantContextProbeJob;
use Tests\TestCase;

final class M1TenantJobContextTest extends TestCase
{
    use M1Fixtures, RefreshDatabase;

    public function test_real_queued_jobs_clear_context_between_tenants_and_after_failure(): void
    {
        $a = $this->fixtureTenant('job-a');
        $b = $this->fixtureTenant('job-b');
        TenantContextProbeJob::$observed = [];
        Queue::connection('sync')->push(new TenantContextProbeJob($a, 'job-a.platform.test'));
        $this->assertNull(app(TenantContextStore::class)->current());
        try {
            Queue::connection('sync')->push(new TenantContextProbeJob($a, 'job-a.platform.test', true));
            $this->fail('Expected synthetic job failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic job failure', $exception->getMessage());
        }
        $this->assertNull(app(TenantContextStore::class)->current());
        Queue::connection('sync')->push(new TenantContextProbeJob($b, 'job-b.platform.test'));
        $this->assertSame([$a, $a, $b], TenantContextProbeJob::$observed);
        $this->assertNull(app(TenantContextStore::class)->current());
    }

    public function test_forged_unknown_revoked_and_suspended_job_contexts_are_rejected_before_handle(): void
    {
        $a = $this->fixtureTenant('job-a');
        $b = $this->fixtureTenant('job-b');
        TenantContextProbeJob::$observed = [];
        foreach ([[$a, 'job-b.platform.test'], [$a, 'unknown.platform.test']] as [$id, $host]) {
            $this->assertRejected($id, $host);
        }
        DB::table('tenant_domains')->where('tenant_id', $b)->update(['status' => 'revoked', 'is_primary' => false]);
        $this->assertRejected($b, 'job-b.platform.test');
        DB::table('tenants')->where('id', $a)->update(['status' => 'suspended']);
        $this->assertRejected($a, 'job-a.platform.test');
        $this->assertSame([], TenantContextProbeJob::$observed);
        $this->assertDatabaseCount('tenant_audit_logs', 0);
    }

    private function assertRejected(int $tenantId, string $host): void
    {
        try {
            Queue::connection('sync')->push(new TenantContextProbeJob($tenantId, $host));
            $this->fail('Unverified job must not run.');
        } catch (LogicException $exception) {
            $this->assertSame('Queued tenant context could not be verified.', $exception->getMessage());
        }
        $this->assertNull(app(TenantContextStore::class)->current());
    }
}

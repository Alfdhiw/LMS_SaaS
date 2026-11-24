<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Exceptions\OperationException;
use App\Support\Http\Hostname;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ManageTenantRegistry implements TenantRegistry
{
    public function createPending(string $publicUlid, string $slug, string $name, string $timezone, string $hostname, int $actorId): int
    {
        $this->requireTransaction();
        $base = Hostname::normalize(config('saas.tenant_base_domain'));
        if ($base === null || Hostname::normalize($hostname) !== $slug.'.'.$base
            || $hostname === Hostname::normalize(config('saas.central_host'))
            || in_array($slug, config('saas.reserved_tenant_slugs', []), true)) {
            throw new OperationException('invalid_tenant_hostname', 422, 'Hostname tenant tidak valid.');
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new OperationException('invalid_timezone', 422, 'Timezone tenant tidak valid.');
        }
        if (DB::table('tenants')->where('slug', $slug)->exists()) {
            throw new OperationException('slug_collision', 409, 'Slug tenant sudah digunakan.');
        }
        if (DB::table('tenant_domains')->where('hostname', $hostname)->exists()) {
            throw new OperationException('domain_collision', 409, 'Hostname tenant sudah digunakan.');
        }
        $now = now();
        $id = (int) DB::table('tenants')->insertGetId([
            'public_ulid' => $publicUlid, 'slug' => $slug, 'name' => $name,
            'status' => 'pending', 'timezone' => $timezone, 'created_by_platform_user_id' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tenant_domains')->insert([
            'tenant_id' => $id, 'hostname' => $hostname, 'kind' => 'default', 'status' => 'active',
            'verified_at' => $now, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tenant_settings')->insert([
            'tenant_id' => $id,
            'onboarding_json' => json_encode(['admin_activation_required' => true, 'provisioned_at' => $now->toIso8601String()], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    public function lock(string $publicUlid): object
    {
        $this->requireTransaction();
        $tenant = DB::table('tenants')->where('public_ulid', $publicUlid)->lockForUpdate()->first();
        if ($tenant === null) {
            throw new OperationException('tenant_not_found', 404, 'Tenant tidak ditemukan.');
        }

        return $tenant;
    }

    public function lockContext(TenantContext $context, string $status = 'active'): object
    {
        $tenant = $this->lock($context->tenantPublicUlid);
        abort_unless((int) $tenant->id === $context->tenantId && $tenant->status === $status, 404);
        abort_unless(DB::table('tenant_domains')->where('tenant_id', $tenant->id)
            ->where('id', $context->domainId)->where('hostname', $context->hostname)
            ->where('status', 'active')->whereNotNull('verified_at')->exists(), 404);

        return $tenant;
    }

    public function transition(int $tenantId, string $action, string $expectedStatus): string
    {
        $this->requireTransaction();
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
        if ($tenant === null || $tenant->status !== $expectedStatus) {
            throw new OperationException('stale_tenant_status', 409, 'Status tenant sudah berubah.');
        }
        $target = $this->targetStatus($action, $expectedStatus);
        DB::table('tenants')->where('id', $tenantId)->update(['status' => $target, 'updated_at' => now()]);
        if ($action === 'archive') {
            DB::table('tenant_domains')->where('tenant_id', $tenantId)
                ->update(['status' => 'revoked', 'is_primary' => false, 'updated_at' => now()]);
        }

        return $target;
    }

    public function targetStatus(string $action, string $currentStatus): string
    {
        $transitions = [
            'activate' => ['pending' => 'active'], 'suspend' => ['active' => 'suspended'],
            'restore' => ['suspended' => 'active'], 'archive' => ['active' => 'archived'],
        ];
        $target = $transitions[$action][$currentStatus] ?? null;
        if ($target === null) {
            throw new OperationException('invalid_tenant_transition', 409, 'Transisi status tenant tidak diizinkan.');
        }

        return $target;
    }

    public function completeAdminActivation(TenantContext $context): void
    {
        $this->lockContext($context, 'pending');
        $settings = DB::table('tenant_settings')->where('tenant_id', $context->tenantId)->lockForUpdate()->first();
        abort_if($settings === null, 409);
        $onboarding = json_decode($settings->onboarding_json, true, 512, JSON_THROW_ON_ERROR);
        $onboarding['admin_activation_required'] = false;
        $onboarding['admin_activated_at'] = now()->toIso8601String();
        DB::table('tenant_settings')->where('tenant_id', $context->tenantId)->update([
            'onboarding_json' => json_encode($onboarding, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Tenant writes require an owning workflow transaction.');
        }
    }
}

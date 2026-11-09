<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Data\TenantContext;
use App\Modules\Tenancy\Support\Hostname;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantResolver
{
    public function resolveFromRequest(Request $request): ?TenantContext
    {
        return $this->resolve(Hostname::fromRequest($request));
    }

    public function resolve(?string $hostname): ?TenantContext
    {
        $hostname = Hostname::normalize($hostname);

        if ($hostname === null || Hostname::isLoopback($hostname)) {
            return null;
        }

        $row = DB::table('tenant_domains as domains')
            ->join('tenants', 'tenants.id', '=', 'domains.tenant_id')
            ->where('domains.hostname', $hostname)
            ->where('domains.status', 'active')
            ->whereNotNull('domains.verified_at')
            ->where('tenants.status', 'active')
            ->select([
                'tenants.id as tenant_id',
                'tenants.public_ulid as tenant_public_ulid',
                'tenants.slug as tenant_slug',
                'tenants.status as tenant_status',
                'domains.id as domain_id',
                'domains.hostname',
                'domains.status as domain_status',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return new TenantContext(
            tenantId: (int) $row->tenant_id,
            tenantPublicUlid: $row->tenant_public_ulid,
            tenantSlug: $row->tenant_slug,
            tenantStatus: $row->tenant_status,
            domainId: (int) $row->domain_id,
            hostname: $row->hostname,
            domainStatus: $row->domain_status,
        );
    }
}

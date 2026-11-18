<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\DB;

final class TenantDirectoryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return DB::table('tenants')
            ->leftJoin('tenant_domains', function ($join): void {
                $join->on('tenant_domains.tenant_id', '=', 'tenants.id')
                    ->where('tenant_domains.is_primary', true)
                    ->whereIn('tenant_domains.status', ['active', 'pending']);
            })
            ->leftJoin('account_invitations', function ($join): void {
                $join->on('account_invitations.tenant_id', '=', 'tenants.id')
                    ->where('account_invitations.purpose', 'bootstrap_admin')
                    ->where('account_invitations.status', 'pending');
            })
            ->orderBy('tenants.created_at')
            ->get([
                'tenants.public_ulid',
                'tenants.slug',
                'tenants.name',
                'tenants.status',
                'tenants.timezone',
                'tenant_domains.hostname',
                'account_invitations.public_ulid as bootstrap_invitation_public_ulid',
                'tenants.created_at',
            ])
            ->map(fn (object $tenant): array => (array) $tenant)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $tenantPublicUlid): ?array
    {
        $tenant = DB::table('tenants')
            ->leftJoin('tenant_domains', function ($join): void {
                $join->on('tenant_domains.tenant_id', '=', 'tenants.id')
                    ->where('tenant_domains.is_primary', true)
                    ->whereIn('tenant_domains.status', ['active', 'pending']);
            })
            ->leftJoin('account_invitations', function ($join): void {
                $join->on('account_invitations.tenant_id', '=', 'tenants.id')
                    ->where('account_invitations.purpose', 'bootstrap_admin')
                    ->where('account_invitations.status', 'pending');
            })
            ->where('tenants.public_ulid', $tenantPublicUlid)
            ->first([
                'tenants.public_ulid',
                'tenants.slug',
                'tenants.name',
                'tenants.status',
                'tenants.timezone',
                'tenant_domains.hostname',
                'account_invitations.public_ulid as bootstrap_invitation_public_ulid',
                'tenants.created_at',
            ]);

        return $tenant === null ? null : (array) $tenant;
    }
}

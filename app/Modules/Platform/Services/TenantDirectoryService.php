<?php

namespace App\Modules\Platform\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TenantDirectoryService
{
    /** @param array<string, mixed> $filters */
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('tenants')
            ->select([
                'tenants.public_ulid',
                'tenants.slug',
                'tenants.name',
                'tenants.status',
                'tenants.timezone',
                'tenants.created_at',
            ])->selectSub($this->primaryHostname(), 'hostname')
            ->selectSub($this->latestBootstrapStatus(), 'bootstrap_status')
            ->selectSub($this->lastOperationAt(), 'last_operation_at');

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q']));
            $query->where(function ($q) use ($search): void {
                $q->where('tenants.name', 'ilike', '%'.$search.'%')
                    ->orWhere('tenants.slug', 'ilike', '%'.$search.'%');
            });
        }
        if (! empty($filters['status'])) {
            $query->where('tenants.status', $filters['status']);
        }

        return $query->orderByDesc('tenants.created_at')->orderByDesc('tenants.id')->paginate(15)
            ->withQueryString()->through(static fn (object $tenant): array => (array) $tenant);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $tenantPublicUlid): ?array
    {
        $tenant = DB::table('tenants')
            ->where('tenants.public_ulid', $tenantPublicUlid)
            ->select([
                'tenants.public_ulid',
                'tenants.slug',
                'tenants.name',
                'tenants.status',
                'tenants.timezone',
                'tenants.created_at',
            ])
            ->selectSub($this->primaryHostname(), 'hostname')
            ->selectSub($this->latestBootstrapStatus(), 'bootstrap_status')
            ->selectSub($this->lastOperationAt(), 'last_operation_at')
            ->selectSub(
                DB::table('account_invitations')->select('public_ulid')
                    ->whereColumn('account_invitations.tenant_id', 'tenants.id')
                    ->where('purpose', 'bootstrap_admin')->where('status', 'pending')
                    ->orderByRaw('created_at DESC NULLS LAST')->orderByDesc('id')->limit(1),
                'bootstrap_invitation_public_ulid',
            )->first();

        return $tenant === null ? null : (array) $tenant;
    }

    private function primaryHostname(): Builder
    {
        return DB::table('tenant_domains')->select('hostname')
            ->whereColumn('tenant_domains.tenant_id', 'tenants.id')
            ->where('is_primary', true)->whereIn('status', ['active', 'pending'])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('id')->limit(1);
    }

    private function latestBootstrapStatus(): Builder
    {
        return DB::table('account_invitations')->select('status')
            ->whereColumn('account_invitations.tenant_id', 'tenants.id')->where('purpose', 'bootstrap_admin')
            ->orderByRaw('created_at DESC NULLS LAST')->orderByDesc('id')->limit(1);
    }

    private function lastOperationAt(): Builder
    {
        return DB::table('platform_audit_logs')->select('created_at')
            ->whereColumn('platform_audit_logs.tenant_id', 'tenants.id')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }
}

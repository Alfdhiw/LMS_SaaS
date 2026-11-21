<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\DB;

final class PlatformDashboardService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $counts = DB::table('tenants')->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');
        $statuses = [];
        foreach (['pending', 'active', 'suspended', 'archived'] as $status) {
            $statuses[$status] = (int) ($counts[$status] ?? 0);
        }

        $failure = DB::table('tenant_provisioning_requests')->where('status', 'failed')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['failure_code', 'created_at']);
        $knownCodes = [
            'role_catalog_missing', 'slug_collision', 'domain_collision', 'admin_email_exists',
            'invalid_tenant_hostname', 'provisioning_conflict', 'provisioning_failed',
            'invalid_timezone', 'reserved_slug', 'provisioning_record_incomplete',
        ];

        return [
            'statuses' => $statuses,
            'total' => array_sum($statuses),
            'new_last_30_days' => DB::table('tenants')->where('created_at', '>=', now()->subDays(30))->count(),
            'pending_invitations' => DB::table('account_invitations')
                ->join('tenants', 'tenants.id', '=', 'account_invitations.tenant_id')
                ->where('tenants.status', 'pending')->where('account_invitations.purpose', 'bootstrap_admin')
                ->where('account_invitations.status', 'pending')->distinct()->count('tenants.id'),
            'last_failure' => $failure === null ? null : [
                'code' => in_array($failure->failure_code, $knownCodes, true) ? $failure->failure_code : 'other',
                'created_at' => $failure->created_at,
            ],
        ];
    }
}

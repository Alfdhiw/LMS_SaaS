<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\TenantMemberships;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ManageTenantMemberships implements TenantMemberships
{
    public function hasActiveAdmin(int $tenantId): bool
    {
        return DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('membership_roles as mr', function ($join): void {
                $join->on('mr.membership_id', '=', 'm.id')->on('mr.tenant_id', '=', 'm.tenant_id');
            })->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('m.tenant_id', $tenantId)->where('m.status', 'active')->where('u.status', 'active')
            ->where('r.key', 'school_admin')->exists();
    }

    public function canArchive(int $tenantId): bool
    {
        return ! DB::table('tenant_memberships')->where('tenant_id', $tenantId)->whereIn('status', ['active', 'invited'])->exists()
            && ! DB::table('account_invitations')->where('tenant_id', $tenantId)->where('status', 'pending')->exists();
    }

    public function revokeSessions(int $tenantId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Membership revocation requires the tenant workflow transaction.');
        }
        DB::table('tenant_memberships')->where('tenant_id', $tenantId)->increment('auth_version');
    }
}

<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Tenancy\Data\TenantContext;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Support\Facades\DB;

final class PermissionChecker
{
    public function __construct(private readonly TenantContextStore $contextStore) {}

    public function allows(
        User $user,
        TenantContext $context,
        int $membershipId,
        string $permission,
    ): bool {
        return DB::table('tenant_memberships as memberships')
            ->join('membership_roles as membership_roles', function ($join): void {
                $join->on('membership_roles.membership_id', '=', 'memberships.id')
                    ->on('membership_roles.tenant_id', '=', 'memberships.tenant_id');
            })
            ->join('role_permissions as role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
            ->join('permissions', function ($join): void {
                $join->on('permissions.id', '=', 'role_permissions.permission_id')
                    ->where('permissions.scope', 'tenant');
            })
            ->where('memberships.id', $membershipId)
            ->where('memberships.tenant_id', $context->tenantId)
            ->where('memberships.user_id', $user->getAuthIdentifier())
            ->where('memberships.status', 'active')
            ->where('role_permissions.permission_scope', 'tenant')
            ->where('permissions.key', $permission)
            ->exists();
    }

    public function allowsCurrent(User $user, int $membershipId, string $permission): bool
    {
        return $this->allows($user, $this->contextStore->require(), $membershipId, $permission);
    }
}

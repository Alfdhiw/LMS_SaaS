<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Data\TenantLoginIdentity;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Tenancy\Data\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TenantAuthenticator
{
    public function attempt(
        TenantContext $context,
        string $username,
        string $password,
    ): ?TenantLoginIdentity {
        $row = DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.tenant_id', $context->tenantId)
            ->where('memberships.local_username_normalized', IdentityNormalizer::username($username))
            ->where('memberships.status', 'active')
            ->where('tenants.status', 'active')
            ->select([
                'memberships.id as membership_id',
                'memberships.user_id',
                'memberships.auth_version as membership_auth_version',
                'users.auth_version as user_auth_version',
                'users.status as user_status',
                'users.password',
            ])
            ->first();

        if ($row === null || $row->user_status !== 'active' || ! Hash::check($password, (string) $row->password)) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);

        if ($user === null) {
            return null;
        }

        return new TenantLoginIdentity(
            user: $user,
            membershipId: (int) $row->membership_id,
            userAuthVersion: (int) $row->user_auth_version,
            membershipAuthVersion: (int) $row->membership_auth_version,
        );
    }
}

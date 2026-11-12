<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlatformPermissionChecker
{
    public function allows(User $user, string $permission): bool
    {
        $staff = DB::table('platform_staff')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->first(['role']);

        if ($staff === null) {
            return false;
        }

        $mappedPermissions = config('saas.platform_role_permissions.'.(string) $staff->role, []);

        if (! in_array($permission, $mappedPermissions, true)) {
            return false;
        }

        return DB::table('permissions')
            ->where('key', $permission)
            ->where('scope', 'platform')
            ->exists();
    }
}

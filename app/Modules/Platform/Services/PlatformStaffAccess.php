<?php

namespace App\Modules\Platform\Services;

use App\Support\Auth\CentralStaffAccess;
use Illuminate\Support\Facades\DB;

final class PlatformStaffAccess implements CentralStaffAccess
{
    public function isActiveOperator(int $userId): bool
    {
        return DB::table('platform_staff')->where('user_id', $userId)->where('status', 'active')->where('role', 'super_admin')->exists();
    }

    public function isStaff(int $userId): bool
    {
        return DB::table('platform_staff')->where('user_id', $userId)->exists();
    }
}

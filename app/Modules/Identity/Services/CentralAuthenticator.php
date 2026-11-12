<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Data\CentralLoginIdentity;
use App\Modules\Identity\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CentralAuthenticator
{
    public function attempt(string $email, string $password): ?CentralLoginIdentity
    {
        $row = DB::table('users')
            ->join('platform_staff', 'platform_staff.user_id', '=', 'users.id')
            ->where('users.email_normalized', IdentityNormalizer::email($email))
            ->whereNotNull('users.email_verified_at')
            ->where('users.status', 'active')
            ->where('platform_staff.status', 'active')
            ->where('platform_staff.role', 'super_admin')
            ->select([
                'users.id',
                'users.auth_version',
                'users.password',
            ])
            ->first();

        if ($row === null || ! Hash::check($password, (string) $row->password)) {
            return null;
        }

        $user = User::query()->find((int) $row->id);

        if ($user === null) {
            return null;
        }

        return new CentralLoginIdentity(
            user: $user,
            userAuthVersion: (int) $row->auth_version,
        );
    }
}

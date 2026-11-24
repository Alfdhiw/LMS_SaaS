<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Data\CentralLoginIdentity;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Support\Auth\CentralStaffAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CentralAuthenticator
{
    public function __construct(private readonly CentralStaffAccess $staff) {}

    public function attempt(string $email, string $password): ?CentralLoginIdentity
    {
        $row = DB::table('users')
            ->where('users.email_normalized', IdentityNormalizer::email($email))
            ->whereNotNull('users.email_verified_at')
            ->where('users.status', 'active')
            ->select([
                'users.id',
                'users.auth_version',
                'users.password',
            ])
            ->first();

        if ($row === null || ! $this->staff->isActiveOperator((int) $row->id) || ! Hash::check($password, (string) $row->password)) {
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

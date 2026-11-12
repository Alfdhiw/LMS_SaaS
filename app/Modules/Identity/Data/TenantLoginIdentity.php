<?php

namespace App\Modules\Identity\Data;

use App\Models\User;

final readonly class TenantLoginIdentity
{
    public function __construct(
        public User $user,
        public int $membershipId,
        public int $userAuthVersion,
        public int $membershipAuthVersion,
    ) {}
}

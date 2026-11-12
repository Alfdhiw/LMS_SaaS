<?php

namespace App\Modules\Identity\Data;

use App\Models\User;

final readonly class CentralLoginIdentity
{
    public function __construct(
        public User $user,
        public int $userAuthVersion,
    ) {}
}

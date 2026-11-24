<?php

namespace App\Support\Auth;

interface CentralStaffAccess
{
    public function isActiveOperator(int $userId): bool;

    public function isStaff(int $userId): bool;
}

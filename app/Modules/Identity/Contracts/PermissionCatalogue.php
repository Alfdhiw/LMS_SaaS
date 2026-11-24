<?php

namespace App\Modules\Identity\Contracts;

interface PermissionCatalogue
{
    public function containsPlatformPermission(string $key): bool;
}

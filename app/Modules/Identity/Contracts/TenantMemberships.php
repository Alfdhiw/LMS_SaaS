<?php

namespace App\Modules\Identity\Contracts;

interface TenantMemberships
{
    public function hasActiveAdmin(int $tenantId): bool;

    public function canArchive(int $tenantId): bool;

    public function revokeSessions(int $tenantId): void;
}

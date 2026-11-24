<?php

namespace App\Modules\Tenancy\Contracts;

use App\Support\Tenancy\TenantContext;

interface TenantRegistry
{
    public function createPending(string $publicUlid, string $slug, string $name, string $timezone, string $hostname, int $actorId): int;

    public function lock(string $publicUlid): object;

    public function lockContext(TenantContext $context, string $status = 'active'): object;

    public function transition(int $tenantId, string $action, string $expectedStatus): string;

    public function targetStatus(string $action, string $currentStatus): string;

    public function completeAdminActivation(TenantContext $context): void;
}

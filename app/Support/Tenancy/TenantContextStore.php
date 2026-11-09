<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Data\TenantContext;
use LogicException;

final class TenantContextStore
{
    private ?TenantContext $context = null;

    public function set(TenantContext $context): void
    {
        if ($this->context !== null) {
            throw new LogicException('Tenant context is already set for this request.');
        }

        $this->context = $context;
    }

    public function current(): ?TenantContext
    {
        return $this->context;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw new LogicException('Tenant context is not available.');
    }

    public function clear(): void
    {
        $this->context = null;
    }
}

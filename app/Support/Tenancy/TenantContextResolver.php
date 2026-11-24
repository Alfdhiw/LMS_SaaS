<?php

namespace App\Support\Tenancy;

interface TenantContextResolver
{
    public function resolve(?string $hostname): ?TenantContext;
}

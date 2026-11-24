<?php

namespace App\Support\Tenancy;

final readonly class TenantContext
{
    public function __construct(
        public int $tenantId,
        public string $tenantPublicUlid,
        public string $tenantSlug,
        public string $tenantStatus,
        public int $domainId,
        public string $hostname,
        public string $domainStatus,
    ) {}
}

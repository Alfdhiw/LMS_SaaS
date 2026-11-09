<?php

namespace App\Modules\Tenancy\Data;

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

<?php

namespace App\Modules\Tenancy\Data;

final readonly class SchoolProfile
{
    public function __construct(
        public int $tenantId,
        public string $publicUlid,
        public string $name,
        public string $timezone,
        public ?string $accentColor,
    ) {}
}

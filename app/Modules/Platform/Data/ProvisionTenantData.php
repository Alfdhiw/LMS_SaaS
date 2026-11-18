<?php

namespace App\Modules\Platform\Data;

use App\Modules\Identity\Support\IdentityNormalizer;

final readonly class ProvisionTenantData
{
    public function __construct(
        public string $schoolName,
        public string $slug,
        public string $timezone,
        public string $adminName,
        public string $adminEmail,
        public string $adminUsername,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            schoolName: trim((string) $validated['school_name']),
            slug: strtolower(trim((string) $validated['slug'])),
            timezone: trim((string) $validated['timezone']),
            adminName: trim((string) $validated['admin_name']),
            adminEmail: IdentityNormalizer::email((string) $validated['admin_email']),
            adminUsername: IdentityNormalizer::username((string) $validated['admin_username']),
        );
    }

    /**
     * @return array<string, string>
     */
    public function canonicalPayload(): array
    {
        return [
            'admin_email' => $this->adminEmail,
            'admin_name' => $this->adminName,
            'admin_username' => $this->adminUsername,
            'school_name' => $this->schoolName,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
        ];
    }
}

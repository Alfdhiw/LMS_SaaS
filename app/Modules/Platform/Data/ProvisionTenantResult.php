<?php

namespace App\Modules\Platform\Data;

final readonly class ProvisionTenantResult
{
    public function __construct(
        public string $tenantPublicUlid,
        public string $tenantSlug,
        public string $tenantHostname,
        public string $tenantStatus,
        public string $invitationPublicUlid,
        public ?string $invitationToken,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeToken): array
    {
        $result = [
            'tenant_public_ulid' => $this->tenantPublicUlid,
            'tenant_slug' => $this->tenantSlug,
            'tenant_hostname' => $this->tenantHostname,
            'tenant_status' => $this->tenantStatus,
            'invitation_public_ulid' => $this->invitationPublicUlid,
            'idempotent_replay' => $this->replayed,
        ];

        if ($includeToken && $this->invitationToken !== null) {
            $result['invitation_token'] = $this->invitationToken;
            $result['activation_path'] = '/activate/'.$this->invitationPublicUlid.'/'.$this->invitationToken;
        }

        return $result;
    }
}

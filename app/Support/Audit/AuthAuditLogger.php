<?php

namespace App\Support\Audit;

use App\Modules\Tenancy\Data\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthAuditLogger
{
    public function tenant(
        Request $request,
        TenantContext $context,
        string $action,
        ?int $actorUserId = null,
        ?int $actorMembershipId = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        DB::table('tenant_audit_logs')->insert([
            'tenant_id' => $context->tenantId,
            'actor_user_id' => $actorUserId,
            'actor_membership_id' => $actorMembershipId,
            'action' => $action,
            'target_type' => 'authentication',
            'target_id' => $targetId,
            'request_id' => $this->requestId($request),
            'metadata_json' => $this->metadata($metadata),
            'created_at' => now(),
        ]);
    }

    public function platform(
        Request $request,
        string $action,
        ?int $actorUserId = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        DB::table('platform_audit_logs')->insert([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => 'authentication',
            'target_id' => $targetId,
            'request_id' => $this->requestId($request),
            'metadata_json' => $this->metadata($metadata),
            'created_at' => now(),
        ]);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->attributes->get('request_id') ?: Str::uuid());
    }

    private function metadata(array $metadata): string
    {
        return json_encode(
            $metadata === [] ? new \stdClass : $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}

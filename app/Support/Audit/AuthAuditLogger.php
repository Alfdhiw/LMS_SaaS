<?php

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;
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
        string $targetType = 'authentication',
    ): void {
        DB::table('tenant_audit_logs')->insert([
            'tenant_id' => $context->tenantId,
            'actor_user_id' => $actorUserId,
            'actor_membership_id' => $actorMembershipId,
            'action' => $action,
            'target_type' => $targetType,
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
        ?int $tenantId = null,
        string $targetType = 'authentication',
    ): void {
        DB::table('platform_audit_logs')->insert([
            'actor_user_id' => $actorUserId,
            'tenant_id' => $tenantId,
            'action' => $action,
            'target_type' => $targetType,
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
        $this->rejectSensitiveKeys($metadata);

        return json_encode(
            $metadata === [] ? new \stdClass : $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function rejectSensitiveKeys(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|token|secret|authorization|cookie|session|credential|recovery.?code|api.?key|private.?key|email/i', $key) === 1) {
                throw new \InvalidArgumentException('Sensitive audit metadata field is not allowed.');
            }
            if (is_array($value)) {
                $this->rejectSensitiveKeys($value);
            } elseif (is_object($value)) {
                throw new \InvalidArgumentException('Audit metadata values must not contain objects.');
            }
        }
    }
}

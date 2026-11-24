<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\TenantMemberships;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Exceptions\OperationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantLifecycleService
{
    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        private readonly AuthAuditLogger $auditLogger,
        private readonly TenantRegistry $tenants,
        private readonly TenantMemberships $memberships,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function transition(
        Request $request,
        User $actor,
        string $tenantPublicUlid,
        string $action,
        string $expectedStatus,
        string $reason,
    ): array {
        if (! $this->permissions->allows($actor, 'platform.tenants.transition')) {
            throw new OperationException('forbidden', 403, 'Aktor tidak memiliki izin lifecycle tenant.');
        }

        return DB::transaction(function () use ($request, $actor, $tenantPublicUlid, $action, $expectedStatus, $reason): array {
            $tenant = $this->tenants->lock($tenantPublicUlid);

            if ($tenant->status !== $expectedStatus) {
                throw new OperationException(
                    'stale_tenant_status',
                    409,
                    'Status tenant sudah berubah; ulangi dengan expected_status terbaru.',
                );
            }

            $this->tenants->targetStatus($action, (string) $tenant->status);
            if (in_array($action, ['activate', 'restore'], true) && ! $this->memberships->hasActiveAdmin((int) $tenant->id)) {
                throw new OperationException(
                    'readiness_admin_not_active',
                    422,
                    'Tenant belum memiliki admin sekolah aktif.',
                );
            }

            if ($action === 'archive' && ! $this->memberships->canArchive((int) $tenant->id)) {
                throw new OperationException('archive_checklist_incomplete', 422, 'Tenant belum memenuhi checklist offboarding untuk archive.');
            }

            $targetStatus = $this->tenants->transition((int) $tenant->id, $action, $expectedStatus);
            if (in_array($action, ['suspend', 'archive'], true)) {
                $this->memberships->revokeSessions((int) $tenant->id);
            }

            $this->auditLogger->platform(
                request: $request,
                action: 'tenant.transitioned',
                actorUserId: (int) $actor->getAuthIdentifier(),
                targetId: (string) $tenant->public_ulid,
                metadata: [
                    'action' => $action,
                    'from' => $tenant->status,
                    'to' => $targetStatus,
                    'expected_status' => $expectedStatus,
                    'reason' => $reason,
                ],
                tenantId: (int) $tenant->id,
                targetType: 'tenant',
            );

            return [
                'tenant_public_ulid' => (string) $tenant->public_ulid,
                'tenant_slug' => (string) $tenant->slug,
                'from' => (string) $tenant->status,
                'to' => $targetStatus,
                'status' => $targetStatus,
            ];
        });
    }
}

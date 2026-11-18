<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Services\PlatformPermissionChecker;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantLifecycleService
{
    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        private readonly AuthAuditLogger $auditLogger,
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
            throw new PlatformOperationException('forbidden', 403, 'Aktor tidak memiliki izin lifecycle tenant.');
        }

        return DB::transaction(function () use ($request, $actor, $tenantPublicUlid, $action, $expectedStatus, $reason): array {
            $tenant = DB::table('tenants')
                ->where('public_ulid', $tenantPublicUlid)
                ->lockForUpdate()
                ->first();

            if ($tenant === null) {
                throw new PlatformOperationException('tenant_not_found', 404, 'Tenant tidak ditemukan.');
            }

            if ($tenant->status !== $expectedStatus) {
                throw new PlatformOperationException(
                    'stale_tenant_status',
                    409,
                    'Status tenant sudah berubah; ulangi dengan expected_status terbaru.',
                );
            }

            $targetStatus = $this->targetStatus($action, (string) $tenant->status);

            if (in_array($action, ['activate', 'restore'], true) && ! $this->hasActiveSchoolAdmin((int) $tenant->id)) {
                throw new PlatformOperationException(
                    'readiness_admin_not_active',
                    422,
                    'Tenant belum memiliki admin sekolah aktif.',
                );
            }

            if ($action === 'archive') {
                $this->assertArchiveReady((int) $tenant->id);
            }

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'status' => $targetStatus,
                    'updated_at' => now(),
                ]);

            if (in_array($action, ['suspend', 'archive'], true)) {
                DB::table('tenant_memberships')
                    ->where('tenant_id', $tenant->id)
                    ->increment('auth_version');
            }

            if ($action === 'archive') {
                DB::table('tenant_domains')
                    ->where('tenant_id', $tenant->id)
                    ->update([
                        'status' => 'revoked',
                        'is_primary' => false,
                        'updated_at' => now(),
                    ]);
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

    private function targetStatus(string $action, string $currentStatus): string
    {
        $transitions = [
            'activate' => ['pending' => 'active'],
            'suspend' => ['active' => 'suspended'],
            'restore' => ['suspended' => 'active'],
            'archive' => ['active' => 'archived'],
        ];

        if (! isset($transitions[$action][$currentStatus])) {
            throw new PlatformOperationException(
                'invalid_tenant_transition',
                409,
                'Transisi status tenant tidak diizinkan.',
            );
        }

        return $transitions[$action][$currentStatus];
    }

    private function hasActiveSchoolAdmin(int $tenantId): bool
    {
        return DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->join('membership_roles as membership_roles', function ($join): void {
                $join->on('membership_roles.membership_id', '=', 'memberships.id')
                    ->on('membership_roles.tenant_id', '=', 'memberships.tenant_id');
            })
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->where('users.status', 'active')
            ->where('roles.key', 'school_admin')
            ->exists();
    }

    private function assertArchiveReady(int $tenantId): void
    {
        $hasOpenMembership = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'invited'])
            ->exists();
        $hasPendingInvitation = DB::table('account_invitations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->exists();

        if ($hasOpenMembership || $hasPendingInvitation) {
            throw new PlatformOperationException(
                'archive_checklist_incomplete',
                422,
                'Tenant belum memenuhi checklist offboarding untuk archive.',
            );
        }
    }
}

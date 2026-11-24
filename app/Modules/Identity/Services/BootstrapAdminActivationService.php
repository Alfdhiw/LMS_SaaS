<?php

namespace App\Modules\Identity\Services;

use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class BootstrapAdminActivationService
{
    public function __construct(private readonly AuthAuditLogger $auditLogger, private readonly TenantRegistry $tenants) {}

    /**
     * @return array{display_name: string, email: string, username: string}|null
     */
    public function inspect(TenantContext $context, string $invitationPublicUlid, string $token): ?array
    {
        $invitation = $this->pendingInvitation($context, $invitationPublicUlid, $token);

        if ($invitation === null) {
            return null;
        }

        return [
            'display_name' => (string) $invitation->display_name,
            'email' => (string) $invitation->email,
            'username' => (string) $invitation->local_username,
        ];
    }

    public function activate(
        Request $request,
        TenantContext $context,
        string $invitationPublicUlid,
        string $token,
        string $password,
    ): bool {
        return DB::transaction(function () use ($request, $context, $invitationPublicUlid, $token, $password): bool {
            $tenant = $this->tenants->lockContext($context, 'pending');
            $invitation = $this->pendingInvitation(
                context: $context,
                invitationPublicUlid: $invitationPublicUlid,
                token: $token,
                lock: true,
            );

            if ($invitation === null) {
                return false;
            }

            $user = DB::table('users')
                ->where('id', $invitation->target_user_id)
                ->lockForUpdate()
                ->first();
            $membership = DB::table('tenant_memberships')
                ->where('id', $invitation->membership_id)
                ->where('tenant_id', $context->tenantId)
                ->lockForUpdate()
                ->first();

            if (
                $tenant === null
                || $tenant->status !== 'pending'
                || $user === null
                || $membership === null
                || $user->status !== 'pending'
                || $membership->status !== 'invited'
            ) {
                return false;
            }

            $now = now();
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'password' => Hash::make($password),
                    'status' => 'active',
                    'email_verified_at' => $now,
                    'password_changed_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('tenant_memberships')
                ->where('id', $membership->id)
                ->where('tenant_id', $context->tenantId)
                ->update([
                    'status' => 'active',
                    'joined_at' => $now,
                    'auth_version' => (int) $membership->auth_version + 1,
                    'updated_at' => $now,
                ]);

            DB::table('account_invitations')
                ->where('id', $invitation->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'consumed',
                    'consumed_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->tenants->completeAdminActivation($context);

            $this->auditLogger->tenant(
                request: $request,
                context: $context,
                action: 'tenant.admin_activated',
                actorUserId: (int) $user->id,
                actorMembershipId: (int) $membership->id,
                targetId: (string) $invitation->public_ulid,
                metadata: ['outcome' => 'succeeded'],
                targetType: 'account_invitation',
            );
            $this->auditLogger->platform(
                request: $request,
                action: 'tenant.admin_activated',
                actorUserId: (int) $user->id,
                targetId: (string) $invitation->public_ulid,
                metadata: ['outcome' => 'succeeded'],
                tenantId: $context->tenantId,
                targetType: 'account_invitation',
            );

            return true;
        });
    }

    private function pendingInvitation(
        TenantContext $context,
        string $invitationPublicUlid,
        string $token,
        bool $lock = false,
    ): ?object {
        $query = DB::table('account_invitations as invitations')
            ->join('users', 'users.id', '=', 'invitations.target_user_id')
            ->join('tenant_memberships as memberships', function ($join): void {
                $join->on('memberships.id', '=', 'invitations.membership_id')
                    ->on('memberships.tenant_id', '=', 'invitations.tenant_id');
            })
            ->where('invitations.public_ulid', $invitationPublicUlid)
            ->where('invitations.tenant_id', $context->tenantId)
            ->where('invitations.purpose', 'bootstrap_admin')
            ->where('invitations.status', 'pending')
            ->where('invitations.token_hash', hash('sha256', $token))
            ->where('invitations.expires_at', '>', now())
            ->select([
                'invitations.id',
                'invitations.public_ulid',
                'invitations.target_user_id',
                'invitations.membership_id',
                'users.email',
                'users.status as user_status',
                'memberships.display_name',
                'memberships.local_username',
                'memberships.status as membership_status',
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}

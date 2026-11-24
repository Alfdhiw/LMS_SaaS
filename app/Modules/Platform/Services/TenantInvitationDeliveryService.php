<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\BootstrapAdminAccounts;
use App\Modules\Platform\Data\ProvisionTenantResult;
use App\Modules\Platform\Mail\BootstrapAdminInvitation;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Exceptions\OperationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class TenantInvitationDeliveryService
{
    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        private readonly AuthAuditLogger $auditLogger,
        private readonly TenantRegistry $tenants,
        private readonly BootstrapAdminAccounts $bootstrap,
    ) {}

    public function sendProvisioned(
        Request $request,
        User $actor,
        ProvisionTenantResult $result,
    ): string {
        if ($result->invitationToken === null) {
            return 'not_sent';
        }

        return $this->send(
            request: $request,
            actor: $actor,
            tenantPublicUlid: $result->tenantPublicUlid,
            invitationPublicUlid: $result->invitationPublicUlid,
            token: $result->invitationToken,
        );
    }

    public function resend(
        Request $request,
        User $actor,
        string $tenantPublicUlid,
        string $invitationPublicUlid,
    ): ProvisionTenantResult {
        if (! $this->permissions->allows($actor, 'platform.tenants.update')) {
            throw new OperationException(
                failureCode: 'forbidden',
                statusCode: 403,
                message: 'Aktor tidak memiliki izin mengirim ulang invitation.',
            );
        }

        $result = DB::transaction(function () use ($request, $actor, $tenantPublicUlid, $invitationPublicUlid): ProvisionTenantResult {
            $this->tenants->lock($tenantPublicUlid);
            $invitation = DB::table('account_invitations as invitations')
                ->join('tenants', 'tenants.id', '=', 'invitations.tenant_id')
                ->join('users', 'users.id', '=', 'invitations.target_user_id')
                ->join('tenant_memberships as memberships', function ($join): void {
                    $join->on('memberships.id', '=', 'invitations.membership_id')
                        ->on('memberships.tenant_id', '=', 'invitations.tenant_id');
                })
                ->join('tenant_domains as domains', function ($join): void {
                    $join->on('domains.tenant_id', '=', 'tenants.id')
                        ->where('domains.is_primary', true)
                        ->whereIn('domains.status', ['active', 'pending']);
                })
                ->where('tenants.public_ulid', $tenantPublicUlid)
                ->where('invitations.public_ulid', $invitationPublicUlid)
                ->lockForUpdate()
                ->first([
                    'tenants.id as tenant_id',
                    'tenants.public_ulid as tenant_public_ulid',
                    'tenants.slug as tenant_slug',
                    'tenants.name as tenant_name',
                    'tenants.status as tenant_status',
                    'domains.hostname as tenant_hostname',
                    'invitations.id as invitation_id',
                    'invitations.public_ulid as invitation_public_ulid',
                    'invitations.purpose',
                    'invitations.status as invitation_status',
                    'invitations.target_user_id',
                    'users.status as user_status',
                    'memberships.status as membership_status',
                ]);

            if ($invitation === null) {
                throw new OperationException(
                    failureCode: 'invitation_not_found',
                    statusCode: 404,
                    message: 'Invitation tidak ditemukan.',
                );
            }

            if (
                $invitation->purpose !== 'bootstrap_admin'
                || $invitation->invitation_status !== 'pending'
                || $invitation->tenant_status !== 'pending'
                || $invitation->user_status !== 'pending'
                || $invitation->membership_status !== 'invited'
            ) {
                throw new OperationException(
                    failureCode: 'invitation_not_resendable',
                    statusCode: 409,
                    message: 'Invitation sudah tidak dapat dikirim ulang.',
                );
            }

            $token = $this->bootstrap->rotate(
                (int) $invitation->tenant_id, (string) $invitation->invitation_public_ulid,
            )->token;

            $this->auditLogger->platform(
                request: $request,
                action: 'tenant.invitation.resent',
                actorUserId: (int) $actor->getAuthIdentifier(),
                targetId: (string) $invitation->invitation_public_ulid,
                metadata: ['channel' => 'email'],
                tenantId: (int) $invitation->tenant_id,
                targetType: 'account_invitation',
            );

            return new ProvisionTenantResult(
                tenantPublicUlid: (string) $invitation->tenant_public_ulid,
                tenantSlug: (string) $invitation->tenant_slug,
                tenantHostname: (string) $invitation->tenant_hostname,
                tenantStatus: (string) $invitation->tenant_status,
                invitationPublicUlid: (string) $invitation->invitation_public_ulid,
                invitationToken: $token,
                replayed: false,
            );
        });

        return $result;
    }

    private function send(
        Request $request,
        User $actor,
        string $tenantPublicUlid,
        string $invitationPublicUlid,
        string $token,
    ): string {
        if (! config('saas.invitation_delivery.enabled', true)) {
            $this->auditDelivery($request, $actor, $tenantPublicUlid, $invitationPublicUlid, 'skipped');

            return 'skipped';
        }

        $invitation = DB::table('account_invitations as invitations')
            ->join('tenants', 'tenants.id', '=', 'invitations.tenant_id')
            ->join('users', 'users.id', '=', 'invitations.target_user_id')
            ->join('tenant_domains as domains', function ($join): void {
                $join->on('domains.tenant_id', '=', 'tenants.id')
                    ->where('domains.is_primary', true)
                    ->whereIn('domains.status', ['active', 'pending']);
            })
            ->where('tenants.public_ulid', $tenantPublicUlid)
            ->where('invitations.public_ulid', $invitationPublicUlid)
            ->where('invitations.status', 'pending')
            ->first([
                'tenants.name as tenant_name',
                'users.name as display_name',
                'users.email',
                'domains.hostname as tenant_hostname',
                'invitations.expires_at',
            ]);

        if ($invitation === null || ! is_string($invitation->email) || ! is_string($invitation->tenant_hostname)) {
            $this->auditDelivery($request, $actor, $tenantPublicUlid, $invitationPublicUlid, 'failed', 'recipient_not_found');

            return 'failed';
        }

        $activationUrl = sprintf(
            '%s://%s/activate/%s/%s',
            trim((string) config('saas.invitation_delivery.url_scheme', 'https'), ':/'),
            $invitation->tenant_hostname,
            $invitationPublicUlid,
            $token,
        );

        try {
            $mailable = new BootstrapAdminInvitation(
                tenantName: (string) $invitation->tenant_name,
                displayName: (string) $invitation->display_name,
                activationUrl: $activationUrl,
                expiresAt: (string) $invitation->expires_at,
            );

            $mailer = config('saas.invitation_delivery.mailer');
            if (is_string($mailer) && $mailer !== '') {
                Mail::mailer($mailer)->to($invitation->email)->send($mailable);
            } else {
                Mail::to($invitation->email)->send($mailable);
            }
        } catch (Throwable $exception) {
            // Transport exceptions may contain recipient addresses or activation secrets; audit only a stable code.
            $this->auditDelivery(
                $request,
                $actor,
                $tenantPublicUlid,
                $invitationPublicUlid,
                'failed',
                'mail_transport_failed',
            );

            return 'failed';
        }

        $this->auditDelivery($request, $actor, $tenantPublicUlid, $invitationPublicUlid, 'sent');

        return 'sent';
    }

    private function auditDelivery(
        Request $request,
        User $actor,
        string $tenantPublicUlid,
        string $invitationPublicUlid,
        string $status,
        ?string $failureCode = null,
    ): void {
        $tenantId = DB::table('tenants')->where('public_ulid', $tenantPublicUlid)->value('id');
        $this->auditLogger->platform(
            request: $request,
            action: 'tenant.invitation.delivery_'.$status,
            actorUserId: (int) $actor->getAuthIdentifier(),
            targetId: $invitationPublicUlid,
            metadata: array_filter([
                'channel' => 'email',
                'failure_code' => $failureCode,
            ]),
            tenantId: $tenantId === null ? null : (int) $tenantId,
            targetType: 'account_invitation',
        );
    }
}

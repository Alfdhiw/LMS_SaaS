<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\BootstrapAdminAccounts;
use App\Modules\Identity\Data\BootstrapInvitation;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Support\Exceptions\OperationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ManageBootstrapAdminAccounts implements BootstrapAdminAccounts
{
    public function create(int $tenantId, int $actorId, string $name, string $email, string $username): BootstrapInvitation
    {
        $this->requireTransaction();
        $role = DB::table('roles')->where('key', 'school_admin')->value('id');
        if ($role === null) {
            throw new OperationException('role_catalog_missing', 503, 'Role catalog belum tersedia.');
        }
        $email = IdentityNormalizer::email($email);
        if (DB::table('users')->where('email_normalized', $email)->exists()) {
            throw new OperationException('admin_email_exists', 409, 'Email admin sudah terhubung ke akun lain.');
        }
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'name' => $name, 'email' => $email, 'email_normalized' => $email,
            'password' => null, 'status' => 'pending', 'auth_version' => 1, 'must_change_password' => false,
            'password_changed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $memberId = (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'local_username' => $username,
            'local_username_normalized' => IdentityNormalizer::username($username), 'display_name' => $name,
            'status' => 'invited', 'auth_version' => 1, 'joined_at' => null, 'left_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('membership_roles')->insert(['tenant_id' => $tenantId, 'membership_id' => $memberId, 'role_id' => $role]);
        $id = (string) Str::ulid();
        $token = bin2hex(random_bytes(32));
        DB::table('account_invitations')->insert([
            'public_ulid' => $id, 'tenant_id' => $tenantId, 'membership_id' => $memberId, 'target_user_id' => $userId,
            'purpose' => 'bootstrap_admin', 'token_hash' => hash('sha256', $token), 'status' => 'pending',
            'expires_at' => $now->copy()->addHours((int) config('saas.provisioning_invitation_ttl_hours', 72)),
            'created_by_platform_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return new BootstrapInvitation($id, $token);
    }

    public function rotate(int $tenantId, string $invitationUlid): BootstrapInvitation
    {
        $this->requireTransaction();
        $invitation = DB::table('account_invitations as invitations')
            ->join('users', 'users.id', '=', 'invitations.target_user_id')
            ->join('tenant_memberships as memberships', function ($join): void {
                $join->on('memberships.id', '=', 'invitations.membership_id')
                    ->on('memberships.tenant_id', '=', 'invitations.tenant_id');
            })->where('invitations.tenant_id', $tenantId)->where('invitations.public_ulid', $invitationUlid)
            ->lockForUpdate()->first(['invitations.id', 'invitations.purpose', 'invitations.status',
                'users.status as user_status', 'memberships.status as membership_status']);
        if ($invitation === null) {
            throw new OperationException('invitation_not_found', 404, 'Invitation tidak ditemukan.');
        }
        if ($invitation->purpose !== 'bootstrap_admin' || $invitation->status !== 'pending'
            || $invitation->user_status !== 'pending' || $invitation->membership_status !== 'invited') {
            throw new OperationException('invitation_not_resendable', 409, 'Invitation sudah tidak dapat dikirim ulang.');
        }
        $token = bin2hex(random_bytes(32));
        DB::table('account_invitations')->where('tenant_id', $tenantId)->where('id', $invitation->id)->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours((int) config('saas.provisioning_invitation_ttl_hours', 72)), 'updated_at' => now(),
        ]);

        return new BootstrapInvitation($invitationUlid, $token);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Bootstrap account writes require an owning workflow transaction.');
        }
    }
}

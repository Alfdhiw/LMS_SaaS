<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Tenancy\Data\TenantContext;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberInvitationService
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly AuthAuditLogger $audit) {}

    /** @return array{invitation_id:string,token:string} */
    public function invite(Request $request, TenantContext $context, User $actor, int $actorMembershipId, string $name, string $username, string $role): array
    {
        abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.invite'), 403);
        abort_unless(in_array($role, ['student', 'teacher'], true), 422);
        $normalized = IdentityNormalizer::username($username);

        return DB::transaction(function () use ($request, $context, $actor, $actorMembershipId, $name, $username, $normalized, $role): array {
            DB::table('tenants')->where('id', $context->tenantId)->lockForUpdate()->first();
            abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.invite'), 403);
            $used = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('local_username_normalized', $normalized)->exists();
            $pendingJoin = DB::table('account_invitations')->where('tenant_id', $context->tenantId)->where('purpose', 'join_existing')
                ->where('proposed_local_username_normalized', $normalized)->where('status', 'pending')->where('expires_at', '>', now())->exists();
            if ($used || $pendingJoin) {
                throw ValidationException::withMessages(['username' => 'Username sekolah sudah digunakan.']);
            }
            $roleId = DB::table('roles')->where('key', $role)->value('id');
            abort_if($roleId === null, 503);
            $userId = DB::table('users')->insertGetId([
                'public_ulid' => (string) Str::ulid(), 'name' => $name, 'status' => 'pending',
                'auth_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $membershipId = DB::table('tenant_memberships')->insertGetId([
                'tenant_id' => $context->tenantId, 'user_id' => $userId,
                'local_username' => $username, 'local_username_normalized' => $normalized,
                'display_name' => $name, 'status' => 'invited', 'auth_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('membership_roles')->insert(['tenant_id' => $context->tenantId, 'membership_id' => $membershipId, 'role_id' => $roleId]);
            $id = (string) Str::ulid();
            $token = bin2hex(random_bytes(32));
            DB::table('account_invitations')->insert([
                'public_ulid' => $id, 'tenant_id' => $context->tenantId, 'membership_id' => $membershipId,
                'target_user_id' => $userId, 'purpose' => 'activate_new', 'token_hash' => hash('sha256', $token),
                'status' => 'pending', 'expires_at' => now()->addHours(72),
                'created_by_membership_id' => $actorMembershipId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'membership.invitation.created', (int) $actor->id, $actorMembershipId, $id, ['role' => $role], 'account_invitation');

            return ['invitation_id' => $id, 'token' => $token];
        });
    }

    public function activate(Request $request, TenantContext $context, string $invitationId, string $token, string $password): void
    {
        DB::transaction(function () use ($request, $context, $invitationId, $token, $password): void {
            $invitation = DB::table('account_invitations')->where('public_ulid', $invitationId)
                ->where('tenant_id', $context->tenantId)->where('purpose', 'activate_new')
                ->where('status', 'pending')->where('expires_at', '>', now())
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_if($invitation === null, 404);
            $user = DB::table('users')->where('id', $invitation->target_user_id)->lockForUpdate()->first();
            $member = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                ->where('id', $invitation->membership_id)->where('user_id', $invitation->target_user_id)->lockForUpdate()->first();
            $creator = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                ->where('id', $invitation->created_by_membership_id)->where('status', 'active')->first();
            $creatorUser = $creator === null ? null : User::query()->find($creator->user_id);
            abort_unless($user !== null && $user->status === 'pending' && $member !== null && $member->status === 'invited'
                && $creatorUser !== null && $creatorUser->status === 'active'
                && $this->permissions->allows($creatorUser, $context, (int) $creator->id, 'members.invite'), 404);
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($password), 'status' => 'active', 'password_changed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('id', $member->id)->update([
                'status' => 'active', 'joined_at' => now(), 'auth_version' => DB::raw('auth_version + 1'), 'updated_at' => now(),
            ]);
            DB::table('account_invitations')->where('id', $invitation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
            $this->audit->tenant($request, $context, 'membership.invitation.consumed', (int) $user->id, (int) $member->id, $invitationId, [], 'account_invitation');
        });
    }
}

<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExistingAccountInvitationService
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly AuthAuditLogger $audit) {}

    /** @return array{invitation_id:string,token:string} */
    public function issue(Request $request, TenantContext $context, User $actor, int $actorMembershipId, string $username, string $role): array
    {
        abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.invite'), 403);
        abort_unless(in_array($role, ['teacher', 'student'], true), 422);
        $normalized = IdentityNormalizer::username($username);

        return DB::transaction(function () use ($request, $context, $actor, $actorMembershipId, $username, $role, $normalized): array {
            app(TenantRegistry::class)->lockContext($context);
            abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.invite'), 403);
            $used = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('local_username_normalized', $normalized)->exists();
            $pending = DB::table('account_invitations')->where('tenant_id', $context->tenantId)->where('purpose', 'join_existing')
                ->where('proposed_local_username_normalized', $normalized)->where('status', 'pending')->where('expires_at', '>', now())->exists();
            if ($used || $pending) {
                throw ValidationException::withMessages(['username' => 'Username sudah digunakan atau memiliki undangan aktif.']);
            }
            $roleId = DB::table('roles')->where('key', $role)->value('id');
            abort_if($roleId === null, 503);
            $id = (string) Str::ulid();
            $token = bin2hex(random_bytes(32));
            DB::table('account_invitations')->insert([
                'public_ulid' => $id, 'tenant_id' => $context->tenantId,
                'proposed_local_username' => $username, 'proposed_local_username_normalized' => $normalized,
                'proposed_role_id' => $roleId, 'purpose' => 'join_existing', 'token_hash' => hash('sha256', $token),
                'status' => 'pending', 'expires_at' => now()->addHours(72),
                'created_by_membership_id' => $actorMembershipId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'membership.join_invitation.issued', (int) $actor->id, $actorMembershipId, $id, ['role' => $role], 'account_invitation');

            return ['invitation_id' => $id, 'token' => $token];
        });
    }

    public function accept(Request $request, TenantContext $context, string $id, string $token, string $email, string $password): void
    {
        $candidate = DB::table('users')->where('email_normalized', IdentityNormalizer::email($email))->first();
        // Use a real password hash even for unknown accounts to reduce enumeration timing differences.
        $passwordValid = Hash::check($password, $candidate?->password ?: '$2y$12$XQM3qOyBQOBKE2K6zOv3uO7kuRbfj9U3OnYwQPYYAcwW0px4QbiiO');
        abort_unless($candidate !== null && $passwordValid && $candidate->status === 'active' && $candidate->email_verified_at !== null, 404);

        DB::transaction(function () use ($request, $context, $id, $token, $candidate, $password): void {
            app(TenantRegistry::class)->lockContext($context);
            // Share the user lock with school-assisted recovery before adding membership.
            $user = DB::table('users')->where('id', $candidate->id)->lockForUpdate()->first();
            abort_unless($user !== null && $user->status === 'active' && $user->email_verified_at !== null && Hash::check($password, $user->password), 404);
            $invitation = DB::table('account_invitations')->where('tenant_id', $context->tenantId)
                ->where('public_ulid', $id)->where('purpose', 'join_existing')->where('status', 'pending')
                ->where('expires_at', '>', now())->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_if($invitation === null || $invitation->target_user_id !== null || $invitation->membership_id !== null, 404);
            $creator = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                ->where('id', $invitation->created_by_membership_id)->where('status', 'active')->first();
            $creatorUser = $creator === null ? null : User::query()->find($creator->user_id);
            abort_unless($creatorUser !== null && $creatorUser->status === 'active'
                && $this->permissions->allows($creatorUser, $context, (int) $creator->id, 'members.invite'), 404);
            $exists = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('local_username_normalized', $invitation->proposed_local_username_normalized))->exists();
            abort_if($exists, 409);
            $role = DB::table('roles')->where('id', $invitation->proposed_role_id)->whereIn('key', ['student', 'teacher'])->first();
            abort_if($role === null, 404);
            $membershipId = DB::table('tenant_memberships')->insertGetId([
                'tenant_id' => $context->tenantId, 'user_id' => $user->id,
                'local_username' => $invitation->proposed_local_username,
                'local_username_normalized' => $invitation->proposed_local_username_normalized,
                'display_name' => $user->name, 'status' => 'active', 'auth_version' => 1,
                'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('membership_roles')->insert(['tenant_id' => $context->tenantId, 'membership_id' => $membershipId, 'role_id' => $role->id]);
            DB::table('account_invitations')->where('id', $invitation->id)->update([
                'target_user_id' => $user->id, 'membership_id' => $membershipId,
                'status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'membership.join_invitation.consumed', (int) $user->id, (int) $membershipId, $id, ['role' => $role->key], 'account_invitation');
        });
    }
}

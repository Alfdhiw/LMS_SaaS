<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Tenancy\Data\TenantContext;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MembershipManagementService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function setStatus(Request $request, TenantContext $context, User $actor, int $actorMembershipId, string $userUlid, string $status): void
    {
        abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.suspend'), 403);
        abort_unless(in_array($status, ['active', 'suspended'], true), 422);

        DB::transaction(function () use ($request, $context, $actor, $actorMembershipId, $userUlid, $status): void {
            // Serializes last-admin checks across concurrent membership changes.
            DB::table('tenants')->where('id', $context->tenantId)->lockForUpdate()->first();
            abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.suspend'), 403);
            $member = $this->member($context, $userUlid);
            abort_unless(in_array($member->status, ['active', 'suspended'], true), 409);

            if ($member->status === $status) {
                return;
            }

            if ($status === 'suspended') {
                $this->assertNotLastAdmin($context, (int) $member->id);
            }

            DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('id', $member->id)->update([
                'status' => $status,
                'auth_version' => DB::raw('auth_version + 1'),
                'updated_at' => now(),
            ]);

            $this->audit->tenant($request, $context, 'membership.status_changed', (int) $actor->id, $actorMembershipId, $userUlid, [
                'from' => $member->status,
                'to' => $status,
            ], 'membership');
        });
    }

    /** @param list<string> $roleKeys */
    public function setRoles(Request $request, TenantContext $context, User $actor, int $actorMembershipId, string $userUlid, array $roleKeys): void
    {
        abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.assign_roles'), 403);
        $roleKeys = array_values(array_unique($roleKeys));
        sort($roleKeys);
        $allowed = ['school_admin', 'teacher', 'homeroom_teacher', 'student'];

        if ($roleKeys === [] || array_diff($roleKeys, $allowed) !== []) {
            throw ValidationException::withMessages(['roles' => 'Role sekolah tidak valid.']);
        }

        DB::transaction(function () use ($request, $context, $actor, $actorMembershipId, $userUlid, $roleKeys): void {
            DB::table('tenants')->where('id', $context->tenantId)->lockForUpdate()->first();
            abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.assign_roles'), 403);
            $member = $this->member($context, $userUlid);
            $old = DB::table('membership_roles as mr')->join('roles', 'roles.id', '=', 'mr.role_id')
                ->where('mr.tenant_id', $context->tenantId)->where('mr.membership_id', $member->id)
                ->orderBy('roles.key')->pluck('roles.key')->all();

            if ($old === $roleKeys) {
                return;
            }

            if (in_array('school_admin', $old, true) && ! in_array('school_admin', $roleKeys, true) && $member->status === 'active') {
                $this->assertNotLastAdmin($context, (int) $member->id);
            }

            $roles = DB::table('roles')->whereIn('key', $roleKeys)->pluck('id');
            abort_unless($roles->count() === count($roleKeys), 503);
            DB::table('membership_roles')->where('tenant_id', $context->tenantId)->where('membership_id', $member->id)->delete();
            foreach ($roles as $roleId) {
                DB::table('membership_roles')->insert(['tenant_id' => $context->tenantId, 'membership_id' => $member->id, 'role_id' => $roleId]);
            }
            DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('id', $member->id)
                ->update(['auth_version' => DB::raw('auth_version + 1'), 'updated_at' => now()]);

            $this->audit->tenant($request, $context, 'membership.roles_changed', (int) $actor->id, $actorMembershipId, $userUlid, [
                'from' => $old,
                'to' => $roleKeys,
            ], 'membership');
        });
    }

    private function member(TenantContext $context, string $userUlid): object
    {
        $member = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $context->tenantId)->where('u.public_ulid', $userUlid)
            ->lockForUpdate()->first(['m.id', 'm.status']);
        abort_if($member === null, 404);

        return $member;
    }

    private function assertNotLastAdmin(TenantContext $context, int $membershipId): void
    {
        $admins = DB::table('tenant_memberships as m')
            ->join('membership_roles as mr', function ($join): void {
                $join->on('mr.tenant_id', '=', 'm.tenant_id')->on('mr.membership_id', '=', 'm.id');
            })->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $context->tenantId)->where('m.status', 'active')
            ->where('u.status', 'active')->where('r.key', 'school_admin');

        if ((clone $admins)->where('m.id', $membershipId)->exists() && (clone $admins)->count() <= 1) {
            throw ValidationException::withMessages(['membership' => 'Admin sekolah aktif terakhir tidak dapat dinonaktifkan atau dicabut.']);
        }
    }
}

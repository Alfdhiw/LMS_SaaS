<?php

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Contracts\MembershipEligibilityReader;
use App\Modules\Identity\Data\MembershipEligibility;
use App\Modules\Identity\Data\MembershipSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class MembershipEligibilityQuery implements MembershipEligibilityReader
{
    public function read(TenantContext $context, MembershipSelection $selection): array
    {
        if ($selection->membershipIds === []) {
            return [];
        }

        $members = DB::table('tenant_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $context->tenantId)
            ->whereIn('m.id', $selection->membershipIds)
            ->get(['m.id', 'm.tenant_id', 'm.user_id', 'm.status as member_status',
                'm.auth_version as member_version', 'u.status as user_status', 'u.auth_version as user_version']);
        $ids = $members->pluck('id')->all();
        $roles = DB::table('membership_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.tenant_id', $context->tenantId)->whereIn('mr.membership_id', $ids)
            ->whereIn('r.key', ['school_admin', 'teacher', 'homeroom_teacher', 'student'])
            ->orderBy('r.key')->get(['mr.membership_id', 'r.key'])->groupBy('membership_id');

        $result = [];
        foreach ($members as $member) {
            $id = (int) $member->id;
            $result[$id] = new MembershipEligibility(
                (int) $member->tenant_id, $id, (int) $member->user_id,
                $member->user_status, $member->member_status, (int) $member->user_version,
                (int) $member->member_version, $roles->get($id, collect())->pluck('key')->all(),
            );
        }

        return $result;
    }
}

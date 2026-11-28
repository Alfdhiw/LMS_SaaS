<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\AcademicIdentity;
use App\Modules\Identity\Data\MembershipSelection;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AcademicIdentityService implements AcademicIdentity
{
    public function __construct(private readonly SchoolProfileWorkflow $school) {}

    public function authorize(Request $request, TenantContext $context, string $permission, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('Locked authorization requires a transaction.');
        }
        [$user, $membership] = $this->school->authorize($request, $context, $permission, $lock);

        return [(int) $user->id, $membership];
    }

    public function findMember(TenantContext $context, string $username, bool $lock = false): ?array
    {
        $row = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
            ->where('local_username_normalized', IdentityNormalizer::username($username))
            ->first(['id', 'user_id', 'local_username', 'display_name']);
        if ($row !== null && $lock) {
            if (DB::transactionLevel() < 1) {
                throw new LogicException('Locked member lookup requires a transaction.');
            }
            DB::table('users')->where('id', $row->user_id)->lockForUpdate()->first();
            $row = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                ->where('id', $row->id)->lockForUpdate()->first(['id', 'local_username', 'display_name']);
        }

        return $row === null ? null : ['id' => (int) $row->id, 'username' => $row->local_username, 'name' => $row->display_name];
    }

    public function describeMembers(TenantContext $context, array $ids): array
    {
        $selection = new MembershipSelection($ids);
        $result = [];
        foreach (DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
            ->whereIn('id', $selection->membershipIds)->get(['id', 'local_username', 'display_name']) as $row) {
            $result[(int) $row->id] = ['username' => $row->local_username, 'name' => $row->display_name];
        }

        return $result;
    }
}

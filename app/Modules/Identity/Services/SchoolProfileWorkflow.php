<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Tenancy\Contracts\SchoolProfiles;
use App\Modules\Tenancy\Data\SchoolProfile;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSession;
use App\Support\Http\Hostname;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Internal entry point; routes must also use the existing tenant middleware. */
final class SchoolProfileWorkflow
{
    public function __construct(
        private readonly SchoolProfiles $profiles,
        private readonly PermissionChecker $permissions,
        private readonly TenantContextStore $contexts,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function read(Request $request): SchoolProfile
    {
        $context = $this->contexts->require();
        $profile = $this->profiles->read($context);
        $this->authorize($request, $context, 'school.view', false);

        return $profile;
    }

    public function update(Request $request, SchoolProfileUpdate $changes): SchoolProfile
    {
        $context = $this->contexts->require();

        return DB::transaction(function () use ($request, $context, $changes): SchoolProfile {
            $before = $this->profiles->lock($context);
            [$actor, $membershipId] = $this->authorize($request, $context, 'school.update', true);
            if ($before->name === $changes->name && $before->timezone === $changes->timezone
                && $before->accentColor === $changes->accentColor) {
                return $before;
            }
            $after = $this->profiles->update($context, $changes);
            $this->audit->tenant($request, $context, 'school.profile_updated', (int) $actor->id,
                $membershipId, $context->tenantPublicUlid, [], 'school_profile');

            return $after;
        });
    }

    /** @return array{User, int} */
    private function authorize(Request $request, TenantContext $context, string $permission, bool $lock): array
    {
        $session = $request->session();
        $user = $request->user();
        abort_unless($user instanceof User && $session->get(AuthSession::AREA) === 'tenant'
            && Hostname::fromRequest($request) === $context->hostname
            && $session->get(AuthSession::HOST) === $context->hostname
            && (int) $session->get(AuthSession::TENANT_ID) === $context->tenantId
            && (int) $session->get(AuthSession::USER_ID) === (int) $user->id, 403);
        $userQuery = User::query()->whereKey($user->id);
        $membershipId = (int) $session->get(AuthSession::MEMBERSHIP_ID);
        $memberQuery = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
            ->where('id', $membershipId)->where('user_id', $user->id);
        if ($lock) {
            $userQuery->lockForUpdate();
            $memberQuery->lockForUpdate();
        }
        $fresh = $userQuery->first();
        $member = $memberQuery->first();
        abort_unless($fresh !== null && $fresh->status === 'active'
            && (int) $session->get(AuthSession::USER_AUTH_VERSION) === (int) $fresh->auth_version
            && $member !== null && $member->status === 'active'
            && (int) $session->get(AuthSession::MEMBERSHIP_AUTH_VERSION) === (int) $member->auth_version
            && $this->permissions->allows($fresh, $context, $membershipId, $permission), 403);

        return [$fresh, $membershipId];
    }
}

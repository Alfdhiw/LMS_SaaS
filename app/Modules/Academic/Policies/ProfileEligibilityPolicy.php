<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Identity\Data\MembershipEligibility;
use InvalidArgumentException;

/** Domain eligibility only; callers must separately authorize the actor and resource. */
final class ProfileEligibilityPolicy
{
    public function mayActivate(MembershipEligibility $membership, int $tenantId, string $profileType): bool
    {
        if (! in_array($profileType, ['student', 'teacher'], true)) {
            throw new InvalidArgumentException('Unknown academic profile type.');
        }

        return $membership->tenantId === $tenantId
            && $membership->isActive()
            && $membership->hasRole($profileType);
    }
}

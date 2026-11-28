<?php

namespace App\Modules\Identity\Data;

use InvalidArgumentException;

final readonly class MembershipEligibility
{
    /** @var list<string> */
    public array $roleKeys;

    /** @param list<string> $roleKeys */
    public function __construct(
        public int $tenantId,
        public int $membershipId,
        public int $userId,
        public string $userStatus,
        public string $membershipStatus,
        public int $userAuthVersion,
        public int $membershipAuthVersion,
        array $roleKeys,
    ) {
        if (min($tenantId, $membershipId, $userId, $userAuthVersion, $membershipAuthVersion) < 1
            || ! in_array($userStatus, ['pending', 'active', 'suspended'], true)
            || ! in_array($membershipStatus, ['invited', 'active', 'suspended', 'left'], true)
            || ! array_is_list($roleKeys)) {
            throw new InvalidArgumentException('Invalid membership eligibility facts.');
        }
        foreach ($roleKeys as $role) {
            if (! is_string($role) || ! in_array($role, ['school_admin', 'teacher', 'homeroom_teacher', 'student'], true)) {
                throw new InvalidArgumentException('Only tenant role keys are allowed.');
            }
        }
        $roles = array_values(array_unique($roleKeys));
        sort($roles, SORT_STRING);
        $this->roleKeys = $roles;
    }

    public function isActive(): bool
    {
        return $this->userStatus === 'active' && $this->membershipStatus === 'active';
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleKeys, true);
    }

    public function matchesVersions(int $userAuthVersion, int $membershipAuthVersion): bool
    {
        return $this->userAuthVersion === $userAuthVersion
            && $this->membershipAuthVersion === $membershipAuthVersion;
    }
}

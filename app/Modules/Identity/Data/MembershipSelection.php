<?php

namespace App\Modules\Identity\Data;

use InvalidArgumentException;

final readonly class MembershipSelection
{
    public const MAX_MEMBERSHIPS = 100;

    /** @var list<int> */
    public array $membershipIds;

    /** @param list<int> $membershipIds Previously authorized, server-resolved IDs. */
    public function __construct(array $membershipIds)
    {
        if (! array_is_list($membershipIds) || count($membershipIds) > self::MAX_MEMBERSHIPS) {
            throw new InvalidArgumentException('Membership selection must be a bounded list.');
        }
        foreach ($membershipIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Membership IDs must be positive integers.');
            }
        }
        $ids = array_values(array_unique($membershipIds));
        sort($ids, SORT_NUMERIC);
        $this->membershipIds = $ids;
    }
}

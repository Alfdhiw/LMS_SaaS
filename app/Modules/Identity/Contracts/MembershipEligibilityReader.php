<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Data\MembershipEligibility;
use App\Modules\Identity\Data\MembershipSelection;
use App\Support\Tenancy\TenantContext;

interface MembershipEligibilityReader
{
    /**
     * Read fresh facts for previously authorized IDs, never a global directory.
     *
     * The owning workflow authorizes the actor, host, both auth versions and
     * resource relationship before calling. Implementations scope by context
     * before lookup, omit missing/foreign IDs and never cache authorization.
     * Facts do not grant permission or replace Academic profile/assignment policy.
     *
     * @return array<int, MembershipEligibility> Keyed by membership ID.
     */
    public function read(TenantContext $context, MembershipSelection $selection): array;
}

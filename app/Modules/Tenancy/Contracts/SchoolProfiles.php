<?php

namespace App\Modules\Tenancy\Contracts;

use App\Modules\Tenancy\Data\SchoolProfile;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Tenancy\TenantContext;

interface SchoolProfiles
{
    /** Caller requires school.view, a fresh active session and verified context. */
    public function read(TenantContext $context): SchoolProfile;

    /** Lock fresh active tenant/domain/settings inside the owning transaction. */
    public function lock(TenantContext $context): SchoolProfile;

    /**
     * Caller locks tenant first, rechecks school.update and both auth versions,
     * then updates and audits atomically in the same transaction. Only the three
     * DTO fields may change; identity/status/hosts/onboarding remain immutable.
     * Tenancy does not import Identity or implement membership authorization.
     */
    public function update(TenantContext $context, SchoolProfileUpdate $changes): SchoolProfile;
}

<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Contracts\SchoolProfiles;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Modules\Tenancy\Data\SchoolProfile;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ManageSchoolProfiles implements SchoolProfiles
{
    public function __construct(private readonly TenantRegistry $registry) {}

    public function read(TenantContext $context): SchoolProfile
    {
        $row = DB::table('tenants as t')->join('tenant_domains as d', 'd.tenant_id', '=', 't.id')
            ->join('tenant_settings as s', 's.tenant_id', '=', 't.id')
            ->where('t.id', $context->tenantId)->where('t.public_ulid', $context->tenantPublicUlid)
            ->where('t.status', 'active')->where('d.id', $context->domainId)->where('d.hostname', $context->hostname)
            ->where('d.status', 'active')->whereNotNull('d.verified_at')
            ->first(['t.id', 't.public_ulid', 't.name', 't.timezone', 's.accent_color']);
        abort_if($row === null, 404);

        return $this->profile($row);
    }

    public function lock(TenantContext $context): SchoolProfile
    {
        $this->requireTransaction();
        $tenant = $this->registry->lockContext($context);
        $settings = DB::table('tenant_settings')->where('tenant_id', $context->tenantId)->lockForUpdate()->first();
        abort_if($settings === null, 404);
        $tenant->accent_color = $settings->accent_color;

        return $this->profile($tenant);
    }

    public function update(TenantContext $context, SchoolProfileUpdate $changes): SchoolProfile
    {
        $this->lock($context);
        DB::table('tenants')->where('id', $context->tenantId)->update([
            'name' => $changes->name, 'timezone' => $changes->timezone, 'updated_at' => now(),
        ]);
        DB::table('tenant_settings')->where('tenant_id', $context->tenantId)->update([
            'accent_color' => $changes->accentColor, 'updated_at' => now(),
        ]);

        return $this->read($context);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('School profile updates require an authorized, audited transaction.');
        }
    }

    private function profile(object $row): SchoolProfile
    {
        return new SchoolProfile((int) $row->id, $row->public_ulid, $row->name, $row->timezone, $row->accent_color);
    }
}

<?php

namespace App\Modules\Tenancy\Actions;

use Illuminate\Support\Facades\DB;

final class UpdateTenantMetadata
{
    /**
     * Must be called inside the platform orchestration transaction.
     *
     * @return array{id:int,public_ulid:string,old_name:string,old_timezone:string,name:string,timezone:string}|null
     */
    public function execute(string $publicUlid, string $name, string $timezone): ?array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Tenant metadata update requires an enclosing transaction.');
        }
        $tenant = DB::table('tenants')->where('public_ulid', $publicUlid)->lockForUpdate()->first();
        if ($tenant === null) {
            return null;
        }
        abort_if($tenant->status === 'archived', 409, 'Metadata tenant archived tidak dapat diubah.');
        if ($tenant->name !== $name || $tenant->timezone !== $timezone) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'name' => $name, 'timezone' => $timezone, 'updated_at' => now(),
            ]);
        }

        return [
            'id' => (int) $tenant->id, 'public_ulid' => (string) $tenant->public_ulid,
            'old_name' => (string) $tenant->name, 'old_timezone' => (string) $tenant->timezone,
            'name' => $name, 'timezone' => $timezone,
        ];
    }
}

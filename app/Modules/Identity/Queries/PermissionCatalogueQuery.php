<?php

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Contracts\PermissionCatalogue;
use Illuminate\Support\Facades\DB;

final class PermissionCatalogueQuery implements PermissionCatalogue
{
    public function containsPlatformPermission(string $key): bool
    {
        return DB::table('permissions')->where('key', $key)->where('scope', 'platform')->exists();
    }
}

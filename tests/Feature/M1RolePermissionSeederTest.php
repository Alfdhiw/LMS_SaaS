<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class M1RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permission_seed_is_idempotent_and_platform_permissions_are_not_granted(): void
    {
        Artisan::call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--no-interaction' => true,
        ]);

        $firstCounts = [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'role_permissions' => DB::table('role_permissions')->count(),
        ];

        Artisan::call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--no-interaction' => true,
        ]);

        $this->assertSame($firstCounts['roles'], DB::table('roles')->count());
        $this->assertSame($firstCounts['permissions'], DB::table('permissions')->count());
        $this->assertSame($firstCounts['role_permissions'], DB::table('role_permissions')->count());
        $this->assertSame(4, $firstCounts['roles']);
        $this->assertSame(7, DB::table('permissions')->where('scope', 'platform')->count());
        $this->assertSame(0, DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.scope', 'platform')
            ->count());
    }
}

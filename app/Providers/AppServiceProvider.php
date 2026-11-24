<?php

namespace App\Providers;

use App\Modules\Identity\Actions\ManageBootstrapAdminAccounts;
use App\Modules\Identity\Actions\ManageTenantMemberships;
use App\Modules\Identity\Contracts\BootstrapAdminAccounts;
use App\Modules\Identity\Contracts\PermissionCatalogue;
use App\Modules\Identity\Contracts\TenantMemberships;
use App\Modules\Identity\Queries\PermissionCatalogueQuery;
use App\Modules\Platform\Services\PlatformStaffAccess;
use App\Modules\Tenancy\Actions\ManageTenantRegistry;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Modules\Tenancy\Services\TenantResolver;
use App\Support\Auth\CentralStaffAccess;
use App\Support\Tenancy\TenantContextResolver;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TenantRegistry::class, ManageTenantRegistry::class);
        $this->app->bind(BootstrapAdminAccounts::class, ManageBootstrapAdminAccounts::class);
        $this->app->bind(TenantMemberships::class, ManageTenantMemberships::class);
        $this->app->bind(CentralStaffAccess::class, PlatformStaffAccess::class);
        $this->app->bind(PermissionCatalogue::class, PermissionCatalogueQuery::class);
        $this->app->singleton(TenantResolver::class);
        $this->app->bind(TenantContextResolver::class, TenantResolver::class);
        $this->app->scoped(TenantContextStore::class, static fn (): TenantContextStore => new TenantContextStore);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

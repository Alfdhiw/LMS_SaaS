<?php

namespace App\Providers;

use App\Modules\Tenancy\Services\TenantResolver;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantResolver::class);
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

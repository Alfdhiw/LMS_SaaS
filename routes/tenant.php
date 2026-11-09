<?php

use App\Http\Middleware\RequireTenantHost;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireTenantHost::class)->group(function (): void {
    // PR3 will add authenticated tenant actions; this placeholder is not an
    // open school page.
    Route::get('/tenant', static fn () => abort(403, 'Tenant authentication is not implemented yet.'))
        ->name('tenant.placeholder');
});

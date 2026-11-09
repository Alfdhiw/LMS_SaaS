<?php

use App\Http\Middleware\RequireCentralHost;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('saas.central_host'))
    ->middleware(RequireCentralHost::class)
    ->group(function (): void {
        // PR3 will add authenticated central actions; this placeholder is not
        // an open administrator page.
        Route::get('/central', static fn () => abort(403, 'Central authentication is not implemented yet.'))
            ->name('central.placeholder');
    });

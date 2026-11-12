<?php

use App\Http\Controllers\Auth\TenantAuthController;
use App\Http\Middleware\EnsureTenantSession;
use App\Http\Middleware\RequireTenantHost;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireTenantHost::class)->group(function (): void {
    Route::get('/login', [TenantAuthController::class, 'showLogin'])->name('tenant.login');
    Route::post('/login', [TenantAuthController::class, 'login']);

    Route::middleware(EnsureTenantSession::class)->group(function (): void {
        Route::get('/dashboard', [TenantAuthController::class, 'dashboard'])
            ->middleware('tenant.permission:school.view')
            ->name('tenant.dashboard');
        Route::post('/logout', [TenantAuthController::class, 'logout'])->name('tenant.logout');
    });
});

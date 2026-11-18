<?php

use App\Http\Controllers\Auth\CentralAuthController;
use App\Http\Controllers\Auth\CentralMfaController;
use App\Http\Controllers\Platform\TenantLifecycleController;
use App\Http\Controllers\Platform\TenantProvisioningController;
use App\Http\Middleware\EnsureCentralSession;
use App\Http\Middleware\RequireCentralHost;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('saas.central_host'))
    ->middleware(RequireCentralHost::class)
    ->group(function (): void {
        Route::get('/login', [CentralAuthController::class, 'showLogin'])->name('central.login');
        Route::post('/login', [CentralAuthController::class, 'login']);

        Route::middleware('central.mfa.pending')->group(function (): void {
            Route::get('/mfa/setup', [CentralMfaController::class, 'showSetup'])->name('central.mfa.setup');
            Route::post('/mfa/setup', [CentralMfaController::class, 'setup']);
            Route::get('/mfa/challenge', [CentralMfaController::class, 'showChallenge'])->name('central.mfa.challenge');
            Route::post('/mfa/challenge', [CentralMfaController::class, 'challenge']);
        });

        Route::middleware(EnsureCentralSession::class)->group(function (): void {
            Route::get('/tenants', [TenantProvisioningController::class, 'index'])
                ->middleware('central.permission:platform.tenants.view')
                ->name('central.tenants.index');
            Route::post('/tenants', [TenantProvisioningController::class, 'store'])
                ->middleware('central.permission:platform.tenants.create')
                ->name('central.tenants.store');
            Route::get('/tenants/{publicUlid}', [TenantProvisioningController::class, 'show'])
                ->middleware('central.permission:platform.tenants.view')
                ->name('central.tenants.show');
            Route::post('/tenants/{publicUlid}/invitation/resend', [TenantProvisioningController::class, 'resend'])
                ->middleware('central.permission:platform.tenants.update')
                ->name('central.tenants.invitation.resend');
            Route::post('/tenants/{publicUlid}/activate', [TenantLifecycleController::class, 'activate'])
                ->middleware('central.permission:platform.tenants.transition')
                ->name('central.tenants.activate');
            Route::post('/tenants/{publicUlid}/suspend', [TenantLifecycleController::class, 'suspend'])
                ->middleware('central.permission:platform.tenants.transition')
                ->name('central.tenants.suspend');
            Route::post('/tenants/{publicUlid}/restore', [TenantLifecycleController::class, 'restore'])
                ->middleware('central.permission:platform.tenants.transition')
                ->name('central.tenants.restore');
            Route::post('/tenants/{publicUlid}/archive', [TenantLifecycleController::class, 'archive'])
                ->middleware('central.permission:platform.tenants.transition')
                ->name('central.tenants.archive');
            Route::get('/dashboard', [CentralAuthController::class, 'dashboard'])
                ->middleware('central.permission:platform.tenants.view')
                ->name('central.dashboard');
            Route::post('/security/mfa/{publicUlid}/reset', [CentralMfaController::class, 'reset'])
                ->middleware('central.permission:platform.staff.mfa_reset')
                ->name('central.mfa.reset');
            Route::post('/logout', [CentralAuthController::class, 'logout'])->name('central.logout');
        });
    });

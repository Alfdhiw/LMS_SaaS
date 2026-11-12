<?php

use App\Http\Controllers\Auth\CentralAuthController;
use App\Http\Controllers\Auth\CentralMfaController;
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
            Route::get('/dashboard', [CentralAuthController::class, 'dashboard'])
                ->middleware('central.permission:platform.tenants.view')
                ->name('central.dashboard');
            Route::post('/security/mfa/{publicUlid}/reset', [CentralMfaController::class, 'reset'])
                ->middleware('central.permission:platform.staff.mfa_reset')
                ->name('central.mfa.reset');
            Route::post('/logout', [CentralAuthController::class, 'logout'])->name('central.logout');
        });
    });

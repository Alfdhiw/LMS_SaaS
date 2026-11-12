<?php

use App\Http\Middleware\EnsureCentralMfaPending;
use App\Http\Middleware\EnsureCentralPermission;
use App\Http\Middleware\EnsureTenantPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            Route::middleware('web')->group(base_path('routes/central.php'));
            Route::middleware('web')->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'central.mfa.pending' => EnsureCentralMfaPending::class,
            'central.permission' => EnsureCentralPermission::class,
            'tenant.permission' => EnsureTenantPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('tenant_user') ?? $request->attributes->get('central_user');
        if ($user?->must_change_password && ! $request->routeIs('tenant.password.change*', 'central.password.change*', 'tenant.logout', 'central.logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Ganti password sebelum melanjutkan.', 'failure_code' => 'password_change_required'], 423)
                : redirect()->to('/password/change');
        }

        return $next($request);
    }
}

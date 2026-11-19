<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSecureIdentityTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);

        return $next($request);
    }
}

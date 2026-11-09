<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Support\Hostname;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCentralHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = Hostname::fromRequest($request);
        $centralHost = Hostname::normalize(config('saas.central_host'));

        if ($host === null || Hostname::isLoopback($host) || $host !== $centralHost) {
            abort(404);
        }

        return $next($request);
    }
}

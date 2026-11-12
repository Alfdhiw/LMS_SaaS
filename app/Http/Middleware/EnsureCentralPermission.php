<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Services\PlatformPermissionChecker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralPermission
{
    public function __construct(private readonly PlatformPermissionChecker $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('central_user');

        if (! $user instanceof User || ! $this->permissions->allows($user, $permission)) {
            abort(403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Services\PermissionChecker;
use App\Support\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantPermission
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContextStore $contextStore,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        $context = $this->contextStore->require();

        if (
            ! $user instanceof User
            || $membership === null
            || ! $this->permissionChecker->allows($user, $context, (int) $membership->id, $permission)
        ) {
            abort(403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Services\TenantResolver;
use App\Support\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTenantHost
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContextStore $contextStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->resolver->resolveFromRequest($request);

        if ($context === null) {
            abort(404);
        }

        $this->contextStore->set($context);
        $request->attributes->set('tenant_context', $context);

        try {
            return $next($request);
        } finally {
            $request->attributes->remove('tenant_context');
            $this->contextStore->clear();
        }
    }
}

<?php

namespace App\Support\Tenancy\Jobs;

use App\Support\Tenancy\TenantContextResolver;
use App\Support\Tenancy\TenantContextStore;
use Closure;
use LogicException;

final class UseTenantContext
{
    /** The dispatching server supplies these locators; execution always re-verifies them. */
    public function __construct(private readonly int $tenantId, private readonly string $hostname) {}

    public function handle(object $job, Closure $next): mixed
    {
        $store = app(TenantContextStore::class);
        $store->clear();
        try {
            $context = app(TenantContextResolver::class)->resolve($this->hostname);
            if ($context === null || $context->tenantId !== $this->tenantId) {
                throw new LogicException('Queued tenant context could not be verified.');
            }
            $store->set($context);

            return $next($job);
        } finally {
            $store->clear();
        }
    }
}

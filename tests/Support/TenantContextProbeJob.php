<?php

namespace Tests\Support;

use App\Support\Tenancy\Jobs\UseTenantContext;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

final class TenantContextProbeJob implements ShouldQueue
{
    public static array $observed = [];

    public function __construct(private readonly int $tenantId, private readonly string $hostname, private readonly bool $fail = false) {}

    public function middleware(): array
    {
        return [new UseTenantContext($this->tenantId, $this->hostname)];
    }

    public function handle(TenantContextStore $contexts): void
    {
        self::$observed[] = $contexts->require()->tenantId;
        if ($this->fail) {
            throw new RuntimeException('Synthetic job failure');
        }
    }
}

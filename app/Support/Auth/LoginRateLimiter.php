<?php

namespace App\Support\Auth;

use App\Modules\Tenancy\Data\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class LoginRateLimiter
{
    private const IDENTIFIER_MAX_ATTEMPTS = 5;

    private const TENANT_IP_MAX_ATTEMPTS = 20;

    private const IP_MAX_ATTEMPTS = 60;

    private const DECAY_SECONDS = 60;

    public function isLimited(Request $request, ?TenantContext $context, string $identifier): bool
    {
        foreach ($this->keys($request, $context, $identifier) as $index => $key) {
            $maxAttempts = match ($index) {
                0 => self::IP_MAX_ATTEMPTS,
                1 => self::TENANT_IP_MAX_ATTEMPTS,
                default => self::IDENTIFIER_MAX_ATTEMPTS,
            };

            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return true;
            }
        }

        return false;
    }

    public function recordFailure(Request $request, ?TenantContext $context, string $identifier): void
    {
        foreach ($this->keys($request, $context, $identifier) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    public function clear(Request $request, ?TenantContext $context, string $identifier): void
    {
        foreach ($this->keys($request, $context, $identifier) as $key) {
            RateLimiter::clear($key);
        }
    }

    /**
     * @return list<string>
     */
    private function keys(Request $request, ?TenantContext $context, string $identifier): array
    {
        $scope = $context === null ? 'central' : 'tenant:'.$context->tenantId;
        $identifierHash = hash_hmac(
            'sha256',
            $scope.'|'.$identifier,
            (string) (config('app.key') ?: 'm1-local-rate-limit-key'),
        );
        $ip = $request->ip() ?: 'unknown';

        return [
            'auth:login:ip:'.$ip,
            'auth:login:scope:'.$scope.':ip:'.$ip,
            'auth:login:identifier:'.$identifierHash,
        ];
    }
}

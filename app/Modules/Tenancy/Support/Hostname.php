<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Http\Request;

final class Hostname
{
    public static function fromRequest(Request $request): ?string
    {
        // Use only the direct Host header. Forwarded host headers require an
        // explicit trusted-proxy policy and must not influence tenant lookup.
        $host = $request->headers->get('host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = trim($host);

        if (substr_count($host, ':') > 1) {
            return null;
        }

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);

            if ($port === '' || ! ctype_digit($port) || (int) $port > 65535) {
                return null;
            }
        }

        return self::normalize($host);
    }

    public static function normalize(?string $hostname): ?string
    {
        if (! is_string($hostname)) {
            return null;
        }

        $hostname = strtolower(rtrim(trim($hostname), '.'));

        if ($hostname === '' || strlen($hostname) > 253) {
            return null;
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname)) {
            return null;
        }

        return $hostname;
    }

    public static function isLoopback(?string $hostname): bool
    {
        return in_array(self::normalize($hostname), ['localhost', '127.0.0.1'], true);
    }
}

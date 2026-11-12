<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Support\Hostname;
use App\Support\Auth\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralMfaPending
{
    private const MAX_AGE_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $centralHost = Hostname::normalize(config('saas.central_host'));
        $pendingUserId = (int) $session->get(AuthSession::MFA_PENDING_USER_ID);
        $pendingAt = (int) $session->get(AuthSession::MFA_PENDING_AT);

        $user = User::query()->find($pendingUserId);
        $staff = DB::table('platform_staff')
            ->where('user_id', $pendingUserId)
            ->where('status', 'active')
            ->where('role', 'super_admin')
            ->first();

        $valid = $pendingUserId > 0
            && $pendingAt > 0
            && now()->timestamp >= $pendingAt
            && now()->timestamp - $pendingAt <= self::MAX_AGE_SECONDS
            && $session->get(AuthSession::AREA) === 'central_mfa_pending'
            && $session->get(AuthSession::MFA_PENDING_HOST) === $centralHost
            && Hostname::fromRequest($request) === $centralHost
            && $user?->status === 'active'
            && $user?->email_verified_at !== null
            && (int) $session->get(AuthSession::MFA_PENDING_AUTH_VERSION) === (int) $user?->auth_version
            && $staff !== null;

        if (! $valid) {
            $request->session()->invalidate();

            return redirect()->route('central.login');
        }

        $request->attributes->set('central_mfa_user', $user);

        return $next($request);
    }
}

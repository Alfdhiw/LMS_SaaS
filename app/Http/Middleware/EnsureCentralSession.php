<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Services\CentralMfaService;
use App\Modules\Tenancy\Support\Hostname;
use App\Support\Auth\AuthSession;
use App\Support\Auth\AuthSessionManager;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralSession
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthSessionManager $sessionManager,
        private readonly CentralMfaService $mfa,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $guard = $this->auth->guard('web');
        $user = $guard->user();
        $centralHost = Hostname::normalize(config('saas.central_host'));

        $valid = $guard->check()
            && $user instanceof User
            && $session->get(AuthSession::AREA) === 'central'
            && $session->get(AuthSession::HOST) === $centralHost
            && Hostname::fromRequest($request) === $centralHost
            && (int) $session->get(AuthSession::USER_ID) === (int) $user->getAuthIdentifier()
            && $session->get(AuthSession::TENANT_ID) === null
            && $session->get(AuthSession::MEMBERSHIP_ID) === null;

        if (! $valid) {
            return $this->reject($request);
        }

        $freshUser = User::query()->find((int) $user->getAuthIdentifier());
        $staff = DB::table('platform_staff')
            ->where('user_id', $freshUser?->getAuthIdentifier())
            ->where('status', 'active')
            ->where('role', 'super_admin')
            ->first();

        $mfaSatisfied = ! $this->mfa->required()
            || (bool) $session->get(AuthSession::MFA_VERIFIED, false);

        if (
            $freshUser === null
            || $freshUser->status !== 'active'
            || $freshUser->email_verified_at === null
            || (int) $session->get(AuthSession::USER_AUTH_VERSION) !== (int) $freshUser->auth_version
            || $staff === null
            || ! $mfaSatisfied
        ) {
            return $this->reject($request);
        }

        $guard->setUser($freshUser);
        $request->attributes->set('central_user', $freshUser);

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        $this->sessionManager->logout($request);

        return redirect()->route('central.login');
    }
}

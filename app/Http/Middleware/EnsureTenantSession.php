<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Support\Hostname;
use App\Support\Auth\AuthSession;
use App\Support\Auth\AuthSessionManager;
use App\Support\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantSession
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthSessionManager $sessionManager,
        private readonly TenantContextStore $contextStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->contextStore->require();
        $session = $request->session();
        $guard = $this->auth->guard('web');
        $user = $guard->user();

        $valid = $guard->check()
            && $user instanceof User
            && $session->get(AuthSession::AREA) === 'tenant'
            && $session->get(AuthSession::HOST) === Hostname::fromRequest($request)
            && (int) $session->get(AuthSession::USER_ID) === (int) $user->getAuthIdentifier()
            && (int) $session->get(AuthSession::TENANT_ID) === $context->tenantId;

        if (! $valid) {
            return $this->reject($request);
        }

        $freshUser = User::query()->find((int) $user->getAuthIdentifier());
        $membershipId = (int) $session->get(AuthSession::MEMBERSHIP_ID);
        $membership = DB::table('tenant_memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $context->tenantId)
            ->where('user_id', $freshUser?->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        if (
            $freshUser === null
            || $freshUser->status !== 'active'
            || (int) $session->get(AuthSession::USER_AUTH_VERSION) !== (int) $freshUser->auth_version
            || $membership === null
            || (int) $session->get(AuthSession::MEMBERSHIP_AUTH_VERSION) !== (int) $membership->auth_version
        ) {
            return $this->reject($request);
        }

        $guard->setUser($freshUser);
        $request->attributes->set('tenant_user', $freshUser);
        $request->attributes->set('tenant_membership', $membership);

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        $this->sessionManager->logout($request);

        return redirect()->to('/login');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\TenantAuthenticator;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSessionManager;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class TenantAuthController extends Controller
{
    public function __construct(
        private readonly TenantContextStore $contextStore,
        private readonly TenantAuthenticator $authenticator,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly AuthSessionManager $sessionManager,
        private readonly AuthAuditLogger $auditLogger,
    ) {}

    public function showLogin(): Response
    {
        $context = $this->contextStore->require();

        return response()->view('auth.login', [
            'title' => 'Login Sekolah',
            'subtitle' => 'Portal '.$context->tenantSlug,
            'identifierLabel' => 'Username sekolah',
            'identifierName' => 'username',
            'action' => url('/login'),
            'value' => old('username'),
        ]);
    }

    public function login(Request $request): Response
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ]);
        $context = $this->contextStore->require();
        $username = IdentityNormalizer::username($validated['username']);

        if ($this->rateLimiter->isLimited($request, $context, $username)) {
            return $this->throttledResponse($request, $context->tenantSlug);
        }

        $identity = $this->authenticator->attempt($context, $username, $validated['password']);

        if ($identity === null) {
            $this->rateLimiter->recordFailure($request, $context, $username);
            $this->auditLogger->tenant(
                request: $request,
                context: $context,
                action: 'auth.login.failed',
                metadata: [
                    'reason' => 'invalid_credentials',
                    'identifier_hash' => $this->identifierHash($username),
                ],
            );

            return back()
                ->withErrors(['credentials' => 'Username atau password tidak valid.'])
                ->withInput($request->only('username'));
        }

        $this->rateLimiter->clear($request, $context, $username);
        $this->sessionManager->loginTenant($request, $context, $identity);
        $this->auditLogger->tenant(
            request: $request,
            context: $context,
            action: 'auth.login.succeeded',
            actorUserId: (int) $identity->user->getAuthIdentifier(),
            actorMembershipId: $identity->membershipId,
        );

        return redirect()->to('/dashboard');
    }

    public function dashboard(Request $request): Response
    {
        $context = $this->contextStore->require();
        $user = $request->attributes->get('tenant_user');

        return response()->view('auth.dashboard', [
            'title' => 'Dashboard Sekolah',
            'subtitle' => $context->tenantSlug,
            'userName' => $user?->name,
            'logoutAction' => url('/logout'),
        ]);
    }

    public function logout(Request $request): Response
    {
        $context = $this->contextStore->require();
        $user = Auth::guard('web')->user();
        $membership = $request->attributes->get('tenant_membership');

        $this->sessionManager->logout($request);
        $this->auditLogger->tenant(
            request: $request,
            context: $context,
            action: 'auth.logout',
            actorUserId: $user?->getAuthIdentifier(),
            actorMembershipId: $membership?->id,
        );

        return redirect()->to('/login');
    }

    private function throttledResponse(Request $request, string $tenantSlug): Response
    {
        $message = 'Terlalu banyak percobaan. Coba lagi nanti.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429);
        }

        return response()->view('auth.login', [
            'title' => 'Login Sekolah',
            'subtitle' => 'Portal '.$tenantSlug,
            'identifierLabel' => 'Username sekolah',
            'identifierName' => 'username',
            'action' => url('/login'),
            'value' => $request->input('username'),
            'error' => $message,
        ], 429);
    }

    private function identifierHash(string $identifier): string
    {
        return hash_hmac('sha256', 'tenant|'.$identifier, (string) (config('app.key') ?: 'm1-audit-key'));
    }
}

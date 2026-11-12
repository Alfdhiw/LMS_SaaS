<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\CentralAuthenticator;
use App\Modules\Identity\Services\CentralMfaService;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSessionManager;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CentralAuthController extends Controller
{
    public function __construct(
        private readonly CentralAuthenticator $authenticator,
        private readonly CentralMfaService $mfa,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly AuthSessionManager $sessionManager,
        private readonly AuthAuditLogger $auditLogger,
    ) {}

    public function showLogin(): Response
    {
        return response()->view('auth.login', [
            'title' => 'Login Platform',
            'subtitle' => 'Portal pusat SaaS',
            'identifierLabel' => 'Email terverifikasi',
            'identifierName' => 'email',
            'action' => route('central.login'),
            'value' => old('email'),
        ]);
    }

    public function login(Request $request): Response
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ]);
        $email = IdentityNormalizer::email($validated['email']);

        if ($this->rateLimiter->isLimited($request, null, $email)) {
            return $this->throttledResponse($request);
        }

        $identity = $this->authenticator->attempt($email, $validated['password']);

        if ($identity === null) {
            $this->rateLimiter->recordFailure($request, null, $email);
            $this->auditLogger->platform(
                request: $request,
                action: 'auth.login.failed',
                metadata: [
                    'reason' => 'invalid_credentials',
                    'identifier_hash' => $this->identifierHash($email),
                ],
            );

            return back()
                ->withErrors(['credentials' => 'Email atau password tidak valid.'])
                ->withInput($request->only('email'));
        }

        if ($this->mfa->required()) {
            $enrolled = $this->mfa->isEnrolled((int) $identity->user->getAuthIdentifier());
            $this->sessionManager->beginCentralMfa(
                $request,
                $identity,
                $enrolled ? null : $this->mfa->newSecret(),
            );
            $this->auditLogger->platform(
                request: $request,
                action: 'auth.login.mfa_pending',
                actorUserId: (int) $identity->user->getAuthIdentifier(),
                metadata: ['enrolled' => $enrolled],
            );

            return redirect()->route($enrolled ? 'central.mfa.challenge' : 'central.mfa.setup');
        }

        $this->rateLimiter->clear($request, null, $email);
        $this->sessionManager->loginCentral($request, $identity);
        $this->auditLogger->platform(
            request: $request,
            action: 'auth.login.succeeded',
            actorUserId: (int) $identity->user->getAuthIdentifier(),
        );

        return redirect()->route('central.dashboard');
    }

    public function dashboard(Request $request): Response
    {
        $user = $request->attributes->get('central_user');

        return response()->view('auth.dashboard', [
            'title' => 'Dashboard Platform',
            'subtitle' => 'Portal pusat SaaS',
            'userName' => $user?->name,
            'logoutAction' => route('central.logout'),
            'recoveryCodes' => $this->recoveryCodes($request),
        ]);
    }

    public function logout(Request $request): Response
    {
        $user = Auth::guard('web')->user();

        $this->sessionManager->logout($request);
        $this->auditLogger->platform(
            request: $request,
            action: 'auth.logout',
            actorUserId: $user?->getAuthIdentifier(),
        );

        return redirect()->route('central.login');
    }

    private function throttledResponse(Request $request): Response
    {
        $message = 'Terlalu banyak percobaan. Coba lagi nanti.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429);
        }

        return response()->view('auth.login', [
            'title' => 'Login Platform',
            'subtitle' => 'Portal pusat SaaS',
            'identifierLabel' => 'Email terverifikasi',
            'identifierName' => 'email',
            'action' => route('central.login'),
            'value' => $request->input('email'),
            'error' => $message,
        ], 429);
    }

    private function identifierHash(string $identifier): string
    {
        return hash_hmac('sha256', 'central|'.$identifier, (string) (config('app.key') ?: 'm1-audit-key'));
    }

    /**
     * @return list<string>
     */
    private function recoveryCodes(Request $request): array
    {
        $encryptedCodes = $request->session()->pull('auth.mfa_recovery_codes');

        if (! is_string($encryptedCodes) || $encryptedCodes === '') {
            return [];
        }

        try {
            $codes = json_decode(Crypt::decryptString($encryptedCodes), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }
}

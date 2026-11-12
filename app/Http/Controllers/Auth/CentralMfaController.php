<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Data\CentralLoginIdentity;
use App\Modules\Identity\Services\CentralMfaService;
use App\Modules\Platform\Services\PlatformMfaResetService;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSession;
use App\Support\Auth\AuthSessionManager;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CentralMfaController extends Controller
{
    public function __construct(
        private readonly CentralMfaService $mfa,
        private readonly PlatformMfaResetService $resetService,
        private readonly AuthSessionManager $sessionManager,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly AuthAuditLogger $auditLogger,
    ) {}

    public function showSetup(Request $request): Response
    {
        $user = $this->pendingUser($request);
        $secret = $this->setupSecret($request);

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('central.mfa.challenge');
        }

        return response()->view('auth.mfa_setup', [
            'title' => 'Aktifkan MFA Platform',
            'subtitle' => 'Tambahkan akun ini ke aplikasi authenticator sebelum melanjutkan.',
            'email' => $user->email,
            'secret' => $secret,
            'otpauthUri' => $this->mfa->otpauthUri($secret, (string) $user->email),
        ]);
    }

    public function setup(Request $request): Response
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $this->pendingUser($request);
        $secret = $this->setupSecret($request);

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('central.mfa.challenge');
        }

        if ($this->rateLimiter->isLimited($request, null, 'mfa|'.$user->getAuthIdentifier())) {
            return $this->mfaError('Terlalu banyak percobaan MFA. Coba lagi nanti.', 429);
        }

        $enrollment = $this->mfa->enroll((int) $user->getAuthIdentifier(), $secret, $validated['code']);

        if ($enrollment === false) {
            $this->recordMfaFailure($request, $user, 'invalid_enrollment_code');

            return back()->withErrors(['code' => 'Kode MFA tidak valid atau sudah kedaluwarsa.']);
        }

        $this->rateLimiter->clear($request, null, 'mfa|'.$user->getAuthIdentifier());
        $this->completeLogin($request, $user);
        $request->session()->put(
            'auth.mfa_recovery_codes',
            Crypt::encryptString(json_encode($enrollment['recoveryCodes'], JSON_THROW_ON_ERROR)),
        );
        $this->auditLogger->platform(
            request: $request,
            action: 'auth.mfa.enrolled',
            actorUserId: (int) $user->getAuthIdentifier(),
        );

        return redirect()->route('central.dashboard');
    }

    public function showChallenge(): Response
    {
        return response()->view('auth.mfa_challenge', [
            'title' => 'Verifikasi MFA Platform',
            'subtitle' => 'Masukkan kode dari aplikasi authenticator atau satu recovery code.',
            'action' => route('central.mfa.challenge'),
        ]);
    }

    public function challenge(Request $request): Response
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = $this->pendingUser($request);
        $identifier = 'mfa|'.$user->getAuthIdentifier();

        if ($this->rateLimiter->isLimited($request, null, $identifier)) {
            return $this->mfaError('Terlalu banyak percobaan MFA. Coba lagi nanti.', 429);
        }

        if (! $this->mfa->verify((int) $user->getAuthIdentifier(), $validated['code'])) {
            $this->recordMfaFailure($request, $user, 'invalid_code');

            return back()->withErrors(['code' => 'Kode MFA tidak valid.']);
        }

        $this->rateLimiter->clear($request, null, $identifier);
        $this->completeLogin($request, $user);
        $this->auditLogger->platform(
            request: $request,
            action: 'auth.mfa.verified',
            actorUserId: (int) $user->getAuthIdentifier(),
        );

        return redirect()->route('central.dashboard');
    }

    public function reset(Request $request, string $publicUlid): Response
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in([
                'device_lost',
                'suspected_compromise',
                'scheduled_reenrollment',
            ])],
        ]);
        $actor = $request->attributes->get('central_user');

        if (! $actor instanceof User) {
            abort(403);
        }

        $reset = $this->resetService->resetForReEnrollment(
            request: $request,
            actor: $actor,
            targetPublicUlid: $publicUlid,
            reason: $validated['reason'],
        );

        if (! $reset) {
            abort(404);
        }

        return redirect()
            ->route('central.dashboard')
            ->with('status', 'MFA target direset. Staf tersebut wajib melakukan enrollment ulang saat login berikutnya.');
    }

    private function pendingUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->attributes->get('central_mfa_user');

        return $user;
    }

    private function completeLogin(Request $request, User $user): void
    {
        $this->sessionManager->completeCentralLogin(
            $request,
            new CentralLoginIdentity($user, (int) $user->auth_version),
        );
    }

    private function setupSecret(Request $request): ?string
    {
        $encryptedSecret = $request->session()->get(AuthSession::MFA_SETUP_SECRET);

        if (! is_string($encryptedSecret) || $encryptedSecret === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedSecret);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordMfaFailure(Request $request, User $user, string $reason): void
    {
        $this->rateLimiter->recordFailure($request, null, 'mfa|'.$user->getAuthIdentifier());
        $this->auditLogger->platform(
            request: $request,
            action: 'auth.mfa.failed',
            actorUserId: (int) $user->getAuthIdentifier(),
            metadata: ['reason' => $reason],
        );
    }

    private function mfaError(string $message, int $status): Response
    {
        return response()->view('auth.mfa_challenge', [
            'title' => 'Verifikasi MFA Platform',
            'subtitle' => 'Masukkan kode dari aplikasi authenticator atau satu recovery code.',
            'action' => route('central.mfa.challenge'),
            'error' => $message,
        ], $status);
    }
}

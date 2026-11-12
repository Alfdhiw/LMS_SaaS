<?php

namespace App\Support\Auth;

use App\Modules\Identity\Data\CentralLoginIdentity;
use App\Modules\Identity\Data\TenantLoginIdentity;
use App\Modules\Tenancy\Data\TenantContext;
use App\Modules\Tenancy\Support\Hostname;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class AuthSessionManager
{
    public function __construct(private readonly AuthManager $auth) {}

    public function loginTenant(
        Request $request,
        TenantContext $context,
        TenantLoginIdentity $identity,
    ): void {
        $this->auth->guard('web')->login($identity->user, false);
        $request->session()->regenerate();
        $request->session()->put([
            AuthSession::AREA => 'tenant',
            AuthSession::HOST => Hostname::fromRequest($request),
            AuthSession::USER_ID => $identity->user->getAuthIdentifier(),
            AuthSession::TENANT_ID => $context->tenantId,
            AuthSession::MEMBERSHIP_ID => $identity->membershipId,
            AuthSession::USER_AUTH_VERSION => $identity->userAuthVersion,
            AuthSession::MEMBERSHIP_AUTH_VERSION => $identity->membershipAuthVersion,
            AuthSession::MFA_VERIFIED => false,
        ]);
    }

    public function loginCentral(Request $request, CentralLoginIdentity $identity): void
    {
        $this->auth->guard('web')->login($identity->user, false);
        $request->session()->regenerate();
        $request->session()->put([
            AuthSession::AREA => 'central',
            AuthSession::HOST => Hostname::fromRequest($request),
            AuthSession::USER_ID => $identity->user->getAuthIdentifier(),
            AuthSession::TENANT_ID => null,
            AuthSession::MEMBERSHIP_ID => null,
            AuthSession::USER_AUTH_VERSION => $identity->userAuthVersion,
            AuthSession::MEMBERSHIP_AUTH_VERSION => null,
            AuthSession::MFA_VERIFIED => false,
        ]);
    }

    public function beginCentralMfa(
        Request $request,
        CentralLoginIdentity $identity,
        ?string $setupSecret = null,
    ): void {
        $this->auth->guard('web')->logout();
        $request->session()->forget([
            AuthSession::AREA,
            AuthSession::HOST,
            AuthSession::USER_ID,
            AuthSession::TENANT_ID,
            AuthSession::MEMBERSHIP_ID,
            AuthSession::USER_AUTH_VERSION,
            AuthSession::MEMBERSHIP_AUTH_VERSION,
            AuthSession::MFA_VERIFIED,
            AuthSession::MFA_PENDING_USER_ID,
            AuthSession::MFA_PENDING_AUTH_VERSION,
            AuthSession::MFA_PENDING_HOST,
            AuthSession::MFA_PENDING_AT,
            AuthSession::MFA_SETUP_SECRET,
        ]);
        $request->session()->regenerate();
        $request->session()->put([
            AuthSession::AREA => 'central_mfa_pending',
            AuthSession::MFA_PENDING_HOST => Hostname::fromRequest($request),
            AuthSession::MFA_PENDING_USER_ID => $identity->user->getAuthIdentifier(),
            AuthSession::MFA_PENDING_AUTH_VERSION => $identity->userAuthVersion,
            AuthSession::MFA_PENDING_AT => now()->timestamp,
            AuthSession::MFA_SETUP_SECRET => $setupSecret === null ? null : Crypt::encryptString($setupSecret),
        ]);
    }

    public function completeCentralLogin(Request $request, CentralLoginIdentity $identity): void
    {
        $this->auth->guard('web')->login($identity->user, false);
        $request->session()->regenerate();
        $request->session()->put([
            AuthSession::AREA => 'central',
            AuthSession::HOST => Hostname::fromRequest($request),
            AuthSession::USER_ID => $identity->user->getAuthIdentifier(),
            AuthSession::TENANT_ID => null,
            AuthSession::MEMBERSHIP_ID => null,
            AuthSession::USER_AUTH_VERSION => $identity->userAuthVersion,
            AuthSession::MEMBERSHIP_AUTH_VERSION => null,
            AuthSession::MFA_VERIFIED => true,
        ]);
    }

    public function clearCentralMfaPending(Request $request): void
    {
        $request->session()->forget([
            AuthSession::MFA_PENDING_USER_ID,
            AuthSession::MFA_PENDING_AUTH_VERSION,
            AuthSession::MFA_PENDING_HOST,
            AuthSession::MFA_PENDING_AT,
            AuthSession::MFA_SETUP_SECRET,
        ]);
    }

    public function logout(Request $request): void
    {
        $this->auth->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}

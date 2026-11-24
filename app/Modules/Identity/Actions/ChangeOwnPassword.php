<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Services\CentralMfaService;
use App\Modules\Identity\Support\PasswordPolicy;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Auth\AuthSession;
use App\Support\Auth\CentralStaffAccess;
use App\Support\Http\Hostname;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ChangeOwnPassword
{
    public function __construct(
        private readonly TenantRegistry $tenants,
        private readonly CentralStaffAccess $staff,
        private readonly CentralMfaService $mfa,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function handle(Request $request, User $actor, ?TenantContext $context, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $password): void
    {
        Validator::make(['current_password' => $currentPassword, 'password' => $password, 'password_confirmation' => $password], [
            'password' => PasswordPolicy::rules(),
        ])->validate();
        DB::transaction(function () use ($request, $actor, $context, $currentPassword, $password): void {
            $session = $request->session();
            abort_unless((int) $session->get(AuthSession::USER_ID) === (int) $actor->id
                && $session->get(AuthSession::HOST) === Hostname::fromRequest($request), 403);
            if ($context !== null) {
                $this->tenants->lockContext($context);
                abort_unless($session->get(AuthSession::AREA) === 'tenant'
                    && $context->hostname === Hostname::fromRequest($request)
                    && (int) $session->get(AuthSession::TENANT_ID) === $context->tenantId, 403);
            } else {
                abort_unless($session->get(AuthSession::AREA) === 'central'
                    && Hostname::fromRequest($request) === Hostname::normalize(config('saas.central_host'))
                    && $this->staff->isActiveOperator((int) $actor->id)
                    && (! $this->mfa->required() || $session->get(AuthSession::MFA_VERIFIED) === true), 403);
            }
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user !== null && $user->status === 'active'
                && (int) $user->auth_version === (int) $session->get(AuthSession::USER_AUTH_VERSION)
                && ($context !== null || $user->email_verified_at !== null), 403);
            if ($context !== null) {
                $member = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
                    ->where('id', $session->get(AuthSession::MEMBERSHIP_ID))->where('user_id', $user->id)
                    ->where('status', 'active')->lockForUpdate()->first();
                abort_unless($member !== null && (int) $member->auth_version === (int) $session->get(AuthSession::MEMBERSHIP_AUTH_VERSION), 403);
            }
            if (! Hash::check($currentPassword, (string) $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak valid.']);
            }
            if (Hash::check($password, (string) $user->password)) {
                throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password saat ini.']);
            }
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($password), 'auth_version' => DB::raw('auth_version + 1'),
                'must_change_password' => false, 'remember_token' => null, 'password_changed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('account_recovery_requests')->where('user_id', $user->id)
                ->whereIn('status', ['requested', 'verified', 'token_issued'])->update(['status' => 'revoked', 'updated_at' => now()]);
            if ($context !== null) {
                $this->audit->tenant($request, $context, 'auth.password.changed', (int) $user->id, (int) $member->id,
                    (string) $user->public_ulid, ['scope' => 'global', 'outcome' => 'succeeded'], 'user');
            } else {
                $this->audit->platform($request, 'auth.password.changed', (int) $user->id,
                    (string) $user->public_ulid, ['scope' => 'global', 'outcome' => 'succeeded'], targetType: 'user');
            }
        });
    }
}

<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Tenancy\Data\TenantContext;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SchoolAssistedRecoveryService
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly AuthAuditLogger $audit) {}

    /** The browser retains the secret; ineligible users get an indistinguishable locator. */
    public function request(Request $request, TenantContext $context, string $username, string $commitment): string
    {
        $this->requireSharedCache();
        $id = (string) Str::ulid();
        $member = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
            ->where('local_username_normalized', IdentityNormalizer::username($username))->first(['user_id']);
        if ($member === null) {
            return $id;
        }

        try {
            DB::transaction(function () use ($request, $context, $commitment, $id, $member): void {
                $user = DB::table('users')->where('id', $member->user_id)->lockForUpdate()->first();
                abort_if($user === null, 404);
                $this->assertEligible($context, (int) $user->id);
                $expires = now()->addMinutes(30);
                Cache::put($this->cacheKey($context, $id), $commitment, $expires);
                DB::table('account_recovery_requests')->insert([
                    'public_ulid' => $id, 'tenant_id' => $context->tenantId, 'user_id' => $user->id,
                    'scope' => 'school_assisted', 'purpose' => 'password_reset', 'status' => 'requested',
                    'token_hash' => null, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->tenant($request, $context, 'recovery.school_assisted.requested', null, null, $id, [], 'account_recovery');
            });
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
        }

        return $id;
    }

    /** @return array{recovery_id:string,username:string,display_name:string} */
    public function preview(TenantContext $context, string $id): array
    {
        $item = DB::table('account_recovery_requests as r')
            ->join('tenant_memberships as m', function ($join): void {
                $join->on('m.user_id', '=', 'r.user_id')->on('m.tenant_id', '=', 'r.tenant_id');
            })
            ->where('r.tenant_id', $context->tenantId)->where('r.public_ulid', $id)
            ->where('r.scope', 'school_assisted')->where('r.status', 'requested')
            ->where('r.expires_at', '>', now())->where('m.status', 'active')
            ->first(['r.public_ulid', 'm.local_username', 'm.display_name']);
        abort_if($item === null, 404);

        return ['recovery_id' => $item->public_ulid, 'username' => $item->local_username, 'display_name' => $item->display_name];
    }

    public function approve(Request $request, TenantContext $context, User $actor, int $actorMembershipId, string $id): void
    {
        $this->requireSharedCache();
        DB::transaction(function () use ($request, $context, $actor, $actorMembershipId, $id): void {
            DB::table('tenants')->where('id', $context->tenantId)->lockForUpdate()->first();
            abort_unless($this->permissions->allows($actor, $context, $actorMembershipId, 'members.assisted_reset'), 403);
            $candidate = DB::table('account_recovery_requests')->where('tenant_id', $context->tenantId)
                ->where('public_ulid', $id)->where('scope', 'school_assisted')->first(['user_id']);
            abort_if($candidate === null, 404);
            $user = DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first();
            abort_if($user === null, 404);
            $recovery = DB::table('account_recovery_requests')->where('tenant_id', $context->tenantId)
                ->where('public_ulid', $id)->where('scope', 'school_assisted')->where('status', 'requested')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_if($recovery === null, 404);
            $this->assertEligible($context, (int) $user->id);
            $commitment = Cache::get($this->cacheKey($context, $id));
            abort_unless(is_string($commitment) && preg_match('/\A[0-9a-f]{64}\z/', $commitment) === 1, 410);
            DB::table('account_recovery_requests')->where('id', $recovery->id)->update([
                'initiated_by_user_id' => $actor->id, 'initiated_by_membership_id' => $actorMembershipId,
                'verified_by_user_id' => $actor->id, 'verified_at' => now(),
                'status' => 'token_issued', 'token_hash' => $commitment, 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'recovery.school_assisted.approved', (int) $actor->id, $actorMembershipId, $id, ['verification' => 'school_offline'], 'account_recovery');
        });
        // Cleanup is best-effort after commit. A cache outage must not report a
        // failed approval after the database already committed it; TTL is the fallback.
        try {
            Cache::forget($this->cacheKey($context, $id));
        } catch (\Throwable) {
            // No token or cache value is written to logs.
        }
    }

    public function consume(Request $request, TenantContext $context, string $recoveryId, string $token, string $password): void
    {
        DB::transaction(function () use ($request, $context, $recoveryId, $token, $password): void {
            $candidate = DB::table('account_recovery_requests')->where('public_ulid', $recoveryId)
                ->where('tenant_id', $context->tenantId)->where('scope', 'school_assisted')->first(['user_id']);
            abort_if($candidate === null, 404);
            $user = DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first();
            abort_if($user === null, 404);
            $recovery = DB::table('account_recovery_requests')->where('public_ulid', $recoveryId)
                ->where('tenant_id', $context->tenantId)->where('scope', 'school_assisted')
                ->where('status', 'token_issued')->where('expires_at', '>', now())
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_if($recovery === null, 404);
            $this->assertEligible($context, (int) $user->id);
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($password), 'auth_version' => DB::raw('auth_version + 1'),
                'password_changed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('account_recovery_requests')->where('user_id', $user->id)
                ->whereIn('status', ['requested', 'verified', 'token_issued'])->where('id', '<>', $recovery->id)
                ->update(['status' => 'revoked', 'updated_at' => now()]);
            DB::table('account_recovery_requests')->where('id', $recovery->id)->update([
                'status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'recovery.school_assisted.consumed', (int) $user->id, null, $recoveryId, [], 'account_recovery');
        });
    }

    private function cacheKey(TenantContext $context, string $id): string
    {
        return 'tenant:'.$context->tenantId.':school-recovery:'.$id;
    }

    private function requireSharedCache(): void
    {
        $store = (string) config('cache.default');
        abort_if(app()->environment('production') && config('cache.stores.'.$store.'.driver') !== 'redis', 503);
    }

    private function assertEligible(TenantContext $context, int $userId): void
    {
        $member = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)
            ->where('user_id', $userId)->where('status', 'active')->first();
        abort_if($member === null, 404);
        $user = DB::table('users')->where('id', $userId)->first(['email', 'status']);
        $roles = DB::table('membership_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.tenant_id', $context->tenantId)->where('mr.membership_id', $member->id)->pluck('r.key');
        abort_unless($user !== null && $user->status === 'active' && $user->email === null
            && $roles->contains('student') && $roles->every(fn ($role) => $role === 'student')
            && DB::table('tenant_memberships')->where('user_id', $userId)->count() === 1
            && ! DB::table('platform_staff')->where('user_id', $userId)->exists(), 403);
    }
}

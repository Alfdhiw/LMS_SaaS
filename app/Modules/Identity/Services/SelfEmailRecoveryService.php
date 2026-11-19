<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Mail\SelfRecoveryMail;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Tenancy\Data\TenantContext;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class SelfEmailRecoveryService
{
    public function __construct(private readonly AuthAuditLogger $audit) {}

    public function issue(Request $request, TenantContext $context, string $email): void
    {
        $address = IdentityNormalizer::email($email);
        $userId = DB::table('users')->where('email_normalized', $address)->whereNotNull('email_verified_at')
            ->where('status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('tenant_memberships')
            ->whereColumn('tenant_memberships.user_id', 'users.id')->where('tenant_memberships.tenant_id', $context->tenantId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('platform_staff')->whereColumn('platform_staff.user_id', 'users.id'))
            ->value('id');
        if ($userId === null) {
            return;
        }

        $issued = DB::transaction(function () use ($request, $context, $userId): ?array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if ($user === null || $user->status !== 'active' || $user->email_verified_at === null
                || DB::table('platform_staff')->where('user_id', $userId)->exists()) {
                return null;
            }
            DB::table('account_recovery_requests')->where('user_id', $userId)->where('scope', 'self_email')
                ->where('status', 'token_issued')->update(['status' => 'revoked', 'updated_at' => now()]);
            $id = (string) Str::ulid();
            $token = bin2hex(random_bytes(32));
            DB::table('account_recovery_requests')->insert([
                'public_ulid' => $id, 'tenant_id' => null, 'user_id' => $userId, 'scope' => 'self_email',
                'purpose' => 'password_reset', 'status' => 'token_issued', 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->tenant($request, $context, 'recovery.self_email.issued', null, null, $id, [], 'account_recovery');

            return ['id' => $id, 'token' => $token, 'email' => $user->email];
        });
        if ($issued === null) {
            return;
        }

        DB::afterCommit(fn () => defer(fn () => $this->deliver($request, $context, $issued)));
    }

    /** @param array{id:string,token:string,email:string} $issued */
    private function deliver(Request $request, TenantContext $context, array $issued): void
    {
        $scheme = app()->environment(['local', 'testing']) ? $request->getScheme() : 'https';
        $port = app()->environment(['local', 'testing']) && ! in_array($request->getPort(), [80, 443], true) ? ':'.$request->getPort() : '';
        // The fragment is never sent in the HTTP request or proxy access log.
        $url = $scheme.'://'.$context->hostname.$port.'/password/reset#'.http_build_query(['id' => $issued['id'], 'token' => $issued['token']]);
        try {
            $mailer = (string) config('mail.default');
            $transport = (string) config('mail.mailers.'.$mailer.'.transport');
            if (in_array($transport, ['', 'log', 'failover', 'roundrobin'], true)
                || ($transport === 'array' && ! app()->environment('testing'))) {
                throw new \RuntimeException('Unsafe recovery mail transport.');
            }
            Mail::to($issued['email'])->send(new SelfRecoveryMail($url));
            $outcome = 'sent';
        } catch (Throwable) {
            // Never report mail exceptions: providers may include addresses or message contents.
            $outcome = 'failed';
        }
        $this->audit->tenant($request, $context, 'recovery.self_email.delivery_'.$outcome, null, null, $issued['id'], [], 'account_recovery');
    }

    public function consume(Request $request, TenantContext $context, string $id, string $token, string $password): void
    {
        DB::transaction(function () use ($request, $context, $id, $token, $password): void {
            $candidate = DB::table('account_recovery_requests')->where('public_ulid', $id)->where('scope', 'self_email')->first(['user_id']);
            abort_if($candidate === null, 404);
            $user = DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first();
            abort_if($user === null || $user->status !== 'active' || $user->email_verified_at === null, 404);
            $belongs = DB::table('tenant_memberships')->where('tenant_id', $context->tenantId)->where('user_id', $user->id)->exists();
            abort_if(! $belongs || DB::table('platform_staff')->where('user_id', $user->id)->exists(), 404);
            $recovery = DB::table('account_recovery_requests')->where('public_ulid', $id)->where('user_id', $user->id)
                ->where('scope', 'self_email')->where('status', 'token_issued')->where('expires_at', '>', now())
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_if($recovery === null, 404);
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($password), 'auth_version' => DB::raw('auth_version + 1'),
                'password_changed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('account_recovery_requests')->where('user_id', $user->id)->where('status', 'token_issued')
                ->where('id', '<>', $recovery->id)->update(['status' => 'revoked', 'updated_at' => now()]);
            DB::table('account_recovery_requests')->where('id', $recovery->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
            $this->audit->tenant($request, $context, 'recovery.self_email.consumed', (int) $user->id, null, $id, [], 'account_recovery');
        });
    }
}

<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Services\CentralMfaService;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformMfaResetService
{
    public function __construct(
        private readonly CentralMfaService $mfa,
        private readonly AuthAuditLogger $auditLogger,
    ) {}

    public function resetForReEnrollment(
        Request $request,
        User $actor,
        string $targetPublicUlid,
        string $reason,
    ): bool {
        return DB::transaction(function () use ($request, $actor, $targetPublicUlid, $reason): bool {
            $target = User::query()
                ->where('public_ulid', $targetPublicUlid)
                ->lockForUpdate()
                ->first();

            if ($target === null || ! $this->isActivePlatformStaff((int) $target->getAuthIdentifier())) {
                return false;
            }

            if (! $this->mfa->resetCredentialForReEnrollment((int) $target->getAuthIdentifier())) {
                return false;
            }

            $this->auditLogger->platform(
                request: $request,
                action: 'auth.mfa.reset',
                actorUserId: (int) $actor->getAuthIdentifier(),
                targetId: $this->targetAuditId($target),
                metadata: [
                    'reason' => $reason,
                    'outcome' => 'succeeded',
                ],
            );

            return true;
        });
    }

    private function isActivePlatformStaff(int $userId): bool
    {
        return DB::table('platform_staff')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    private function targetAuditId(User $target): string
    {
        return hash_hmac(
            'sha256',
            'platform-mfa|'.$target->public_ulid,
            (string) (config('app.key') ?: 'm1-audit-key'),
        );
    }
}

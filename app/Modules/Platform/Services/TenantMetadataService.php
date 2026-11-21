<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Services\PlatformPermissionChecker;
use App\Modules\Tenancy\Actions\UpdateTenantMetadata;
use App\Support\Audit\AuthAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantMetadataService
{
    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        private readonly UpdateTenantMetadata $update,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function update(Request $request, User $actor, string $publicUlid, string $name, string $timezone): void
    {
        DB::transaction(function () use ($request, $actor, $publicUlid, $name, $timezone): void {
            DB::table('platform_staff')->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($this->permissions->allows($actor, 'platform.tenants.update'), 403);
            $changed = $this->update->execute($publicUlid, $name, $timezone);
            abort_if($changed === null, 404);
            if ($changed['old_name'] === $name && $changed['old_timezone'] === $timezone) {
                return;
            }
            $this->audit->platform(
                request: $request,
                action: 'tenant.metadata.updated',
                actorUserId: (int) $actor->id,
                targetId: $publicUlid,
                metadata: [
                    'name_before_hash' => hash_hmac('sha256', $changed['old_name'], (string) config('app.key')),
                    'name_after_hash' => hash_hmac('sha256', $name, (string) config('app.key')),
                    'timezone_before' => $changed['old_timezone'],
                    'timezone_after' => $timezone,
                ],
                tenantId: $changed['id'],
                targetType: 'tenant',
            );
        });
    }
}

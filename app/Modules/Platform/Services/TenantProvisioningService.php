<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\BootstrapAdminAccounts;
use App\Modules\Platform\Data\ProvisionTenantData;
use App\Modules\Platform\Data\ProvisionTenantResult;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Exceptions\OperationException;
use App\Support\Http\Hostname;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class TenantProvisioningService
{
    private bool $requestOwned = false;

    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        private readonly AuthAuditLogger $auditLogger,
        private readonly TenantRegistry $tenants,
        private readonly BootstrapAdminAccounts $bootstrap,
    ) {}

    public function provision(
        Request $request,
        User $actor,
        ProvisionTenantData $data,
    ): ProvisionTenantResult {
        $this->assertActor($actor);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 255) {
            throw new OperationException(
                failureCode: 'idempotency_key_required',
                statusCode: 422,
                message: 'Idempotency-Key wajib berisi 16 sampai 255 karakter.',
            );
        }

        $this->validateBusinessInput($data);
        $keyHash = hash('sha256', $idempotencyKey);
        $payloadDigest = $this->payloadDigest($data);
        $this->requestOwned = false;

        try {
            return DB::transaction(function () use ($request, $actor, $data, $keyHash, $payloadDigest): ProvisionTenantResult {
                $this->lockIdempotencyKey((int) $actor->getAuthIdentifier(), $keyHash);

                $existing = DB::table('tenant_provisioning_requests')
                    ->where('actor_user_id', $actor->getAuthIdentifier())
                    ->where('idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals((string) $existing->payload_digest, $payloadDigest)) {
                        throw new OperationException(
                            failureCode: 'idempotency_payload_mismatch',
                            statusCode: 409,
                            message: 'Idempotency-Key sudah digunakan untuk payload berbeda.',
                        );
                    }

                    if ($existing->status === 'succeeded' && $existing->tenant_id !== null) {
                        return $this->resultFromExisting($existing, replayed: true);
                    }

                    if ($existing->status === 'processing') {
                        throw new OperationException(
                            failureCode: 'provisioning_in_progress',
                            statusCode: 409,
                            message: 'Provisioning dengan Idempotency-Key tersebut masih berjalan.',
                        );
                    }

                    DB::table('tenant_provisioning_requests')
                        ->where('id', $existing->id)
                        ->update([
                            'status' => 'processing',
                            'failure_code' => null,
                            'completed_at' => null,
                            'updated_at' => now(),
                        ]);
                    $this->requestOwned = true;
                } else {
                    DB::table('tenant_provisioning_requests')->insert([
                        'actor_user_id' => $actor->getAuthIdentifier(),
                        'idempotency_key_hash' => $keyHash,
                        'payload_digest' => $payloadDigest,
                        'status' => 'processing',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->requestOwned = true;
                }

                $tenantPublicUlid = (string) Str::ulid();
                $hostname = $this->hostnameFor($data);
                $tenantId = $this->tenants->createPending(
                    $tenantPublicUlid, $data->slug, $data->schoolName, $data->timezone, $hostname,
                    (int) $actor->getAuthIdentifier(),
                );
                $invitation = $this->bootstrap->create(
                    $tenantId, (int) $actor->getAuthIdentifier(), $data->adminName, $data->adminEmail, $data->adminUsername,
                );
                $invitationPublicUlid = $invitation->publicUlid;
                $invitationToken = $invitation->token;

                DB::table('tenant_provisioning_requests')
                    ->where('actor_user_id', $actor->getAuthIdentifier())
                    ->where('idempotency_key_hash', $keyHash)
                    ->update([
                        'tenant_id' => $tenantId,
                        'status' => 'succeeded',
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);

                $this->auditLogger->platform(
                    request: $request,
                    action: 'tenant.create.succeeded',
                    actorUserId: (int) $actor->getAuthIdentifier(),
                    targetId: $tenantPublicUlid,
                    metadata: [
                        'slug' => $data->slug,
                        'hostname' => $hostname,
                        'invitation_public_ulid' => $invitationPublicUlid,
                        'idempotent_replay' => false,
                    ],
                    tenantId: $tenantId,
                    targetType: 'tenant',
                );

                return new ProvisionTenantResult(
                    tenantPublicUlid: $tenantPublicUlid,
                    tenantSlug: $data->slug,
                    tenantHostname: $hostname,
                    tenantStatus: 'pending',
                    invitationPublicUlid: $invitationPublicUlid,
                    invitationToken: $invitationToken,
                    replayed: false,
                );
            });
        } catch (OperationException $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, $exception->failureCode);
            $this->recordFailureAudit($request, $actor, $exception->failureCode);
            throw $exception;
        } catch (QueryException $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, 'provisioning_conflict');
            $this->recordFailureAudit($request, $actor, 'provisioning_conflict');

            throw new OperationException(
                failureCode: 'provisioning_conflict',
                statusCode: 409,
                message: 'Provisioning ditolak karena konflik data.',
            );
        } catch (Throwable $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, 'provisioning_failed');
            $this->recordFailureAudit($request, $actor, 'provisioning_failed');

            throw new OperationException(
                failureCode: 'provisioning_failed',
                statusCode: 500,
                message: 'Provisioning gagal diproses.',
            );
        }
    }

    private function assertActor(User $actor): void
    {
        if (! $this->permissions->allows($actor, 'platform.tenants.create')) {
            throw new OperationException(
                failureCode: 'forbidden',
                statusCode: 403,
                message: 'Aktor tidak memiliki izin provisioning tenant.',
            );
        }
    }

    private function validateBusinessInput(ProvisionTenantData $data): void
    {
        if (in_array($data->slug, config('saas.reserved_tenant_slugs', []), true)) {
            throw new OperationException(
                failureCode: 'reserved_slug',
                statusCode: 422,
                message: 'Slug tenant termasuk nama yang dicadangkan.',
            );
        }

        if (! in_array($data->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new OperationException(
                failureCode: 'invalid_timezone',
                statusCode: 422,
                message: 'Timezone tenant tidak valid.',
            );
        }
    }

    private function lockIdempotencyKey(int $actorUserId, string $keyHash): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['tenant-provisioning|'.$actorUserId.'|'.$keyHash],
        );
    }

    private function hostnameFor(ProvisionTenantData $data): string
    {
        $baseDomain = Hostname::normalize((string) config('saas.tenant_base_domain'));
        $hostname = Hostname::normalize($data->slug.'.'.$baseDomain);

        if ($baseDomain === null || $hostname === null || $hostname === Hostname::normalize(config('saas.central_host'))) {
            throw new OperationException(
                failureCode: 'invalid_tenant_hostname',
                statusCode: 422,
                message: 'Hostname tenant tidak valid.',
            );
        }

        return $hostname;
    }

    private function payloadDigest(ProvisionTenantData $data): string
    {
        return hash('sha256', json_encode($data->canonicalPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resultFromExisting(object $requestRow, bool $replayed): ProvisionTenantResult
    {
        $tenant = DB::table('tenants')->where('id', $requestRow->tenant_id)->first();
        $invitation = DB::table('account_invitations')
            ->where('tenant_id', $requestRow->tenant_id)
            ->where('purpose', 'bootstrap_admin')
            ->latest('id')
            ->first();

        if ($tenant === null || $invitation === null) {
            throw new OperationException(
                failureCode: 'provisioning_record_incomplete',
                statusCode: 500,
                message: 'Catatan provisioning tidak lengkap.',
            );
        }

        return new ProvisionTenantResult(
            tenantPublicUlid: (string) $tenant->public_ulid,
            tenantSlug: (string) $tenant->slug,
            tenantHostname: (string) DB::table('tenant_domains')->where('tenant_id', $tenant->id)->value('hostname'),
            tenantStatus: (string) $tenant->status,
            invitationPublicUlid: (string) $invitation->public_ulid,
            invitationToken: null,
            replayed: $replayed,
        );
    }

    private function markFailed(User $actor, string $keyHash, string $payloadDigest, string $failureCode): void
    {
        if (! $this->requestOwned) {
            return;
        }

        DB::table('tenant_provisioning_requests')->updateOrInsert(
            [
                'actor_user_id' => $actor->getAuthIdentifier(),
                'idempotency_key_hash' => $keyHash,
            ],
            [
                'payload_digest' => $payloadDigest,
                'status' => 'failed',
                'failure_code' => $failureCode,
                'tenant_id' => null,
                'completed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function recordFailureAudit(Request $request, User $actor, string $failureCode): void
    {
        $this->auditLogger->platform(
            request: $request,
            action: 'tenant.create.failed',
            actorUserId: (int) $actor->getAuthIdentifier(),
            metadata: [
                'failure_code' => $failureCode,
                'outcome' => 'failed',
            ],
            targetType: 'tenant_provisioning_request',
        );
    }
}

<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Identity\Services\PlatformPermissionChecker;
use App\Modules\Identity\Support\IdentityNormalizer;
use App\Modules\Platform\Data\ProvisionTenantData;
use App\Modules\Platform\Data\ProvisionTenantResult;
use App\Modules\Tenancy\Support\Hostname;
use App\Support\Audit\AuthAuditLogger;
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
    ) {}

    public function provision(
        Request $request,
        User $actor,
        ProvisionTenantData $data,
    ): ProvisionTenantResult {
        $this->assertActor($actor);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 255) {
            throw new PlatformOperationException(
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
                        throw new PlatformOperationException(
                            failureCode: 'idempotency_payload_mismatch',
                            statusCode: 409,
                            message: 'Idempotency-Key sudah digunakan untuk payload berbeda.',
                        );
                    }

                    if ($existing->status === 'succeeded' && $existing->tenant_id !== null) {
                        return $this->resultFromExisting($existing, replayed: true);
                    }

                    if ($existing->status === 'processing') {
                        throw new PlatformOperationException(
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

                $this->assertNoCollision($data);

                $roleId = DB::table('roles')->where('key', 'school_admin')->value('id');

                if ($roleId === null) {
                    throw new PlatformOperationException(
                        failureCode: 'role_catalog_missing',
                        statusCode: 503,
                        message: 'Role catalog belum tersedia.',
                    );
                }

                $tenantPublicUlid = (string) Str::ulid();
                $hostname = $this->hostnameFor($data);
                $now = now();
                $tenantId = (int) DB::table('tenants')->insertGetId([
                    'public_ulid' => $tenantPublicUlid,
                    'slug' => $data->slug,
                    'name' => $data->schoolName,
                    'status' => 'pending',
                    'timezone' => $data->timezone,
                    'created_by_platform_user_id' => $actor->getAuthIdentifier(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('tenant_domains')->insert([
                    'tenant_id' => $tenantId,
                    'hostname' => $hostname,
                    'kind' => 'default',
                    'status' => 'active',
                    'verified_at' => $now,
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('tenant_settings')->insert([
                    'tenant_id' => $tenantId,
                    'onboarding_json' => json_encode([
                        'admin_activation_required' => true,
                        'provisioned_at' => $now->toIso8601String(),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $userId = (int) DB::table('users')->insertGetId([
                    'public_ulid' => (string) Str::ulid(),
                    'name' => $data->adminName,
                    'email' => $data->adminEmail,
                    'email_normalized' => IdentityNormalizer::email($data->adminEmail),
                    'password' => null,
                    'status' => 'pending',
                    'auth_version' => 1,
                    'must_change_password' => false,
                    'password_changed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $membershipId = (int) DB::table('tenant_memberships')->insertGetId([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'local_username' => $data->adminUsername,
                    'local_username_normalized' => $data->adminUsername,
                    'display_name' => $data->adminName,
                    'status' => 'invited',
                    'auth_version' => 1,
                    'joined_at' => null,
                    'left_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('membership_roles')->insert([
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                    'role_id' => $roleId,
                ]);

                $invitationPublicUlid = (string) Str::ulid();
                $invitationToken = bin2hex(random_bytes(32));
                DB::table('account_invitations')->insert([
                    'public_ulid' => $invitationPublicUlid,
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                    'target_user_id' => $userId,
                    'proposed_local_username' => null,
                    'proposed_local_username_normalized' => null,
                    'proposed_role_id' => null,
                    'purpose' => 'bootstrap_admin',
                    'token_hash' => hash('sha256', $invitationToken),
                    'status' => 'pending',
                    'expires_at' => $now->copy()->addHours((int) config('saas.provisioning_invitation_ttl_hours', 72)),
                    'consumed_at' => null,
                    'created_by_membership_id' => null,
                    'created_by_platform_user_id' => $actor->getAuthIdentifier(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

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
        } catch (PlatformOperationException $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, $exception->failureCode);
            $this->recordFailureAudit($request, $actor, $exception->failureCode);
            throw $exception;
        } catch (QueryException $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, 'provisioning_conflict');
            $this->recordFailureAudit($request, $actor, 'provisioning_conflict');

            throw new PlatformOperationException(
                failureCode: 'provisioning_conflict',
                statusCode: 409,
                message: 'Provisioning ditolak karena konflik data.',
            );
        } catch (Throwable $exception) {
            $this->markFailed($actor, $keyHash, $payloadDigest, 'provisioning_failed');
            $this->recordFailureAudit($request, $actor, 'provisioning_failed');

            throw new PlatformOperationException(
                failureCode: 'provisioning_failed',
                statusCode: 500,
                message: 'Provisioning gagal diproses.',
            );
        }
    }

    private function assertActor(User $actor): void
    {
        if (! $this->permissions->allows($actor, 'platform.tenants.create')) {
            throw new PlatformOperationException(
                failureCode: 'forbidden',
                statusCode: 403,
                message: 'Aktor tidak memiliki izin provisioning tenant.',
            );
        }
    }

    private function validateBusinessInput(ProvisionTenantData $data): void
    {
        if (in_array($data->slug, config('saas.reserved_tenant_slugs', []), true)) {
            throw new PlatformOperationException(
                failureCode: 'reserved_slug',
                statusCode: 422,
                message: 'Slug tenant termasuk nama yang dicadangkan.',
            );
        }

        if (! in_array($data->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new PlatformOperationException(
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

    private function assertNoCollision(ProvisionTenantData $data): void
    {
        $hostname = $this->hostnameFor($data);

        if (DB::table('tenants')->where('slug', $data->slug)->exists()) {
            throw new PlatformOperationException(
                failureCode: 'slug_collision',
                statusCode: 409,
                message: 'Slug tenant sudah digunakan.',
            );
        }

        if (DB::table('tenant_domains')->where('hostname', $hostname)->exists()) {
            throw new PlatformOperationException(
                failureCode: 'domain_collision',
                statusCode: 409,
                message: 'Hostname tenant sudah digunakan.',
            );
        }

        if (DB::table('users')->where('email_normalized', $data->adminEmail)->exists()) {
            throw new PlatformOperationException(
                failureCode: 'admin_email_exists',
                statusCode: 409,
                message: 'Email admin sudah terhubung ke akun lain.',
            );
        }

    }

    private function hostnameFor(ProvisionTenantData $data): string
    {
        $baseDomain = Hostname::normalize((string) config('saas.tenant_base_domain'));
        $hostname = Hostname::normalize($data->slug.'.'.$baseDomain);

        if ($baseDomain === null || $hostname === null || $hostname === Hostname::normalize(config('saas.central_host'))) {
            throw new PlatformOperationException(
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
            throw new PlatformOperationException(
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

<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CentralMfaService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Totp $totp) {}

    public function required(): bool
    {
        return (bool) config('saas.central_mfa_required', false)
            || (app()->environment('production') && (bool) config('saas.central_mfa_required_in_production', true));
    }

    public function isEnrolled(int $userId): bool
    {
        return DB::table('platform_mfa_credentials')
            ->where('user_id', $userId)
            ->whereNotNull('enabled_at')
            ->exists();
    }

    public function resetCredentialForReEnrollment(int $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            $credential = DB::table('platform_mfa_credentials')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($credential === null || $credential->enabled_at === null) {
                return false;
            }

            DB::table('platform_mfa_credentials')
                ->where('user_id', $userId)
                ->delete();

            DB::table('users')
                ->where('id', $userId)
                ->increment('auth_version');

            return true;
        });
    }

    /**
     * @return array{secret: string, recoveryCodes: list<string>}
     */
    public function enroll(int $userId, string $secret, string $code): array|false
    {
        if ($this->totp->verify($secret, $code) === null) {
            return false;
        }

        $recoveryCodes = [];
        $recoveryHashes = [];

        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $recoveryCode = strtoupper(bin2hex(random_bytes(5)));
            $recoveryCodes[] = $recoveryCode;
            $recoveryHashes[] = $this->recoveryHash($recoveryCode);
        }

        DB::table('platform_mfa_credentials')->updateOrInsert(
            ['user_id' => $userId],
            [
                'secret_encrypted' => Crypt::encryptString($secret),
                'recovery_code_hashes' => json_encode($recoveryHashes, JSON_THROW_ON_ERROR),
                'enabled_at' => now(),
                'last_used_counter' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return ['secret' => $secret, 'recoveryCodes' => $recoveryCodes];
    }

    public function verify(int $userId, string $code): bool
    {
        return DB::transaction(function () use ($userId, $code): bool {
            $credential = DB::table('platform_mfa_credentials')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($credential === null) {
                return false;
            }

            try {
                $secret = Crypt::decryptString((string) $credential->secret_encrypted);
            } catch (Throwable) {
                return false;
            }

            $counter = $this->totp->verify($secret, $code);

            if ($counter !== null) {
                if ($credential->last_used_counter !== null && $counter <= (int) $credential->last_used_counter) {
                    return false;
                }

                DB::table('platform_mfa_credentials')
                    ->where('user_id', $userId)
                    ->update(['last_used_counter' => $counter, 'updated_at' => now()]);

                return true;
            }

            try {
                $recoveryHashes = json_decode(
                    (string) $credential->recovery_code_hashes,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (Throwable) {
                return false;
            }

            foreach ($recoveryHashes as $index => $hash) {
                if (! hash_equals((string) $hash, $this->recoveryHash($code))) {
                    continue;
                }

                unset($recoveryHashes[$index]);
                DB::table('platform_mfa_credentials')
                    ->where('user_id', $userId)
                    ->update([
                        'recovery_code_hashes' => json_encode(array_values($recoveryHashes), JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);

                return true;
            }

            return false;
        });
    }

    public function otpauthUri(string $secret, string $email): string
    {
        $issuer = 'LMS SaaS Platform';
        $label = rawurlencode($issuer.':'.$email);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    public function newSecret(): string
    {
        return $this->totp->generateSecret();
    }

    public function currentCode(string $secret): string
    {
        return $this->totp->currentCode($secret);
    }

    private function recoveryHash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }
}

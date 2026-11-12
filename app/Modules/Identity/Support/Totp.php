<?php

namespace App\Modules\Identity\Support;

use InvalidArgumentException;

final class Totp
{
    private const PERIOD_SECONDS = 30;

    private const DIGITS = 6;

    private const SECRET_BYTES = 20;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public function currentCode(string $secret): string
    {
        return $this->codeAt($secret, intdiv(now()->timestamp, self::PERIOD_SECONDS));
    }

    public function verify(string $secret, string $code): ?int
    {
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $counter = intdiv(now()->timestamp, self::PERIOD_SECONDS);

        foreach ([-1, 0, 1] as $offset) {
            $candidateCounter = $counter + $offset;

            if ($candidateCounter >= 0 && hash_equals($this->codeAt($secret, $candidateCounter), $code)) {
                return $candidateCounter;
            }
        }

        return null;
    }

    private function codeAt(string $secret, int $counter): string
    {
        $decodedSecret = $this->base32Decode($secret);
        $counterBytes = pack('N2', intdiv($counter, 0x100000000), $counter % 0x100000000);
        $hash = hash_hmac('sha1', $counterBytes, $decodedSecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binaryCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binaryCode % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(rtrim($value, '='));
        $bits = '';

        foreach (str_split($value) as $character) {
            $index = strpos($alphabet, $character);

            if ($index === false) {
                throw new InvalidArgumentException('Invalid TOTP secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}

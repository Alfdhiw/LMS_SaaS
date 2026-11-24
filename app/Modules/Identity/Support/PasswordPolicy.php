<?php

namespace App\Modules\Identity\Support;

use Closure;
use Illuminate\Support\Facades\Hash;

final class PasswordPolicy
{
    public static function byteLimit(): int
    {
        return Hash::getDefaultDriver() === 'bcrypt' ? min(72, (int) (config('hashing.bcrypt.limit') ?: 72)) : 512;
    }

    public static function rules(): array
    {
        return [
            'required', 'string', 'min:12', 'max:128', 'confirmed', 'different:current_password',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::byteLimit()) {
                    $fail('Password baru melebihi batas '.self::byteLimit().' byte.');
                }
            },
        ];
    }
}

<?php

namespace App\Modules\Identity\Support;

final class IdentityNormalizer
{
    public static function username(string $username): string
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}

<?php

namespace App\Modules\Identity\Data;

final readonly class BootstrapInvitation
{
    public function __construct(public string $publicUlid, #[\SensitiveParameter] public string $token) {}
}

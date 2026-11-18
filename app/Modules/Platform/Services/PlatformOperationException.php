<?php

namespace App\Modules\Platform\Services;

use RuntimeException;

final class PlatformOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

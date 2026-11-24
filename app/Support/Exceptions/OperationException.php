<?php

namespace App\Support\Exceptions;

use RuntimeException;

final class OperationException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

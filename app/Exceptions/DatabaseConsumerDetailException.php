<?php

namespace App\Exceptions;

use RuntimeException;

class DatabaseConsumerDetailException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}

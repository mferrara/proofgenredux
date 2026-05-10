<?php

namespace App\Services\Ferraraphoto;

use RuntimeException;
use Throwable;

class FerraraphotoApiException extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $status,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}

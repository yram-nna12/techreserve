<?php

namespace App\Libraries;

use RuntimeException;
use Throwable;

class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        private readonly string $failureCode = 'gateway_error',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}

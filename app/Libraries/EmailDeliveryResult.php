<?php

namespace App\Libraries;

final class EmailDeliveryResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly bool $permanent,
        public readonly string $errorCode = ''
    ) {
    }

    public static function success(): self
    {
        return new self(true, false);
    }

    public static function temporaryFailure(string $errorCode): self
    {
        return new self(false, false, $errorCode);
    }

    public static function permanentFailure(string $errorCode): self
    {
        return new self(false, true, $errorCode);
    }
}

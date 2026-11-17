<?php

namespace App\Services;

interface PaymentStatusProviderInterface
{
    public function status(array $expected): array;
}

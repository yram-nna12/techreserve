<?php

namespace App\Services;

interface PaymentStateApplierInterface
{
    public function apply(
        int $reservationId,
        int $paymentId,
        array $gatewayData,
        ?array $receipt = null
    ): array;
}

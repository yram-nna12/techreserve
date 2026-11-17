<?php

namespace App\Services;

use App\Libraries\PaymentWebhookException;

final class PaymentWebhookReceiptPolicy
{
    public static function isDuplicate(?array $receipt, int $paymentId, string $payloadHash): bool
    {
        if ($receipt === null) {
            return false;
        }

        if ((int) ($receipt['payment_id'] ?? 0) !== $paymentId
            || !is_string($receipt['payload_hash'] ?? null)
            || !hash_equals($receipt['payload_hash'], $payloadHash)) {
            throw new PaymentWebhookException(
                409,
                'webhook_event_conflict',
                'The payment event conflicts with a previously processed event.'
            );
        }

        return true;
    }
}

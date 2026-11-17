<?php

namespace App\Services;

use App\Libraries\PaymentWebhookException;

final class PaymentWebhookAuthenticator
{
    public function __construct(
        private readonly string $secret,
        private readonly int $timestampToleranceSeconds = 300
    ) {
    }

    public function verify(
        string $rawBody,
        string $eventId,
        string $timestamp,
        string $signature,
        ?int $now = null
    ): void {
        if ($this->secret === '' || strlen($this->secret) < 32) {
            throw new PaymentWebhookException(503, 'webhook_not_configured', 'Payment webhook authentication is unavailable.');
        }

        if ($this->timestampToleranceSeconds < 30 || $this->timestampToleranceSeconds > 900
            || preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $eventId) !== 1
            || strlen($rawBody) > 8192
            || preg_match('/\Av1=([a-f0-9]{64})\z/i', $signature, $matches) !== 1) {
            throw new PaymentWebhookException(401, 'invalid_webhook_authentication', 'Payment webhook authentication failed.');
        }

        $sentAt = (int) $timestamp;
        if (abs(($now ?? time()) - $sentAt) > $this->timestampToleranceSeconds) {
            throw new PaymentWebhookException(401, 'expired_webhook_timestamp', 'Payment webhook authentication failed.');
        }

        $message = $timestamp . "\n" . $eventId . "\n" . $rawBody;
        $expected = hash_hmac('sha256', $message, $this->secret);
        if (!hash_equals($expected, strtolower($matches[1]))) {
            throw new PaymentWebhookException(401, 'invalid_webhook_authentication', 'Payment webhook authentication failed.');
        }
    }
}

<?php

namespace App\Services;

use App\Libraries\PaymentWebhookException;
use Config\PaymentGateway;

final class PaymentWebhookService
{
    private PaymentGateway $settings;
    private ?PaymentService $payments;

    public function __construct(
        ?PaymentGateway $settings = null,
        ?PaymentService $payments = null
    ) {
        $this->settings = $settings ?? config('PaymentGateway');
        $this->payments = $payments;
    }

    public function receive(
        string $rawBody,
        string $eventId,
        string $timestamp,
        string $signature
    ): array {
        (new PaymentWebhookAuthenticator(
            $this->settings->webhookSecret,
            $this->settings->webhookTimestampToleranceSeconds
        ))->verify($rawBody, $eventId, $timestamp, $signature);

        $event = PaymentWebhookEvent::fromJson($rawBody, $eventId);

        return ($this->payments ?? new PaymentService())->applyWebhook($event, hash('sha256', $rawBody));
    }
}

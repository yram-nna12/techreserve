<?php

use App\Libraries\PaymentWebhookException;
use App\Services\PaymentWebhookEvent;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookEventTest extends TestCase
{
    private const EVENT_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testParsesVersionedPaymentStatusEvent(): void
    {
        $event = PaymentWebhookEvent::fromJson(
            json_encode($this->event(), JSON_THROW_ON_ERROR),
            self::EVENT_ID
        );

        $this->assertSame('payment.status.changed', $event['event_type']);
        $this->assertSame('paid', $event['status']);
        $this->assertSame('RES-42', $event['reservation_reference']);
    }

    public function testRejectsMismatchedEventIdUnsupportedStatusAndInvalidAmount(): void
    {
        foreach ([
            [$this->event(), '550e8400-e29b-41d4-a716-446655440001'],
            [array_replace($this->event(), ['status' => 'cancelled']), self::EVENT_ID],
            [array_replace($this->event(), ['amount' => '12.001']), self::EVENT_ID],
        ] as [$event, $headerEventId]) {
            try {
                PaymentWebhookEvent::fromJson(json_encode($event, JSON_THROW_ON_ERROR), $headerEventId);
                $this->fail('An invalid payment event was accepted.');
            } catch (PaymentWebhookException $exception) {
                $this->assertContains($exception->httpStatus, [400, 422]);
            }
        }
    }

    private function event(): array
    {
        return [
            'event_id' => self::EVENT_ID,
            'event_type' => 'payment.status.changed',
            'event_version' => 1,
            'occurred_at' => '2026-11-17 14:30:00',
            'transaction_reference' => 'TRX-0123456789abcdef0123456789abcdef',
            'reservation_reference' => 'RES-42',
            'gateway_transaction_id' => 'gw_0123456789abcdef0123456789abcdef',
            'amount' => '1500.00',
            'currency' => 'PHP',
            'payment_method' => 'gcash',
            'status' => 'paid',
            'paid_at' => '2026-11-17 14:29:00',
            'expires_at' => '2026-11-17 15:00:00',
        ];
    }
}

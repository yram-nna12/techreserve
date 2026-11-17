<?php

use App\Libraries\PaymentWebhookException;
use App\Services\PaymentWebhookAuthenticator;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookAuthenticatorTest extends TestCase
{
    private const SECRET = 'unit-test-webhook-signing-secret-0123456789';
    private const EVENT_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testAcceptsMatchingHmacAndFreshTimestamp(): void
    {
        $body = '{"event_id":"' . self::EVENT_ID . '"}';
        $timestamp = (string) time();
        $signature = $this->signature($timestamp, self::EVENT_ID, $body);

        (new PaymentWebhookAuthenticator(self::SECRET))->verify(
            $body,
            self::EVENT_ID,
            $timestamp,
            $signature
        );

        $this->assertTrue(true);
    }

    public function testRejectsInvalidSignatureAndExpiredTimestamp(): void
    {
        $body = '{"event_id":"' . self::EVENT_ID . '"}';
        $authenticator = new PaymentWebhookAuthenticator(self::SECRET);
        $timestamp = (string) time();

        try {
            $authenticator->verify($body, self::EVENT_ID, $timestamp, 'v1=' . str_repeat('0', 64));
            $this->fail('Invalid webhook signature was accepted.');
        } catch (PaymentWebhookException $exception) {
            $this->assertSame(401, $exception->httpStatus);
        }

        $timestamp = (string) (time() - 600);
        try {
            $authenticator->verify(
                $body,
                self::EVENT_ID,
                $timestamp,
                $this->signature($timestamp, self::EVENT_ID, $body)
            );
            $this->fail('Expired webhook timestamp was accepted.');
        } catch (PaymentWebhookException $exception) {
            $this->assertSame('expired_webhook_timestamp', $exception->errorCode);
        }
    }

    public function testRejectsUnconfiguredSigningSecret(): void
    {
        try {
            (new PaymentWebhookAuthenticator(''))->verify(
                '{}',
                self::EVENT_ID,
                (string) time(),
                'v1=' . str_repeat('0', 64)
            );
            $this->fail('An unconfigured webhook secret was accepted.');
        } catch (PaymentWebhookException $exception) {
            $this->assertSame(503, $exception->httpStatus);
        }
    }

    private function signature(string $timestamp, string $eventId, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . "\n" . $eventId . "\n" . $body, self::SECRET);
    }
}

<?php

use App\Libraries\PaymentWebhookException;
use App\Services\PaymentWebhookReceiptPolicy;
use PHPUnit\Framework\TestCase;

final class PaymentWebhookReceiptPolicyTest extends TestCase
{
    public function testOnlyExactReceiptReplayIsAcceptedAsDuplicate(): void
    {
        $receipt = ['payment_id' => 42, 'payload_hash' => str_repeat('a', 64)];

        $this->assertFalse(PaymentWebhookReceiptPolicy::isDuplicate(null, 42, str_repeat('a', 64)));
        $this->assertTrue(PaymentWebhookReceiptPolicy::isDuplicate($receipt, 42, str_repeat('a', 64)));

        foreach ([
            [43, str_repeat('a', 64)],
            [42, str_repeat('b', 64)],
        ] as [$paymentId, $payloadHash]) {
            try {
                PaymentWebhookReceiptPolicy::isDuplicate($receipt, $paymentId, $payloadHash);
                $this->fail('A conflicting webhook receipt was accepted.');
            } catch (PaymentWebhookException $exception) {
                $this->assertSame(409, $exception->httpStatus);
            }
        }
    }
}

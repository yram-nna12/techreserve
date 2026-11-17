<?php

use App\Services\PaymentGatewayResponse;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayResponseTest extends TestCase
{
    private function expected(): array
    {
        return [
            'transaction_reference' => 'TRX-0123456789abcdef0123456789abcdef',
            'reservation_reference' => 'RES-42',
            'amount' => '1500.00',
            'currency' => 'PHP',
            'payment_method' => 'gcash',
        ];
    }

    public function testAcceptsMatchingPendingInitiationAndStatusResponses(): void
    {
        $initiation = $this->expected() + [
            'gateway_transaction_id' => 'gw_0123456789abcdef0123456789abcdef',
            'status' => 'pending',
            'checkout_url' => 'http://127.0.0.1:8082/checkout/TRX-0123456789abcdef0123456789abcdef/token',
            'expires_at' => '2026-11-17 16:00:00',
        ];
        $this->assertSame(
            'pending',
            PaymentGatewayResponse::initiation(
                json_encode(['data' => $initiation], JSON_THROW_ON_ERROR),
                $this->expected(),
                'http://127.0.0.1:8082'
            )['status']
        );

        $status = $this->expected() + [
            'gateway_transaction_id' => 'gw_0123456789abcdef0123456789abcdef',
            'status' => 'paid',
            'paid_at' => '2026-11-17 15:45:00',
            'expires_at' => '2026-11-17 16:00:00',
        ];
        $this->assertSame('paid', PaymentGatewayResponse::status(
            json_encode(['data' => $status], JSON_THROW_ON_ERROR),
            $this->expected()
        )['status']);

        foreach (['pending', 'failed', 'expired'] as $outcome) {
            $status['status'] = $outcome;
            $status['paid_at'] = null;
            $this->assertSame($outcome, PaymentGatewayResponse::status(
                json_encode(['data' => $status], JSON_THROW_ON_ERROR),
                $this->expected()
            )['status']);
        }
    }

    public function testRejectsMismatchedAmountAndUntrustedCheckoutHost(): void
    {
        $data = $this->expected() + [
            'gateway_transaction_id' => 'gw_0123456789abcdef0123456789abcdef',
            'status' => 'pending',
            'checkout_url' => 'http://127.0.0.1:8082/checkout/TRX-0123456789abcdef0123456789abcdef/token',
            'expires_at' => '2026-11-17 16:00:00',
        ];
        $mismatchedAmount = array_replace($data, ['amount' => '1.00']);
        $untrustedHost = array_replace($data, ['checkout_url' => 'http://attacker.invalid/checkout/ref/token']);

        foreach ([$mismatchedAmount, $untrustedHost] as $response) {
            try {
                PaymentGatewayResponse::initiation(
                    json_encode(['data' => $response], JSON_THROW_ON_ERROR),
                    $this->expected(),
                    'http://127.0.0.1:8082'
                );
                $this->fail('Invalid gateway response was accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testRejectsPaidTimestampsThatConflictWithGatewayStatus(): void
    {
        $status = $this->expected() + [
            'gateway_transaction_id' => 'gw_0123456789abcdef0123456789abcdef',
            'status' => 'failed',
            'paid_at' => '2026-11-17 15:45:00',
            'expires_at' => '2026-11-17 16:00:00',
        ];

        try {
            PaymentGatewayResponse::status(
                json_encode(['data' => $status], JSON_THROW_ON_ERROR),
                $this->expected()
            );
            $this->fail('A failed payment with a paid timestamp was accepted.');
        } catch (UnexpectedValueException) {
            $this->assertTrue(true);
        }
    }
}

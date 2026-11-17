<?php

use App\Services\PaymentRules;
use PHPUnit\Framework\TestCase;

final class PaymentRulesTest extends TestCase
{
    public function testOnlyPositivePhpTotalsOnApprovedReservationsCanStart(): void
    {
        PaymentRules::assertCanInitiate('approved', '120.00', 'PHP', 'accepted');

        foreach ([
            ['pending', '120.00', 'PHP', 'accepted'],
            ['rejected', '120.00', 'PHP', 'accepted'],
            ['cancelled', '120.00', 'PHP', 'accepted'],
            ['completed', '120.00', 'PHP', 'accepted'],
            ['approved', '0.00', 'PHP', 'accepted'],
            ['approved', '120.00', 'USD', 'accepted'],
            ['approved', '120.00', 'PHP', null],
            ['approved', '120.00', 'PHP', 'pending'],
            ['approved', '120.00', 'PHP', 'rejected'],
        ] as [$status, $amount, $currency, $formStatus]) {
            try {
                PaymentRules::assertCanInitiate($status, $amount, $currency, $formStatus);
                $this->fail('An ineligible reservation payment was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testPendingAndBorrowerPaidAttemptsBlockCancellation(): void
    {
        foreach ([
            [['payment_status' => 'pending']],
            [['payment_status' => 'paid']],
        ] as $payments) {
            try {
                PaymentRules::assertCanCancel($payments, true);
                $this->fail('Borrower cancellation was allowed with an unresolved or paid attempt.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        PaymentRules::assertCanCancel([['payment_status' => 'failed']], true);
        PaymentRules::assertCanCancel([['payment_status' => 'expired']], true);
        PaymentRules::assertCanCancel([['payment_status' => 'paid']], false);
    }

    public function testGatewayCannotRegressOrChangeTerminalPaymentState(): void
    {
        foreach (['paid', 'failed', 'expired'] as $terminal) {
            $this->assertSame($terminal, PaymentRules::nextStatus('pending', $terminal));
        }
        $this->assertSame('failed', PaymentRules::nextStatus('failed', 'failed'));

        foreach ([['paid', 'pending'], ['failed', 'paid'], ['pending', 'refunded']] as [$current, $gateway]) {
            try {
                PaymentRules::nextStatus($current, $gateway);
                $this->fail('Invalid payment state transition was accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }
}

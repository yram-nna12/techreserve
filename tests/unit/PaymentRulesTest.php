<?php

use App\Services\PaymentRules;
use PHPUnit\Framework\TestCase;

final class PaymentRulesTest extends TestCase
{
    public function testOnlyPositivePhpTotalsOnApprovedReservationsCanStart(): void
    {
        PaymentRules::assertCanInitiate('approved', '120.00', 'PHP', 'accepted', '2099-01-01 00:00:00');

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
                PaymentRules::assertCanInitiate($status, $amount, $currency, $formStatus, '2099-01-01 00:00:00');
                $this->fail('An ineligible reservation payment was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testPaymentDeadlineIsExclusiveAndUsesUtcInstants(): void
    {
        $start = '2026-11-26 08:00:00';
        $this->assertFalse(PaymentRules::paymentPeriodEnded($start, new DateTimeImmutable('2026-11-26 15:59:59', new DateTimeZone('Asia/Manila'))));
        $this->assertTrue(PaymentRules::paymentPeriodEnded($start, new DateTimeImmutable('2026-11-26 16:00:00', new DateTimeZone('Asia/Manila'))));
        $this->assertTrue(PaymentRules::paymentPeriodEnded($start, new DateTimeImmutable('2026-11-27 02:00:00', new DateTimeZone('Asia/Manila'))));
    }

    public function testPastOrMissingStartCannotInitiate(): void
    {
        foreach (['2000-01-01 00:00:00', null, 'not-a-date'] as $start) {
            try {
                PaymentRules::assertCanInitiate('approved', '120.00', 'PHP', 'accepted', $start);
                $this->fail('Payment was allowed without a future start.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDelayedDeliveryUsesConfirmationTime(): void
    {
        $this->assertTrue(PaymentRules::paymentConfirmedBeforeDeadline('2026-11-26 07:59:59', '2026-11-26 08:00:00'));
        $this->assertFalse(PaymentRules::paymentConfirmedBeforeDeadline('2026-11-26 08:00:00', '2026-11-26 08:00:00'));
        $this->assertFalse(PaymentRules::paymentConfirmedBeforeDeadline('invalid', '2026-11-26 08:00:00'));
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
        foreach (['paid', 'failed', 'cancelled', 'expired'] as $terminal) {
            $this->assertSame($terminal, PaymentRules::nextStatus('pending', $terminal));
        }
        $this->assertSame('failed', PaymentRules::nextStatus('failed', 'failed'));

        foreach ([['paid', 'pending'], ['paid', 'paid-again'], ['failed', 'paid'], ['cancelled', 'paid'], ['pending', 'refunded']] as [$current, $gateway]) {
            try {
                PaymentRules::nextStatus($current, $gateway);
                $this->fail('Invalid payment state transition was accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }
}

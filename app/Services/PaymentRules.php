<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use UnexpectedValueException;

final class PaymentRules
{
    public static function paymentPeriodEnded(string $startUtc, ?DateTimeInterface $now = null): bool
    {
        $utc = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startUtc, $utc);
        if (!$start || $start->format('Y-m-d H:i:s') !== $startUtc) {
            throw new DomainException('The reservation start time is invalid.');
        }
        return ($now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now', $utc)) >= $start;
    }

    public static function canInitiate(string $status, string $amount, string $currency, ?string $formStatus, ?string $startUtc): bool
    {
        try {
            self::assertCanInitiate($status, $amount, $currency, $formStatus, $startUtc);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public static function paymentConfirmedBeforeDeadline(string $paidUtc, string $startUtc): bool
    {
        try {
            $paid = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $paidUtc, new DateTimeZone('UTC'));
            return $paid !== false && $paid->format('Y-m-d H:i:s') === $paidUtc && !self::paymentPeriodEnded($startUtc, $paid);
        } catch (DomainException) {
            return false;
        }
    }

    public static function assertCanInitiate(
        string $reservationStatus,
        string $amount,
        string $currency,
        ?string $formStatus,
        ?string $startUtc = null
    ): void
    {
        if ($startUtc === null || self::paymentPeriodEnded($startUtc)) {
            throw new DomainException('The payment period has ended for this reservation.');
        }
        if ($reservationStatus !== 'approved') {
            throw new DomainException('Payment is available only for approved reservations.');
        }
        if ($formStatus !== 'accepted') {
            throw new DomainException('Payment is available only after the latest signed request form is accepted.');
        }
        if ($currency !== 'PHP' || !preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $amount)
            || $amount === '0.00') {
            throw new DomainException('This reservation total cannot be processed by the payment gateway.');
        }
    }

    public static function assertCanCancel(array $payments, bool $borrower): void
    {
        foreach ($payments as $payment) {
            if ($payment['payment_status'] === 'pending') {
                throw new DomainException('Resolve the pending payment with the gateway before cancelling this reservation.');
            }
            if ($borrower && $payment['payment_status'] === 'paid') {
                throw new DomainException('Paid reservations cannot be cancelled by borrowers because refunds are not available.');
            }
        }
    }

    public static function nextStatus(string $current, string $gateway): string
    {
        if (!in_array($gateway, ['pending', 'paid', 'failed', 'cancelled', 'expired'], true)) {
            throw new UnexpectedValueException('Unsupported payment status from gateway.');
        }
        if ($current === 'pending') {
            return $gateway;
        }
        if ($current === $gateway && in_array($current, ['paid', 'failed', 'cancelled', 'expired'], true)) {
            return $current;
        }

        throw new UnexpectedValueException('A terminal payment status cannot be changed.');
    }
}

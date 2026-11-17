<?php

namespace App\Services;

use DomainException;
use UnexpectedValueException;

final class PaymentRules
{
    public static function assertCanInitiate(
        string $reservationStatus,
        string $amount,
        string $currency,
        ?string $formStatus
    ): void
    {
        if ($reservationStatus !== 'approved') {
            throw new DomainException('Payment is available only for approved reservations.');
        }
        if ($formStatus !== 'accepted') {
            throw new DomainException('Payment is available only after the latest signed request form is accepted.');
        }
        if ($currency !== 'PHP' || !preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $amount)
            || $amount === '0.00') {
            throw new DomainException('This reservation total cannot be processed by the mock gateway.');
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

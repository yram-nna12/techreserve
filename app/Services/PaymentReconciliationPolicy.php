<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class PaymentReconciliationPolicy
{
    public static function isStale(array $payment, DateTimeImmutable $cutoff): bool
    {
        $timestamps = [];
        foreach (['last_verified_at', 'updated_at', 'created_at'] as $field) {
            $timestamp = self::parseTimestamp($payment[$field] ?? null);
            if ($timestamp !== null) {
                $timestamps[] = $timestamp;
            }
        }
        if ($timestamps === []) {
            return false;
        }

        usort($timestamps, static fn(DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);

        return $timestamps[count($timestamps) - 1] <= $cutoff;
    }

    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $timestamp;
    }
}

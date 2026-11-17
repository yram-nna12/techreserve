<?php

namespace App\Services;

use App\Libraries\PaymentWebhookException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class PaymentWebhookEvent
{
    private const FIELDS = [
        'event_id',
        'event_type',
        'event_version',
        'occurred_at',
        'transaction_reference',
        'reservation_reference',
        'gateway_transaction_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'paid_at',
        'expires_at',
    ];

    public static function fromJson(string $rawBody, string $headerEventId): array
    {
        try {
            $event = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PaymentWebhookException(400, 'invalid_webhook_json', 'The payment event is invalid.');
        }

        if (!is_array($event)
            || array_diff(array_keys($event), self::FIELDS) !== []
            || array_diff(self::FIELDS, array_keys($event)) !== []) {
            throw new PaymentWebhookException(400, 'invalid_webhook_event', 'The payment event is invalid.');
        }

        if (!is_string($event['event_id'])
            || !hash_equals(strtolower($headerEventId), strtolower($event['event_id']))
            || $event['event_type'] !== 'payment.status.changed'
            || $event['event_version'] !== 1
            || !self::isDate($event['occurred_at'])
            || !is_string($event['transaction_reference'])
            || preg_match('/\ATRX-[a-f0-9]{32}\z/i', $event['transaction_reference']) !== 1
            || !is_string($event['reservation_reference'])
            || preg_match('/\ARES-[1-9][0-9]*\z/', $event['reservation_reference']) !== 1
            || !is_string($event['gateway_transaction_id'])
            || preg_match('/\Agw_[A-Za-z0-9._:-]{1,60}\z/', $event['gateway_transaction_id']) !== 1
            || !is_string($event['amount'])
            || preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', $event['amount']) !== 1
            || $event['amount'] === '0.00'
            || $event['currency'] !== 'PHP'
            || !in_array($event['payment_method'], ['gcash', 'bank_transfer'], true)
            || !in_array($event['status'], ['paid', 'failed', 'expired'], true)
            || !self::isDate($event['expires_at'])
            || !array_key_exists('paid_at', $event)
            || ($event['status'] === 'paid' && !self::isDate($event['paid_at']))
            || ($event['status'] !== 'paid' && $event['paid_at'] !== null)) {
            throw new PaymentWebhookException(422, 'invalid_webhook_event', 'The payment event is invalid.');
        }

        return $event;
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d H:i:s') === $value;
    }
}

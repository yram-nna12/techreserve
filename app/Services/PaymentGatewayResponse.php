<?php

namespace App\Services;

use UnexpectedValueException;

final class PaymentGatewayResponse
{
    private const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'expired'];

    public static function hasValidSignature(string $body, string $nonce, string $signature, string $secret): bool
    {
        if ($secret === '' || !preg_match('/\A[a-f0-9]{64}\z/', $nonce)) {
            return false;
        }
        return hash_equals('v1=' . hash_hmac('sha256', "techreserve-response-v1\n" . $nonce . "\n" . $body, $secret), $signature);
    }

    public static function initiation(string $body, array $expected, string $baseUrl): array
    {
        $data = self::data($body);
        self::assertCommon($data, $expected);

        if (($data['reservation_reference'] ?? null) !== ($expected['reservation_reference'] ?? null)
            || !is_string($data['checkout_url'] ?? null) || !self::isCheckoutUrl($data['checkout_url'], $baseUrl)) {
            throw new UnexpectedValueException('The payment gateway returned an invalid checkout URL.');
        }
        self::assertDate($data['expires_at'] ?? null);

        return $data;
    }

    public static function status(string $body, array $expected): array
    {
        $data = self::data($body);
        self::assertCommon($data, $expected);

        if (!array_key_exists('paid_at', $data)
            || ($data['paid_at'] !== null && !self::isDate($data['paid_at']))
            || ($data['status'] === 'paid' && $data['paid_at'] === null)
            || ($data['status'] !== 'paid' && $data['paid_at'] !== null)) {
            throw new UnexpectedValueException('The payment gateway returned an invalid paid timestamp.');
        }
        self::assertDate($data['expires_at'] ?? null);

        return $data;
    }

    private static function data(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new UnexpectedValueException('The payment gateway returned invalid JSON.', 0, $exception);
        }

        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new UnexpectedValueException('The payment gateway response has an invalid shape.');
        }

        return $decoded['data'];
    }

    private static function assertCommon(array $data, array $expected): void
    {
        foreach (['transaction_reference', 'gateway_transaction_id', 'amount', 'currency', 'payment_method', 'status'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new UnexpectedValueException('The payment gateway response is missing required payment data.');
            }
        }

        if ($data['transaction_reference'] !== $expected['transaction_reference']
            || $data['amount'] !== $expected['amount']
            || $data['currency'] !== $expected['currency']
            || $data['payment_method'] !== $expected['payment_method']
            || !preg_match('/\Agw_[A-Za-z0-9._:-]{1,60}\z/', $data['gateway_transaction_id'])
            || !in_array($data['status'], self::STATUSES, true)) {
            throw new UnexpectedValueException('The payment gateway response did not match the stored payment attempt.');
        }
    }

    private static function isCheckoutUrl(string $url, string $baseUrl): bool
    {
        $checkout = parse_url($url);
        $base = parse_url($baseUrl);
        if (!is_array($checkout) || !is_array($base)
            || !in_array($checkout['scheme'] ?? '', ['http', 'https'], true)
            || strtolower((string) ($checkout['scheme'] ?? '')) !== strtolower((string) ($base['scheme'] ?? ''))
            || strtolower((string) ($checkout['host'] ?? '')) !== strtolower((string) ($base['host'] ?? ''))
            || (int) ($checkout['port'] ?? 0) !== (int) ($base['port'] ?? 0)
            || isset($checkout['user']) || isset($checkout['pass']) || isset($checkout['fragment'])) {
            return false;
        }

        $basePath = rtrim((string) ($base['path'] ?? ''), '/');
        return str_starts_with((string) ($checkout['path'] ?? ''), $basePath . '/checkout/');
    }

    private static function assertDate(mixed $value): void
    {
        if (!self::isDate($value)) {
            throw new UnexpectedValueException('The payment gateway returned an invalid expiry timestamp.');
        }
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value) === 1
            && strtotime($value) !== false;
    }
}

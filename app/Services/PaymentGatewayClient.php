<?php

namespace App\Services;

use App\Libraries\PaymentGatewayException;
use Throwable;

final class PaymentGatewayClient implements PaymentStatusProviderInterface
{
    public function initiate(array $payload): array
    {
        $config = config('PaymentGateway');
        $response = $this->request('POST', '/api/v1/payments', $payload);
        try {
            return PaymentGatewayResponse::initiation($response, $payload, $config->baseUrl);
        } catch (Throwable $exception) {
            log_message('warning', 'Payment gateway initiation response rejected: {reason}', ['reason' => $exception->getMessage()]);
            throw new PaymentGatewayException(
                'The payment gateway response could not be verified. The attempt remains unresolved.',
                false,
                'invalid_response',
                $exception
            );
        }
    }

    public function status(array $expected): array
    {
        $response = $this->request(
            'GET',
            '/api/v1/payments/' . rawurlencode($expected['transaction_reference'])
        );
        try {
            return PaymentGatewayResponse::status($response, $expected);
        } catch (Throwable $exception) {
            log_message('warning', 'Payment gateway status response rejected: {reason}', ['reason' => $exception->getMessage()]);
            throw new PaymentGatewayException(
                'The payment gateway status response could not be verified.',
                false,
                'invalid_response',
                $exception
            );
        }
    }

    private function request(string $method, string $path, ?array $payload = null): string
    {
        $config = config('PaymentGateway');
        if ($config->apiKey === '' || $config->webhookSecret === '' || !in_array(parse_url($config->baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($config->baseUrl, PHP_URL_HOST) === null) {
            throw new PaymentGatewayException('The payment gateway is not configured.', false, 'not_configured');
        }

        $client = single_service('curlrequest', [
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $nonce = bin2hex(random_bytes(32));
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $config->apiKey,
                'X-Payment-Response-Nonce' => $nonce,
            ],
        ];
        if ($payload !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $result = $client->request($method, $config->baseUrl . $path, $options);
        } catch (Throwable $exception) {
            log_message('warning', 'Payment gateway request failed ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            throw new PaymentGatewayException(
                'The payment gateway could not be reached. The payment remains pending.',
                true,
                'network_error',
                $exception
            );
        }

        $statusCode = $result->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $retryable = in_array($statusCode, [408, 429], true) || $statusCode >= 500;
            throw new PaymentGatewayException(
                'The payment gateway could not confirm the request. The payment remains pending.',
                $retryable,
                'http_' . $statusCode
            );
        }

        $body = $result->getBody();
        if (!PaymentGatewayResponse::hasValidSignature($body, $nonce, $result->getHeaderLine('X-Payment-Response-Signature'), $config->webhookSecret)) {
            log_message('warning', 'Payment gateway response signature was missing or invalid.');
            throw new PaymentGatewayException('The payment gateway response could not be authenticated.', false, 'invalid_signature');
        }
        return $body;
    }
}

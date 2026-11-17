<?php

namespace App\Services;

use App\Libraries\PaymentGatewayException;
use Throwable;

final class PaymentGatewayClient
{
    public function initiate(array $payload): array
    {
        $config = config('PaymentGateway');
        $response = $this->request('POST', '/api/v1/payments', $payload);
        try {
            return PaymentGatewayResponse::initiation($response, $payload, $config->baseUrl);
        } catch (Throwable $exception) {
            log_message('warning', 'Payment gateway initiation response could not be verified ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            throw new PaymentGatewayException('The payment gateway response could not be verified. The attempt remains unresolved.', 0, $exception);
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
            log_message('warning', 'Payment gateway status response could not be verified ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            throw new PaymentGatewayException('The payment gateway status response could not be verified.', 0, $exception);
        }
    }

    private function request(string $method, string $path, ?array $payload = null): string
    {
        $config = config('PaymentGateway');
        if ($config->apiKey === '' || !in_array(parse_url($config->baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($config->baseUrl, PHP_URL_HOST) === null) {
            throw new PaymentGatewayException('The payment gateway is not configured.');
        }

        $client = single_service('curlrequest', [
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $config->apiKey,
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
            throw new PaymentGatewayException('The payment gateway could not be reached. The payment remains pending.', 0, $exception);
        }

        if ($result->getStatusCode() < 200 || $result->getStatusCode() >= 300) {
            throw new PaymentGatewayException('The payment gateway could not confirm the request. The payment remains pending.');
        }

        return $result->getBody();
    }
}

<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class PaymentGateway extends BaseConfig
{
    public string $baseUrl = 'http://127.0.0.1:8082';
    public string $apiKey = '';
    public string $webhookSecret = '';
    public int $webhookTimestampToleranceSeconds = 300;
    public float $timeout = 5.0;
    public float $connectTimeout = 2.0;
    public int $reconciliationStaleMinutes = 10;
    public int $reconciliationBatchLimit = 25;
    public int $reconciliationMaxAttempts = 2;
    public int $reconciliationRetryDelayMilliseconds = 250;

    public function __construct()
    {
        parent::__construct();
        $this->baseUrl = rtrim((string) env('paymentGateway.baseUrl', $this->baseUrl), '/');
        $this->apiKey = (string) env('paymentGateway.apiKey', '');
        $this->webhookSecret = (string) env('paymentGateway.webhookSecret', '');
        $tolerance = filter_var(
            env('paymentGateway.webhookTimestampToleranceSeconds', 300),
            FILTER_VALIDATE_INT
        );
        if ($tolerance === false || $tolerance < 30 || $tolerance > 900) {
            throw new \InvalidArgumentException(
                'paymentGateway.webhookTimestampToleranceSeconds must be between 30 and 900.'
            );
        }
        $this->webhookTimestampToleranceSeconds = $tolerance;
        $this->reconciliationStaleMinutes = $this->integerSetting(
            'paymentGateway.reconciliationStaleMinutes',
            $this->reconciliationStaleMinutes,
            1,
            1440
        );
        $this->reconciliationBatchLimit = $this->integerSetting(
            'paymentGateway.reconciliationBatchLimit',
            $this->reconciliationBatchLimit,
            1,
            100
        );
        $this->reconciliationMaxAttempts = $this->integerSetting(
            'paymentGateway.reconciliationMaxAttempts',
            $this->reconciliationMaxAttempts,
            1,
            3
        );
        $this->reconciliationRetryDelayMilliseconds = $this->integerSetting(
            'paymentGateway.reconciliationRetryDelayMilliseconds',
            $this->reconciliationRetryDelayMilliseconds,
            0,
            2000
        );
    }

    private function integerSetting(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var(env($name, $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException(
                $name . ' must be an integer between ' . $minimum . ' and ' . $maximum . '.'
            );
        }

        return $value;
    }
}

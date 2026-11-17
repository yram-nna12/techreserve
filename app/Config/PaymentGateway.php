<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class PaymentGateway extends BaseConfig
{
    public string $baseUrl = 'http://127.0.0.1:8082';
    public string $apiKey = '';
    public float $timeout = 5.0;
    public float $connectTimeout = 2.0;

    public function __construct()
    {
        parent::__construct();
        $this->baseUrl = rtrim((string) env('paymentGateway.baseUrl', $this->baseUrl), '/');
        $this->apiKey = (string) env('paymentGateway.apiKey', '');
    }
}

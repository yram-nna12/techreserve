<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Booking extends BaseConfig
{
    public int $minimumMinutes = 10;
    public int $incrementMinutes = 10;
    public int $maximumMinutes = 480;
    public int $advanceDays = 30;
    public int $maximumResources = 20;
    public int $transactionAttempts = 3;
    public string $timezone = 'Asia/Manila';
    public string $currency = 'PHP';
}

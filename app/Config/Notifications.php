<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

class Notifications extends BaseConfig
{
    public bool $enabled = true;
    public bool $emailDeliveryEnabled = false;
    public int $maxEmailAttempts = 5;
    public int $emailLeaseSeconds = 300;
    public int $retryBaseDelaySeconds = 60;
    public int $retryMaxDelaySeconds = 3600;

    public function __construct()
    {
        parent::__construct();
        $this->enabled = $this->booleanSetting('notifications.enabled', true);
        $this->emailDeliveryEnabled = $this->booleanSetting('notifications.emailDeliveryEnabled', false);
    }

    private function booleanSetting(string $name, bool $default): bool
    {
        $value = env($name, $default);
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException($name . ' must be true or false.');
        }

        return $parsed;
    }
}

<?php

namespace App\Libraries;

interface NotificationEmailTransport
{
    public function send(array $message): EmailDeliveryResult;
}

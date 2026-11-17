<?php

namespace App\Libraries;

final class CodeIgniterNotificationEmailTransport implements NotificationEmailTransport
{
    public function send(array $message): EmailDeliveryResult
    {
        $config = config('Email');
        $recipient = $message['recipient_email'] ?? '';

        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return EmailDeliveryResult::permanentFailure('invalid_recipient');
        }
        if ($config->protocol !== 'smtp' || $config->SMTPHost === ''
            || filter_var($config->fromEmail, FILTER_VALIDATE_EMAIL) === false
            || (($config->SMTPUser === '') !== ($config->SMTPPass === ''))) {
            return EmailDeliveryResult::permanentFailure('smtp_configuration_invalid');
        }

        $email = service('email');
        $email->clear(true);
        try {
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($recipient);
            $email->setSubject($message['subject']);
            $email->setMailType('html');
            $email->setMessage($message['html_body']);
            $email->setAltMessage($message['text_body']);

            if ($email->send(false)) {
                return EmailDeliveryResult::success();
            }

            $debug = strip_tags($email->printDebugger([]));
            if (preg_match('/(?<![0-9])5[0-9]{2}(?![0-9])/', $debug) === 1) {
                return EmailDeliveryResult::permanentFailure('smtp_permanent_rejection');
            }

            return EmailDeliveryResult::temporaryFailure('smtp_delivery_failed');
        } finally {
            $email->clear(true);
        }
    }
}

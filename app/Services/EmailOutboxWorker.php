<?php

namespace App\Services;

use App\Libraries\CodeIgniterNotificationEmailTransport;
use App\Libraries\EmailDeliveryResult;
use App\Libraries\EmailRetryPolicy;
use App\Libraries\NotificationEmailTransport;
use Config\Notifications;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class EmailOutboxWorker
{
    private ?EmailOutboxStoreInterface $store;
    private NotificationEmailTransport $transport;
    private EmailRetryPolicy $retryPolicy;
    private Notifications $settings;

    public function __construct(
        ?EmailOutboxStoreInterface $store = null,
        ?NotificationEmailTransport $transport = null,
        ?EmailRetryPolicy $retryPolicy = null,
        ?Notifications $settings = null
    ) {
        $this->store = $store;
        $this->transport = $transport ?? new CodeIgniterNotificationEmailTransport();
        $this->retryPolicy = $retryPolicy ?? new EmailRetryPolicy();
        $this->settings = $settings ?? config('Notifications');
    }

    public function run(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Email worker limit must be between 1 and 100.');
        }

        $report = ['processed' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'disabled' => false];
        if (!$this->settings->enabled || !$this->settings->emailDeliveryEnabled) {
            $report['disabled'] = true;
            return $report;
        }

        while ($report['processed'] < $limit) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $nowText = $now->format('Y-m-d H:i:s');
            $message = $this->store()->claimNext(
                $this->settings->maxEmailAttempts,
                $this->settings->emailLeaseSeconds,
                $nowText
            );
            if ($message === null) {
                break;
            }

            $report['processed']++;
            try {
                $result = $this->transport->send($message);
            } catch (Throwable $exception) {
                log_message('error', 'Notification email transport threw ({exceptionClass}).', [
                    'exceptionClass' => $exception::class,
                ]);
                $result = EmailDeliveryResult::temporaryFailure('transport_exception');
            }

            if ($result->successful) {
                $this->store()->markSent((int) $message['id'], $nowText);
                $report['sent']++;
                continue;
            }

            $decision = $this->retryPolicy->failure(
                (int) $message['attempt_count'],
                $result->permanent,
                $this->settings->maxEmailAttempts,
                $now,
                $this->settings->retryBaseDelaySeconds,
                $this->settings->retryMaxDelaySeconds
            );
            $errorCode = preg_match('/\A[a-z0-9_-]{1,64}\z/', $result->errorCode) === 1
                ? $result->errorCode : 'delivery_failed';
            $this->store()->markFailure(
                (int) $message['id'],
                $decision['status'],
                $decision['available_at'],
                $errorCode,
                $nowText
            );

            if ($decision['status'] === 'failed') {
                $report['failed']++;
            } else {
                $report['retrying']++;
            }
        }

        return $report;
    }

    private function store(): EmailOutboxStoreInterface
    {
        return $this->store ??= new DatabaseNotificationStore();
    }
}

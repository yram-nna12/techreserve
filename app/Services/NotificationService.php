<?php

namespace App\Services;

use App\Libraries\NotificationEmailRenderer;
use App\Libraries\NotificationEventFactory;
use Config\Notifications;
use DomainException;
use Throwable;

final class NotificationService
{
    private NotificationStoreInterface $store;
    private NotificationEventFactory $events;
    private NotificationEmailRenderer $renderer;
    private Notifications $settings;

    public function __construct(
        ?NotificationStoreInterface $store = null,
        ?NotificationEventFactory $events = null,
        ?NotificationEmailRenderer $renderer = null,
        ?Notifications $settings = null
    ) {
        $this->store = $store ?? new DatabaseNotificationStore();
        $this->events = $events ?? new NotificationEventFactory();
        $this->renderer = $renderer ?? new NotificationEmailRenderer();
        $this->settings = $settings ?? config('Notifications');
    }

    public function reservationSubmitted(int $reservationId, array $context): void
    {
        $this->safelyPublish(fn(): array => $this->events->reservationSubmitted($reservationId, $context));
    }

    public function reservationStatusChanged(int $userId, int $reservationId, string $status, array $context): void
    {
        $this->safelyPublish(fn(): array => $this->events->reservationStatusChanged($userId, $reservationId, $status, $context));
    }

    public function paymentStatusChanged(
        int $userId,
        int $reservationId,
        int $paymentId,
        string $status,
        array $context
    ): void {
        $this->safelyPublish(fn(): ?array => $this->events->paymentStatusChanged(
            $userId,
            $reservationId,
            $paymentId,
            $status,
            $context
        ));
    }

    public function roleForUser(int $userId): ?string
    {
        $recipient = $this->store->activeRecipient($userId);
        if (!$recipient || !in_array($recipient['role'] ?? null, ['borrower', 'admin'], true)) {
            return null;
        }

        return $recipient['role'];
    }

    public function unreadCount(int $userId): int
    {
        return $this->store->unreadCount($userId);
    }

    public function listForUser(int $userId): array
    {
        if ($this->roleForUser($userId) === null) {
            throw new DomainException('Notifications are unavailable for this account.');
        }

        return $this->store->listForUser($userId, 50);
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        $this->assertWritesEnabled();
        if ($this->roleForUser($userId) === null) {
            throw new DomainException('Notifications are unavailable for this account.');
        }

        return $this->store->markRead($userId, $notificationId, gmdate('Y-m-d H:i:s'));
    }

    public function markAllRead(int $userId): int
    {
        $this->assertWritesEnabled();
        if ($this->roleForUser($userId) === null) {
            throw new DomainException('Notifications are unavailable for this account.');
        }

        return $this->store->markAllRead($userId, gmdate('Y-m-d H:i:s'));
    }

    private function safelyPublish(callable $buildEvent): void
    {
        if (!$this->settings->enabled) {
            return;
        }

        try {
            $event = $buildEvent();
            if ($event !== null) {
                $this->publish($event);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Notification dispatch failed after the business event committed ({exceptionClass}).', [
                'exceptionClass' => $exception::class,
            ]);
        }
    }

    private function publish(array $event): void
    {
        if ($event['recipient_role'] === 'admin') {
            $recipients = $this->store->activeAdministrators();
        } else {
            $recipient = $this->store->activeRecipient((int) $event['recipient_user_id']);
            $recipients = $recipient === null ? [] : [$recipient];
        }

        if ($recipients === []) {
            log_message('warning', 'Notification has no active authorized recipient.');
            return;
        }

        foreach ($recipients as $recipient) {
            if (($recipient['status'] ?? null) !== 'active'
                || ($recipient['role'] ?? null) !== $event['recipient_role']) {
                log_message('warning', 'Notification recipient role or account status did not match the event.');
                continue;
            }

            $now = gmdate('Y-m-d H:i:s');
            $notification = [
                'user_id' => (int) $recipient['id'],
                'reservation_id' => $event['reservation_id'],
                'title' => $event['title'],
                'message' => $event['message'],
                'notification_type' => $event['notification_type'],
                'event_key' => $event['event_key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $email = $this->emailMessage($recipient, $event);

            try {
                $this->store->createEvent($notification, $email);
            } catch (Throwable $exception) {
                log_message('error', 'Notification storage failed after the business event committed ({exceptionClass}).', [
                    'exceptionClass' => $exception::class,
                ]);
            }
        }
    }

    private function emailMessage(array $recipient, array $event): ?array
    {
        $address = $recipient['email'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            log_message('warning', 'Notification email was not queued because the active account has no valid associated email address.');
            return null;
        }

        try {
            return $this->renderer->render($event) + [
                'user_id' => (int) $recipient['id'],
                'event_key' => $event['event_key'],
                'recipient_email' => $address,
            ];
        } catch (Throwable $exception) {
            log_message('error', 'Notification email rendering failed ({exceptionClass}).', [
                'exceptionClass' => $exception::class,
            ]);
            return null;
        }
    }

    private function assertWritesEnabled(): void
    {
        if (!$this->settings->enabled) {
            throw new DomainException('Notification changes are disabled pending database approval.');
        }
    }
}

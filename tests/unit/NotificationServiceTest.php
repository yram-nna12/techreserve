<?php

use App\Libraries\NotificationEmailRenderer;
use App\Services\NotificationService;
use App\Services\NotificationStoreInterface;
use Config\Notifications;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    public function testCreationQueuesBothChannelsOnceForTheAuthorizedRecipient(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
            8 => ['id' => 8, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
        ]);
        $service = new NotificationService(
            store: $store,
            renderer: new NotificationEmailRenderer(),
            settings: $this->enabledSettings()
        );

        $service->reservationStatusChanged(4, 25, 'approved', ['amount' => '45.00', 'currency' => 'PHP']);
        $service->reservationStatusChanged(4, 25, 'approved', ['amount' => '45.00', 'currency' => 'PHP']);

        $this->assertCount(1, $store->notifications);
        $this->assertCount(1, $store->emails);
        $this->assertSame(4, $store->notifications[0]['user_id']);
        $this->assertSame('reservation:25:approved', $store->notifications[0]['event_key']);
        $this->assertSame('borrower@example.test', $store->emails[0]['recipient_email']);
    }

    public function testNewReservationNotifiesActiveAdministratorsButNotBorrowers(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
            8 => ['id' => 8, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
            9 => ['id' => 9, 'role' => 'admin', 'status' => 'inactive', 'email' => 'inactive@example.test'],
        ]);
        $service = new NotificationService(
            store: $store,
            renderer: new NotificationEmailRenderer(),
            settings: $this->enabledSettings()
        );

        $service->reservationSubmitted(25, []);

        $this->assertCount(1, $store->notifications);
        $this->assertSame(8, $store->notifications[0]['user_id']);
        $this->assertSame('admin', $store->recipients[8]['role']);
    }

    public function testReservationAndPaymentEventsAreStoredForTheirIntendedRecipients(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
            8 => ['id' => 8, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
        ]);
        $service = new NotificationService(
            store: $store,
            renderer: new NotificationEmailRenderer(),
            settings: $this->enabledSettings()
        );

        $service->reservationSubmitted(25, []);
        $service->reservationStatusChanged(4, 25, 'approved', []);
        $service->reservationStatusChanged(4, 26, 'rejected', []);
        $service->paymentStatusChanged(4, 25, 12, 'paid', []);
        $service->paymentStatusChanged(4, 26, 13, 'failed', []);
        $service->paymentStatusChanged(4, 27, 14, 'expired', []);

        $this->assertSame(
            [
                ['user_id' => 8, 'notification_type' => 'reservation_submitted'],
                ['user_id' => 4, 'notification_type' => 'reservation_approved'],
                ['user_id' => 4, 'notification_type' => 'reservation_rejected'],
                ['user_id' => 4, 'notification_type' => 'payment_paid'],
                ['user_id' => 4, 'notification_type' => 'payment_failed'],
                ['user_id' => 4, 'notification_type' => 'payment_expired'],
            ],
            array_map(
                static fn(array $notification): array => [
                    'user_id' => $notification['user_id'],
                    'notification_type' => $notification['notification_type'],
                ],
                $store->notifications
            )
        );
    }

    public function testAtomicPaymentNotificationsAreInAppOnlyAndDeduplicated(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
        ]);
        $service = new NotificationService(store: $store, settings: $this->enabledSettings());

        $service->persistPaymentStatusChanged(4, 25, 12, 'paid', ['amount' => '45.00', 'currency' => 'PHP']);
        $service->persistPaymentStatusChanged(4, 25, 12, 'paid', ['amount' => '45.00', 'currency' => 'PHP']);

        $this->assertCount(1, $store->notifications);
        $this->assertSame('payment:12:paid', $store->notifications[0]['event_key']);
        $this->assertSame('payment_paid', $store->notifications[0]['notification_type']);
        $this->assertSame([], $store->emails);
    }

    public function testAtomicPaymentNotificationStorageFailurePropagates(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
        ]);
        $store->failCreateEvent = true;
        $service = new NotificationService(store: $store, settings: $this->enabledSettings());

        $this->expectException(RuntimeException::class);
        $service->persistPaymentStatusChanged(4, 25, 12, 'paid', []);
    }

    public function testWrongRoleIsSkippedAndInvalidAddressGetsInAppOnly(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
            5 => ['id' => 5, 'role' => 'borrower', 'status' => 'active', 'email' => 'not-an-email'],
        ]);
        $service = new NotificationService(
            store: $store,
            renderer: new NotificationEmailRenderer(),
            settings: $this->enabledSettings()
        );

        $service->reservationStatusChanged(4, 25, 'approved', []);
        $service->reservationStatusChanged(5, 25, 'rejected', []);

        $this->assertCount(1, $store->notifications);
        $this->assertCount(0, $store->emails);
        $this->assertSame(5, $store->notifications[0]['user_id']);
    }

    public function testReadOperationsAreScopedToTheCurrentUser(): void
    {
        $store = new FakeNotificationStore([
            4 => ['id' => 4, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'],
            8 => ['id' => 8, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
        ]);
        $settings = $this->enabledSettings();
        $service = new NotificationService(store: $store, settings: $settings);
        $service->reservationStatusChanged(4, 25, 'approved', []);
        $service->reservationSubmitted(26, []);

        $this->assertFalse($service->markRead(8, (int) $store->notifications[0]['id']));
        $this->assertTrue($service->markRead(4, (int) $store->notifications[0]['id']));
        $this->assertSame(1, $service->unreadCount(8));
        $this->assertSame(1, $service->markAllRead(8));
        $this->assertSame(0, $service->unreadCount(8));
    }

    public function testNotificationWritesAreEnabledByDefault(): void
    {
        $store = new FakeNotificationStore([
            8 => ['id' => 8, 'role' => 'admin', 'status' => 'active', 'email' => 'admin@example.test'],
        ]);
        $settings = new Notifications();
        $service = new NotificationService(store: $store, settings: $settings);

        $service->reservationSubmitted(25, []);

        $this->assertTrue($settings->enabled);
        $this->assertCount(1, $store->notifications);
        $this->assertSame('reservation:25:submitted', $store->notifications[0]['event_key']);
    }

    private function enabledSettings(): Notifications
    {
        $settings = new Notifications();
        $settings->enabled = true;
        return $settings;
    }
}

final class FakeNotificationStore implements NotificationStoreInterface
{
    public array $recipients;
    public array $notifications = [];
    public array $emails = [];
    public bool $failCreateEvent = false;

    public function __construct(array $recipients)
    {
        $this->recipients = $recipients;
    }

    public function activeRecipient(int $userId): ?array
    {
        $recipient = $this->recipients[$userId] ?? null;
        return $recipient !== null && $recipient['status'] === 'active' ? $recipient : null;
    }

    public function activeAdministrators(): array
    {
        return array_values(array_filter($this->recipients, static fn(array $recipient): bool =>
            $recipient['role'] === 'admin' && $recipient['status'] === 'active'
        ));
    }

    public function createEvent(array $notification, ?array $email): bool
    {
        if ($this->failCreateEvent) {
            throw new RuntimeException('Simulated notification persistence failure.');
        }
        foreach ($this->notifications as $existing) {
            if ($existing['user_id'] === $notification['user_id'] && $existing['event_key'] === $notification['event_key']) {
                return false;
            }
        }
        $notification['id'] = count($this->notifications) + 1;
        $notification['is_read'] = 0;
        $this->notifications[] = $notification;
        if ($email !== null) {
            $this->emails[] = $email;
        }
        return true;
    }

    public function unreadCount(int $userId): int
    {
        return count(array_filter($this->notifications, static fn(array $item): bool =>
            $item['user_id'] === $userId && $item['is_read'] === 0
        ));
    }

    public function listForUser(int $userId, int $limit): array
    {
        return array_slice(array_values(array_filter($this->notifications, static fn(array $item): bool =>
            $item['user_id'] === $userId
        )), 0, $limit);
    }

    public function markRead(int $userId, int $notificationId, string $readAt): bool
    {
        foreach ($this->notifications as &$item) {
            if ($item['id'] === $notificationId && $item['user_id'] === $userId) {
                $item['is_read'] = 1;
                $item['read_at'] = $readAt;
                return true;
            }
        }
        unset($item);
        return false;
    }

    public function markAllRead(int $userId, string $readAt): int
    {
        $count = 0;
        foreach ($this->notifications as &$item) {
            if ($item['user_id'] === $userId && $item['is_read'] === 0) {
                $item['is_read'] = 1;
                $item['read_at'] = $readAt;
                $count++;
            }
        }
        unset($item);
        return $count;
    }
}

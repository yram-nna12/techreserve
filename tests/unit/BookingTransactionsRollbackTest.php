<?php

use App\Services\BookingTransactions;
use App\Services\NotificationService;
use App\Services\NotificationStoreInterface;
use CodeIgniter\Database\BaseConnection;
use Config\Notifications;
use PHPUnit\Framework\TestCase;

final class BookingTransactionsRollbackTest extends TestCase
{
    public function testAtomicPaymentNotificationFailureRollsBackItsEnclosingTransaction(): void
    {
        $store = new FailingAtomicNotificationStore();
        $notifications = new NotificationService(store: $store, settings: $this->enabledSettings());
        $db = $this->createMock(BaseConnection::class);
        $db->method('transException')->willReturnSelf();
        $db->method('resetTransStatus')->willReturnSelf();
        $db->method('query')->willReturn(true);
        $db->method('transBegin')->willReturn(true);
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $db->expects($this->never())->method('transCommit');

        try {
            (new BookingTransactions($db))->run(
                static fn() => $notifications->persistPaymentStatusChanged(
                    4,
                    25,
                    12,
                    'paid',
                    ['amount' => '45.00', 'currency' => 'PHP']
                )
            );
            $this->fail('Notification storage failure did not escape the payment transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification persistence failure.', $exception->getMessage());
        }
    }

    private function enabledSettings(): Notifications
    {
        $settings = new Notifications();
        $settings->enabled = true;
        return $settings;
    }
}

final class FailingAtomicNotificationStore implements NotificationStoreInterface
{
    public function activeRecipient(int $userId): ?array
    {
        return ['id' => $userId, 'role' => 'borrower', 'status' => 'active', 'email' => 'borrower@example.test'];
    }

    public function activeAdministrators(): array
    {
        return [];
    }

    public function createEvent(array $notification, ?array $email): bool
    {
        throw new RuntimeException('Simulated notification persistence failure.');
    }

    public function unreadCount(int $userId): int
    {
        return 0;
    }

    public function listForUser(int $userId, int $limit): array
    {
        return [];
    }

    public function markRead(int $userId, int $notificationId, string $readAt): bool
    {
        return false;
    }

    public function markAllRead(int $userId, string $readAt): int
    {
        return 0;
    }
}

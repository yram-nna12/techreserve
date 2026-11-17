<?php

namespace App\Services;

interface NotificationStoreInterface
{
    public function activeRecipient(int $userId): ?array;

    public function activeAdministrators(): array;

    public function createEvent(array $notification, ?array $email): bool;

    public function unreadCount(int $userId): int;

    public function listForUser(int $userId, int $limit): array;

    public function markRead(int $userId, int $notificationId, string $readAt): bool;

    public function markAllRead(int $userId, string $readAt): int;
}

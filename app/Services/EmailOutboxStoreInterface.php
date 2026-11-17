<?php

namespace App\Services;

interface EmailOutboxStoreInterface
{
    public function claimNext(int $maxAttempts, int $leaseSeconds, string $now): ?array;

    public function markSent(int $id, string $sentAt): void;

    public function markFailure(
        int $id,
        string $status,
        ?string $availableAt,
        string $errorCode,
        string $updatedAt
    ): void;
}

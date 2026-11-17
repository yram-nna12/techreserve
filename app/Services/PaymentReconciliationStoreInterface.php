<?php

namespace App\Services;

interface PaymentReconciliationStoreInterface
{
    public function acquireLock(): bool;

    public function releaseLock(): void;

    public function findStalePending(string $staleBefore, int $limit): array;

    public function recordAttempt(int $paymentId, string $attemptedAt): void;
}

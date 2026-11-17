<?php

use App\Services\PaymentReconciliationPolicy;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationPolicyTest extends TestCase
{
    public function testUsesTheNewestAvailableTimestampToSelectStalePayments(): void
    {
        $cutoff = new DateTimeImmutable('2026-11-17 10:10:00', new DateTimeZone('UTC'));

        $this->assertTrue(PaymentReconciliationPolicy::isStale([
            'last_verified_at' => null,
            'updated_at' => '2026-11-17 09:00:00',
            'created_at' => '2026-11-17 08:00:00',
        ], $cutoff));
        $this->assertFalse(PaymentReconciliationPolicy::isStale([
            'last_verified_at' => '2026-11-17 10:10:01',
            'updated_at' => '2026-11-17 09:00:00',
            'created_at' => '2026-11-17 08:00:00',
        ], $cutoff));
        $this->assertFalse(PaymentReconciliationPolicy::isStale([
            'last_verified_at' => '2026-11-17 09:00:00',
            'updated_at' => '2026-11-17 10:10:01',
            'created_at' => '2026-11-17 08:00:00',
        ], $cutoff));
    }

    public function testPaymentsWithoutAUsableTimestampAreNotSelected(): void
    {
        $cutoff = new DateTimeImmutable('2026-11-17 10:10:00', new DateTimeZone('UTC'));

        $this->assertFalse(PaymentReconciliationPolicy::isStale([
            'last_verified_at' => null,
            'updated_at' => 'invalid',
            'created_at' => null,
        ], $cutoff));
    }
}

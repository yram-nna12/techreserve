<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class DatabasePaymentReconciliationStore implements PaymentReconciliationStoreInterface
{
    private const LOCK_NAME = 'techreserve:payments:reconcile';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function acquireLock(): bool
    {
        $result = $this->db->query('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME])->getRowArray();
        if (!is_array($result) || !array_key_exists('acquired', $result)) {
            throw new RuntimeException('The payment reconciliation lock could not be checked.');
        }

        return (int) $result['acquired'] === 1;
    }

    public function releaseLock(): void
    {
        $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
    }

    public function findStalePending(string $staleBefore, int $limit): array
    {
        $payments = $this->db->prefixTable('payments');
        $activity = 'GREATEST('
            . "COALESCE(last_verified_at, '1000-01-01 00:00:00'), "
            . "COALESCE(updated_at, '1000-01-01 00:00:00'), "
            . "COALESCE(created_at, '1000-01-01 00:00:00'))";
        $sql = 'SELECT id, reservation_id, transaction_reference, amount, currency, payment_method,'
            . ' last_verified_at, updated_at, created_at'
            . ' FROM ' . $payments
            . ' WHERE payment_status = ? AND transaction_reference IS NOT NULL'
            . ' AND (last_verified_at IS NOT NULL OR updated_at IS NOT NULL OR created_at IS NOT NULL)'
            . ' AND ' . $activity . ' <= ?'
            . ' ORDER BY ' . $activity . ' ASC, id ASC LIMIT ' . max(1, min(100, $limit));

        return $this->db->query($sql, ['pending', $staleBefore])->getResultArray();
    }

    public function recordAttempt(int $paymentId, string $attemptedAt): void
    {
        $this->db->table('payments')
            ->where('id', $paymentId)
            ->where('payment_status', 'pending')
            ->update(['updated_at' => $attemptedAt]);
    }
}

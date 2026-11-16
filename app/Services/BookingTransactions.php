<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

class BookingTransactions
{
    public BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function run(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= config('Booking')->transactionAttempts; $attempt++) {
            $this->db->resetTransStatus()->transException(true);
            try {
                // Applies only to this transaction; no application/global timezone or isolation change.
                $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                if (!$this->db->transBegin()) {
                    throw new RuntimeException('Cannot begin booking transaction.');
                }
                $result = $operation($this->db);
                if (!$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new RuntimeException('Booking transaction failed.');
                }
                return $result;
            } catch (Throwable $exception) {
                $code = (int) $exception->getCode();
                for ($cause = $exception->getPrevious(); $cause; $cause = $cause->getPrevious()) {
                    if (in_array((int) $cause->getCode(), [1205, 1213], true)) {
                        $code = (int) $cause->getCode();
                    }
                }
                $this->db->transRollback();
                $this->db->resetTransStatus();
                if (in_array($code, [1205, 1213], true) && $attempt < config('Booking')->transactionAttempts) {
                    usleep(10000 * $attempt);
                    continue;
                }
                throw $exception;
            } finally {
                $this->db->transException(false);
            }
        }
        throw new RuntimeException('Booking transaction retry limit reached.');
    }

    public function lockResources(array $ids): array
    {
        sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->db->query('SELECT * FROM ' . $this->db->prefixTable('resources') . ' WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$row) {
                throw new \DomainException('A selected resource no longer exists.');
            }
            $rows[(int) $id] = $row;
        }
        return $rows;
    }
}

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

    public function lockResources(array $ids, array $conflictGroups = [], array $catalogGroups = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach ($ids as $id) {
            $this->lockResource($id, $rows);
        }

        do {
            $groups = $conflictGroups;
            $catalogs = $catalogGroups;
            foreach ($rows as $resource) {
                if ($resource['resource_type'] === 'facility') {
                    $groups = array_merge($groups, ResourceConflictPolicy::groups($resource));
                }
                if ($resource['resource_type'] === 'equipment' && is_string($resource['catalog_group'] ?? null)) {
                    $catalogs[] = $resource['catalog_group'];
                }
            }
            $groups = array_values(array_unique(array_filter($groups, static fn($value): bool => is_string($value) && $value !== '')));
            $catalogs = array_values(array_unique(array_filter($catalogs, static fn($value): bool => is_string($value) && $value !== '')));
            if ($groups === [] && $catalogs === []) {
                break;
            }

            $builder = $this->db->table('resources')->select('id')->where('deleted_at', null)->groupStart();
            $hasCondition = false;
            if ($groups !== []) {
                $builder->groupStart()->whereIn('conflict_group', $groups)->orWhereIn('conflict_group_alt', $groups)->groupEnd();
                $hasCondition = true;
            }
            if ($catalogs !== []) {
                if ($hasCondition) {
                    $builder->orGroupStart();
                } else {
                    $builder->groupStart();
                }
                $builder->where('resource_type', 'equipment')->whereIn('catalog_group', $catalogs)->groupEnd();
            }
            $relatedIds = array_column($builder->groupEnd()->orderBy('id')->get()->getResultArray(), 'id');
            $newIds = array_values(array_diff(array_map('intval', $relatedIds), array_keys($rows)));
            sort($newIds, SORT_NUMERIC);
            foreach ($newIds as $id) {
                $this->lockResource($id, $rows);
            }
        } while ($newIds !== []);

        ksort($rows, SORT_NUMERIC);
        return $rows;
    }

    private function lockResource(int $id, array &$rows): void
    {
        if (isset($rows[$id])) {
            return;
        }
        $row = $this->db->query(
            'SELECT * FROM ' . $this->db->prefixTable('resources') . ' WHERE id = ? FOR UPDATE',
            [$id]
        )->getRowArray();
        if (!$row) {
            throw new \DomainException('A selected resource no longer exists.');
        }
        $rows[$id] = $row;
    }
}

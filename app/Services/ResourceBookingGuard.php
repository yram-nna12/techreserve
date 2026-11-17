<?php

namespace App\Services;

use App\Models\ResourceModel;
use CodeIgniter\Database\BaseConnection;
use DomainException;

class ResourceBookingGuard
{
    private BookingTransactions $transactions;

    public function __construct(?BaseConnection $db = null)
    {
        $this->transactions = new BookingTransactions($db);
    }

    public function change(int $id, ?array $data): void
    {
        $this->transactions->run(function ($db) use ($id, $data) {
            $resource = $this->transactions->lockResources(
                [$id],
                $data ? ResourceConflictPolicy::groups($data) : [],
                $data && is_string($data['catalog_group'] ?? null) ? [$data['catalog_group']] : []
            )[$id];
            if ($resource['deleted_at'] !== null) {
                throw new DomainException('Resource is already archived.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $availability = new AvailabilityService($db);
            $allocations = $availability->allocations($id, $now);
            if ($allocations !== []) {
                if ($data === null || $data['resource_type'] !== $resource['resource_type'] || $data['status'] !== 'available') {
                    throw new DomainException('This resource has upcoming or active approved reservations. Cancel those reservations explicitly before archiving, changing type, or making it unavailable.');
                }
                if ((int) $data['quantity'] < AvailabilityService::peak($allocations, $now)) {
                    throw new DomainException('Stock cannot be reduced below peak upcoming or active approved commitments.');
                }
            }
            if ($data !== null && $resource['resource_type'] === 'facility') {
                $conflictGroupsChanged = ($data['conflict_group'] ?? null) !== ($resource['conflict_group'] ?? null)
                    || ($data['conflict_group_alt'] ?? null) !== ($resource['conflict_group_alt'] ?? null);
                if ($conflictGroupsChanged && $availability->facilityCommitments($resource, $now) !== []) {
                    throw new DomainException('A facility with upcoming or active shared-space reservations cannot be moved between scheduling-conflict groups.');
                }
            }
            if ($data !== null && $allocations !== []) {
                foreach (['catalog_group', 'conflict_group', 'conflict_group_alt'] as $field) {
                    if (($data[$field] ?? null) !== ($resource[$field] ?? null)) {
                        throw new DomainException('A resource with approved commitments cannot be moved between matching or scheduling-conflict groups.');
                    }
                }
            }
            if ($data !== null) {
                $this->assertInventoryIdentifierAvailable($db, $data['inventory_identifier'] ?? null, $id);
            }
            $model = new ResourceModel($db);
            $saved = $data === null ? $model->delete($id) : $model->update($id, $data);
            if (!$saved) {
                throw new DomainException('The resource change could not be saved.');
            }
        });
    }

    public function create(array $data): int
    {
        return $this->transactions->run(function ($db) use ($data): int {
            $this->transactions->lockResources(
                [],
                ResourceConflictPolicy::groups($data),
                is_string($data['catalog_group'] ?? null) ? [$data['catalog_group']] : []
            );
            $this->assertInventoryIdentifierAvailable($db, $data['inventory_identifier'] ?? null);
            $model = new ResourceModel($db);
            $id = $model->insert($data, true);
            if (!$id) {
                throw new DomainException('The resource could not be saved.');
            }

            return (int) $id;
        });
    }

    private function assertInventoryIdentifierAvailable(BaseConnection $db, ?string $identifier, int $excludeId = 0): void
    {
        if ($identifier === null || $identifier === '') {
            return;
        }
        $query = $db->table('resources')->select('id')->where('inventory_identifier', $identifier);
        if ($excludeId > 0) {
            $query->where('id <>', $excludeId);
        }
        if ($query->get(1)->getRowArray()) {
            throw new DomainException('That inventory identifier is already in use.');
        }
    }
}

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
            $resource = $this->transactions->lockResources([$id])[$id];
            if ($resource['deleted_at'] !== null) {
                throw new DomainException('Resource is already archived.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $allocations = (new AvailabilityService($db))->allocations($id, $now);
            if ($allocations !== []) {
                if ($data === null || $data['resource_type'] !== $resource['resource_type'] || $data['status'] !== 'available') {
                    throw new DomainException('This resource has upcoming or active approved reservations. Cancel those reservations explicitly before archiving, changing type, or making it unavailable.');
                }
                if ((int) $data['quantity'] < AvailabilityService::peak($allocations, $now)) {
                    throw new DomainException('Stock cannot be reduced below peak upcoming or active approved commitments.');
                }
            }
            $model = new ResourceModel($db);
            $saved = $data === null ? $model->delete($id) : $model->update($id, $data);
            if (!$saved) {
                throw new DomainException('The resource change could not be saved.');
            }
        });
    }
}

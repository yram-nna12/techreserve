<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

class AvailabilityService
{
    public function __construct(private BaseConnection $db)
    {
    }

    /** Caller holds the resource mutex. FOR UPDATE guarantees current reads. */
    public function allocations(int $resourceId, string $start, ?string $end = null, int $exclude = 0): array
    {
        $sql = 'SELECT r.start_datetime, r.end_datetime, i.quantity FROM ' . $this->db->prefixTable('reservation_items') . ' i JOIN '
            . $this->db->prefixTable('reservations') . ' r ON r.id = i.reservation_id WHERE i.resource_id = ? '
            . "AND r.status = 'approved' AND r.end_datetime > ? AND r.id <> ?";
        $bindings = [$resourceId, $start, $exclude];
        if ($end !== null) {
            $sql .= ' AND r.start_datetime < ?';
            $bindings[] = $end;
        }
        return $this->db->query($sql . ' FOR UPDATE', $bindings)->getResultArray();
    }

    public static function peak(array $allocations, string $start, ?string $end = null): int
    {
        $events = [];
        foreach ($allocations as $row) {
            $from = max($start, $row['start_datetime']);
            $to = $end === null ? $row['end_datetime'] : min($end, $row['end_datetime']);
            if ($from < $to) {
                $events[] = [$from, (int) $row['quantity']];
                $events[] = [$to, -(int) $row['quantity']];
            }
        }
        usort($events, static fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $used = $peak = 0;
        foreach ($events as [, $change]) {
            $used += $change;
            $peak = max($peak, $used);
        }
        return $peak;
    }

    public function check(array $resource, int $quantity, string $start, string $end, int $exclude = 0): void
    {
        if ($resource['deleted_at'] !== null || $resource['status'] !== 'available') {
            throw new DomainException('A selected resource is archived, unavailable, or under maintenance.');
        }
        $stock = $resource['resource_type'] === 'facility' ? 1 : (int) $resource['quantity'];
        if ($quantity < 1 || $quantity > $stock || ($resource['resource_type'] === 'facility' && $quantity !== 1)) {
            throw new DomainException('Invalid requested quantity for a selected resource.');
        }
        if (self::peak($this->allocations((int) $resource['id'], $start, $end, $exclude), $start, $end) + $quantity > $stock) {
            throw new DomainException('A selected resource has insufficient availability for this schedule.');
        }
    }
}

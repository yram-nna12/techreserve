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

    private function facilityConflicts(array $resource, string $start, string $end, int $exclude): array
    {
        return $this->facilityCommitments($resource, $start, $end, $exclude);
    }

    public function facilityCommitments(array $resource, string $start, ?string $end = null, int $exclude = 0): array
    {
        $groups = ResourceConflictPolicy::groups($resource);
        $sql = 'SELECT r.start_datetime, r.end_datetime, i.quantity FROM '
            . $this->db->prefixTable('reservation_items') . ' i JOIN '
            . $this->db->prefixTable('reservations') . ' r ON r.id = i.reservation_id JOIN '
            . $this->db->prefixTable('resources') . ' booked ON booked.id = i.resource_id'
            . " WHERE r.status = 'approved' AND r.end_datetime > ? AND r.id <> ?";
        $bindings = [$start, $exclude];
        if ($end !== null) {
            $sql .= ' AND r.start_datetime < ?';
            array_splice($bindings, 2, 0, [$end]);
        }
        $sql .= ' AND (booked.id = ?';
        $bindings[] = (int) $resource['id'];
        foreach ($groups as $group) {
            $sql .= ' OR booked.conflict_group = ? OR booked.conflict_group_alt = ?';
            $bindings[] = $group;
            $bindings[] = $group;
        }

        return $this->db->query($sql . ') FOR UPDATE', $bindings)->getResultArray();
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
        $allocations = $resource['resource_type'] === 'facility'
            ? $this->facilityConflicts($resource, $start, $end, $exclude)
            : $this->allocations((int) $resource['id'], $start, $end, $exclude);
        if (self::peak($allocations, $start, $end) + $quantity > $stock) {
            throw new DomainException('A selected resource has insufficient availability for this schedule.');
        }
    }

    public function remaining(array $resource, string $start, string $end, int $exclude = 0): int
    {
        if ($resource['deleted_at'] !== null || $resource['status'] !== 'available') {
            return 0;
        }
        $stock = $resource['resource_type'] === 'facility' ? 1 : (int) $resource['quantity'];
        $allocations = $resource['resource_type'] === 'facility'
            ? $this->facilityConflicts($resource, $start, $end, $exclude)
            : $this->allocations((int) $resource['id'], $start, $end, $exclude);

        return max(0, $stock - self::peak($allocations, $start, $end));
    }
}

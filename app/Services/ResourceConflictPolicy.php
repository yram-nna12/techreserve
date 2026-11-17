<?php

namespace App\Services;

use DomainException;

final class ResourceConflictPolicy
{
    public static function groups(array $resource): array
    {
        return array_values(array_unique(array_filter([
            $resource['conflict_group'] ?? null,
            $resource['conflict_group_alt'] ?? null,
        ], static fn($group): bool => is_string($group) && $group !== '')));
    }

    public static function conflicts(array $first, array $second): bool
    {
        if (($first['resource_type'] ?? null) !== 'facility' || ($second['resource_type'] ?? null) !== 'facility') {
            return false;
        }
        if ((int) ($first['id'] ?? 0) === (int) ($second['id'] ?? 0)) {
            return true;
        }

        return array_intersect(self::groups($first), self::groups($second)) !== [];
    }

    public static function assertNoConflicts(array $resources): void
    {
        $facilities = [];
        foreach ($resources as $resource) {
            if (($resource['resource_type'] ?? null) !== 'facility') {
                continue;
            }
            foreach ($facilities as $facility) {
                if (self::conflicts($facility, $resource)) {
                    throw new DomainException('The selected facility configurations overlap and cannot be reserved together.');
                }
            }
            $facilities[] = $resource;
        }
    }
}

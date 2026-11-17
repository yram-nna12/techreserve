<?php

namespace App\Services;

final class ResourceCatalog
{
    public static function borrowerGroups(array $resources): array
    {
        $groups = [];
        foreach ($resources as $resource) {
            if ($resource['resource_type'] !== 'equipment' || empty($resource['catalog_group'])) {
                $key = 'resource:' . (int) $resource['id'];
                $resource['inventory_count'] = 1;
                $groups[$key] = $resource;
                continue;
            }

            $key = implode("\0", [
                $resource['catalog_group'],
                $resource['category'] ?? 'General',
                $resource['rate_per_hour'],
                $resource['status'],
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = $resource;
                $groups[$key]['name'] = $resource['catalog_group'];
                $groups[$key]['quantity'] = 0;
                $groups[$key]['inventory_count'] = 0;
                $groups[$key]['_locations'] = [];
            }
            $groups[$key]['id'] = min((int) $groups[$key]['id'], (int) $resource['id']);
            $groups[$key]['quantity'] += (int) $resource['quantity'];
            $groups[$key]['inventory_count']++;
            if (!empty($resource['location'])) {
                $groups[$key]['_locations'][$resource['location']] = true;
            }
        }

        foreach ($groups as &$resource) {
            if (isset($resource['_locations'])) {
                $locations = array_keys($resource['_locations']);
                $resource['location'] = count($locations) === 1 ? $locations[0] : ($locations === [] ? null : 'Multiple locations');
                unset($resource['_locations']);
            }
        }
        unset($resource);

        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: ((int) $a['id'] <=> (int) $b['id']));

        return $groups;
    }
}

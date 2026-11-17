<?php

use App\Services\ResourceService;
use PHPUnit\Framework\TestCase;

final class ResourceServiceTest extends TestCase
{
    public function testKnownRatesAreSuggestedButAnAdministratorCanOverrideThem(): void
    {
        [$facility, $facilityErrors] = (new ResourceService())->validate($this->input([
            'name' => 'Basketball Gym with Aircon',
            'resource_type' => 'facility',
            'quantity' => '1',
        ]));
        [$equipment, $equipmentErrors] = (new ResourceService())->validate($this->input([
            'name' => 'Peavey Speaker',
            'resource_type' => 'equipment',
            'quantity' => '1',
        ]));
        [$override, $overrideErrors] = (new ResourceService())->validate($this->input([
            'name' => 'Peavey Speaker',
            'resource_type' => 'equipment',
            'quantity' => '1',
            'rate_per_hour' => '145',
        ]));

        $this->assertSame([], $facilityErrors);
        $this->assertSame('250.00', $facility['rate_per_hour']);
        $this->assertSame(['basketball-gym'], array_filter([$facility['conflict_group'], $facility['conflict_group_alt']]));
        $this->assertSame([], $equipmentErrors);
        $this->assertSame('150.00', $equipment['rate_per_hour']);
        $this->assertSame('Peavey Speaker', $equipment['catalog_group']);
        $this->assertSame([], $overrideErrors);
        $this->assertSame('145.00', $override['rate_per_hour']);
    }

    public function testCombinedFacilityGetsBothConflictGroupsAndRejectsTooMany(): void
    {
        [$combined, $combinedErrors] = (new ResourceService())->validate($this->input([
            'name' => 'Executive Lounge 1 and 2 Combined',
            'resource_type' => 'facility',
        ]));
        [, $invalidErrors] = (new ResourceService())->validate($this->input([
            'conflict_groups' => 'one,two,three',
        ]));

        $this->assertSame([], $combinedErrors);
        $this->assertSame(['executive-lounge-1', 'executive-lounge-2'], [
            $combined['conflict_group'],
            $combined['conflict_group_alt'],
        ]);
        $this->assertArrayHasKey('conflict_groups', $invalidErrors);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Classroom',
            'description' => '',
            'resource_type' => 'facility',
            'category' => '',
            'quantity' => '1',
            'rate_per_hour' => '',
            'status' => 'available',
            'location' => '',
            'capacity' => '',
            'catalog_group' => '',
            'inventory_identifier' => '',
            'conflict_groups' => '',
        ], $overrides);
    }
}

<?php

use App\Services\ResourceCatalog;
use PHPUnit\Framework\TestCase;

final class ResourceCatalogTest extends TestCase
{
    public function testMatchingEquipmentIsGroupedWithoutChangingAggregateInventory(): void
    {
        $catalog = ResourceCatalog::borrowerGroups([
            $this->equipment(4, 'Projector #1', 'Projector', 2, 'A'),
            $this->equipment(7, 'Projector #2', 'Projector', 3, 'B'),
            $this->equipment(8, 'Projector #3', 'Projector', 1, 'B', '75.00'),
        ]);

        $this->assertCount(2, $catalog);
        $this->assertSame('Projector', $catalog[0]['name']);
        $this->assertSame(5, $catalog[0]['quantity']);
        $this->assertSame(2, $catalog[0]['inventory_count']);
        $this->assertSame('Multiple locations', $catalog[0]['location']);
        $this->assertSame('60.00', $catalog[0]['rate_per_hour']);
        $this->assertSame(1, $catalog[1]['quantity']);
        $this->assertSame(8, $catalog[1]['id']);
    }

    private function equipment(int $id, string $name, string $group, int $quantity, string $location, string $rate = '60.00'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'resource_type' => 'equipment',
            'category' => 'Audio',
            'catalog_group' => $group,
            'quantity' => $quantity,
            'rate_per_hour' => $rate,
            'status' => 'available',
            'location' => $location,
        ];
    }
}

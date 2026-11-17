<?php

use App\Services\ResourceConflictPolicy;
use PHPUnit\Framework\TestCase;

final class ResourceConflictPolicyTest extends TestCase
{
    public function testCombinedLoungesConflictWithEachComponentButComponentsRemainIndependent(): void
    {
        $loungeOne = ['id' => 1, 'resource_type' => 'facility', 'conflict_group' => 'executive-lounge-1'];
        $loungeTwo = ['id' => 2, 'resource_type' => 'facility', 'conflict_group' => 'executive-lounge-2'];
        $combined = [
            'id' => 3,
            'resource_type' => 'facility',
            'conflict_group' => 'executive-lounge-1',
            'conflict_group_alt' => 'executive-lounge-2',
        ];

        $this->assertFalse(ResourceConflictPolicy::conflicts($loungeOne, $loungeTwo));
        $this->assertTrue(ResourceConflictPolicy::conflicts($loungeOne, $combined));
        $this->assertTrue(ResourceConflictPolicy::conflicts($loungeTwo, $combined));
    }

    public function testAlternativeGymConfigurationsShareOneConflictGroup(): void
    {
        $aircon = ['id' => 10, 'resource_type' => 'facility', 'conflict_group' => 'basketball-gym'];
        $greenMat = ['id' => 11, 'resource_type' => 'facility', 'conflict_group' => 'basketball-gym'];

        $this->assertTrue(ResourceConflictPolicy::conflicts($aircon, $greenMat));
        $this->expectException(DomainException::class);
        ResourceConflictPolicy::assertNoConflicts([$aircon, $greenMat]);
    }
}

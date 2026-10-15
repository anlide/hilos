<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\ResourceProfile;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-side resource cost value object (HIL-182, HIL-448), and the mandatory
 * slot placement adds to it (HIL-447).
 */
final class ResourceProfileTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        $profile = ResourceProfile::none();

        $this->assertTrue($profile->isEmpty());
        $this->assertSame([], $profile->costs);
    }

    public function testCostsCarriesTheMap(): void
    {
        $profile = ResourceProfile::costs(['ram' => 2.0, 'cpu' => 1.0]);

        $this->assertFalse($profile->isEmpty());
        $this->assertSame(['ram' => 2.0, 'cpu' => 1.0], $profile->costs);
    }

    public function testZeroCostsAreDropped(): void
    {
        $this->assertSame(['ram' => 2.0], ResourceProfile::costs(['ram' => 2.0, 'gpu' => 0.0])->costs);
        $this->assertTrue(ResourceProfile::costs(['gpu' => 0.0])->isEmpty(), 'A map of zeros adds no project cost');
    }

    public function testNegativeCostIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Resource cost of 'ram' must not be negative, got -1");

        ResourceProfile::costs(['ram' => -1.0]);
    }

    public function testPlacementChargesAnAgentWithNoProjectCostOneSlot(): void
    {
        $this->assertSame([ResourceProfile::RESOURCE_SLOTS => 1.0], ResourceProfile::none()->forPlacement()->costs);
        $this->assertFalse(ResourceProfile::none()->forPlacement()->isEmpty(), 'No placed agent is free');
    }

    public function testPlacementAddsTheSlotToTheProjectCost(): void
    {
        $profile = ResourceProfile::costs(['ram' => 2.0]);

        $this->assertSame(['ram' => 2.0, ResourceProfile::RESOURCE_SLOTS => 1.0], $profile->forPlacement()->costs);
        $this->assertSame(['ram' => 2.0], $profile->costs, 'The project cost itself is left as the agent declared it');
    }

    public function testNormalizingTwiceChargesOneSlot(): void
    {
        $once = ResourceProfile::costs(['ram' => 2.0])->forPlacement();

        $this->assertSame($once->costs, $once->forPlacement()->costs);
        $this->assertSame([ResourceProfile::RESOURCE_SLOTS => 1.0], ResourceProfile::none()->forPlacement()->forPlacement()->costs);
    }

    public function testAnAgentCannotDeclareTheSlotItself(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Resource 'slots' is reserved: placement charges every agent one, an agent declares none");

        ResourceProfile::costs(['ram' => 2.0, ResourceProfile::RESOURCE_SLOTS => 1.0]);
    }

    public function testAnAgentCannotCancelTheSlotWithAZero(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Resource 'slots' is reserved");

        ResourceProfile::costs([ResourceProfile::RESOURCE_SLOTS => 0.0]);
    }
}

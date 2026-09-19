<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\ResourceProfile;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-side resource cost value object (HIL-182, HIL-448).
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
        $profile = ResourceProfile::costs(['ram' => 2.0, 'slots' => 1.0]);

        $this->assertFalse($profile->isEmpty());
        $this->assertSame(['ram' => 2.0, 'slots' => 1.0], $profile->costs);
    }

    public function testZeroCostsAreDropped(): void
    {
        $this->assertSame(['ram' => 2.0], ResourceProfile::costs(['ram' => 2.0, 'gpu' => 0.0])->costs);
        $this->assertTrue(ResourceProfile::costs(['gpu' => 0.0])->isEmpty(), 'A map of zeros is the free agent');
    }

    public function testNegativeCostIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Resource cost of 'ram' must not be negative, got -1");

        ResourceProfile::costs(['ram' => -1.0]);
    }
}

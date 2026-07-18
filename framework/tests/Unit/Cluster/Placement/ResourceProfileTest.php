<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-side resource demand value object (HIL-182).
 */
final class ResourceProfileTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        $profile = ResourceProfile::none();

        $this->assertTrue($profile->isEmpty());
        $this->assertSame([], $profile->minimums);
        $this->assertSame([], $profile->preferences);
    }

    public function testCreateCarriesMinimumsAndPreferences(): void
    {
        $profile = ResourceProfile::create(minimums: ['ram' => 16.0], preferences: ['cpu' => 2.0]);

        $this->assertFalse($profile->isEmpty());
        $this->assertSame(16.0, $profile->minimums['ram']);
        $this->assertSame(2.0, $profile->preferences['cpu']);
    }

    public function testAMinimumOnlyProfileIsNotEmpty(): void
    {
        $this->assertFalse(ResourceProfile::create(minimums: ['ram' => 8.0])->isEmpty());
    }
}

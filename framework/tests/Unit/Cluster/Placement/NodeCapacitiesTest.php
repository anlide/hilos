<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\NodeCapacities;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the capability-tag reading that backs resource-aware placement (HIL-182).
 *
 * A node advertises a flat tag list; these pin how that list is split into boolean tags and
 * numeric capacities, and how malformed tokens degrade to plain tags.
 */
final class NodeCapacitiesTest extends TestCase
{
    public function testBooleanTagsArePresenceFlags(): void
    {
        $capacities = NodeCapacities::fromTags(['worker', 'gpu']);

        $this->assertTrue($capacities->hasTag('worker'));
        $this->assertTrue($capacities->hasTag('gpu'));
        $this->assertFalse($capacities->hasTag('ram'));
    }

    public function testNumericCapacitiesAreParsedAndSummed(): void
    {
        $capacities = NodeCapacities::fromTags(['worker', 'cpu=8', 'ram=32']);

        $this->assertSame(8.0, $capacities->capacity('cpu'));
        $this->assertSame(32.0, $capacities->capacity('ram'));
        $this->assertSame(40.0, $capacities->totalCapacity(), 'Total capacity sums the numeric capacities');
        $this->assertFalse($capacities->hasTag('cpu'), 'A numeric capacity is not also a boolean tag');
    }

    public function testAbsentCapacityIsZero(): void
    {
        $this->assertSame(0.0, NodeCapacities::fromTags(['worker'])->capacity('cpu'));
        $this->assertSame(0.0, NodeCapacities::fromTags([])->totalCapacity());
    }

    public function testMalformedTokensDegradeToPlainTags(): void
    {
        // A missing key, a non-numeric value, and a bare key stay opaque boolean tags rather
        // than becoming capacities, so an operator typo never parses as a number.
        $capacities = NodeCapacities::fromTags(['=8', 'cpu=lots', ' gpu ']);

        $this->assertTrue($capacities->hasTag('=8'));
        $this->assertTrue($capacities->hasTag('cpu=lots'));
        $this->assertTrue($capacities->hasTag('gpu'), 'Surrounding whitespace is trimmed');
        $this->assertSame(0.0, $capacities->capacity('cpu'));
    }

    public function testFractionalCapacityIsPreserved(): void
    {
        $this->assertSame(1.5, NodeCapacities::fromTags(['ram=1.5'])->capacity('ram'));
    }
}

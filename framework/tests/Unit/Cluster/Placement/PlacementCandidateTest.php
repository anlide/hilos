<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\PlacementCandidate;
use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for one node as the placement policy sees it (HIL-448): free capacity, the gate
 * both the policy and a placement by node name go through, and the load after placement.
 */
final class PlacementCandidateTest extends TestCase
{
    public function testFreeIsDeclaredMinusHeld(): void
    {
        $candidate = $this->candidate(['ram=10'], ['ram' => 4.0]);

        $this->assertSame(6.0, $candidate->free('ram'));
        $this->assertSame(0.0, $candidate->free('gpu'), 'An undeclared resource has nothing free');
    }

    public function testShortfallsNameEveryResourceWithoutRoom(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=2'], ['ram' => 9.0, 'slots' => 1.0]);

        $this->assertSame(
            ['ram' => ['cost' => 2.0, 'free' => 1.0]],
            $candidate->shortfalls(ResourceProfile::costs(['ram' => 2.0, 'slots' => 1.0])),
        );
        $this->assertSame([], $candidate->shortfalls(ResourceProfile::costs(['ram' => 1.0])), 'Exactly the free room fits');
    }

    public function testAcceptsRequiresEveryTag(): void
    {
        $this->assertFalse($this->candidate(['ram=10'])->accepts(['gpu'], ResourceProfile::none()));
        $this->assertTrue($this->candidate(['gpu', 'ram=10'])->accepts(['gpu'], ResourceProfile::none()));
    }

    public function testAFreeAgentStillNeedsADeclaredCapacity(): void
    {
        $this->assertFalse(
            $this->candidate(['worker'])->accepts(['worker'], ResourceProfile::none()),
            'A node that declares no capacity takes no placed work, even work that costs nothing',
        );
        $this->assertTrue($this->candidate(['worker', 'ram=0'])->accepts(['worker'], ResourceProfile::none()));
    }

    public function testAcceptsRefusesWhenTheCostDoesNotFit(): void
    {
        $candidate = $this->candidate(['ram=10'], ['ram' => 9.0]);

        $this->assertFalse($candidate->accepts([], ResourceProfile::costs(['ram' => 2.0])));
        $this->assertTrue($candidate->accepts([], ResourceProfile::costs(['ram' => 1.0])));
    }

    public function testLoadAfterIsTheMostLoadedCostedResource(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=4'], ['ram' => 2.0, 'slots' => 2.0]);

        $this->assertSame(0.75, $candidate->loadAfter(ResourceProfile::costs(['ram' => 1.0, 'slots' => 1.0])));
        $this->assertSame(0.3, $candidate->loadAfter(ResourceProfile::costs(['ram' => 1.0])), 'Only costed resources count');
    }

    public function testLoadAfterOfAFreeAgentIsZero(): void
    {
        $this->assertSame(0.0, $this->candidate(['ram=10'], ['ram' => 8.0])->loadAfter(ResourceProfile::none()));
    }

    /**
     * Builds a follower candidate from advertised tags and held capacity.
     *
     * @param list<string> $tags Advertised capability tags
     * @param array<string, float> $used Held capacity keyed by resource name
     * @return PlacementCandidate Candidate
     */
    private function candidate(array $tags, array $used = []): PlacementCandidate
    {
        return new PlacementCandidate('n1', NodeCapacities::fromTags($tags), $used, 0, false);
    }
}

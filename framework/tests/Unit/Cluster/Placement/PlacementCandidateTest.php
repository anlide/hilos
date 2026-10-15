<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\PlacementCandidate;
use Hilos\Cluster\Placement\PlacementRefusalCode;
use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for one node as the placement policy sees it (HIL-448): free capacity, the gate
 * both the policy and a placement by node name go through, and the load after placement — each
 * at the cost placement charges, the agent's own plus its mandatory slot (HIL-447) — and the
 * gate's answer as a structured refusal naming the first gate the node fails (HIL-447).
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
            $candidate->shortfalls(ResourceProfile::costs(['ram' => 2.0])),
        );
        $this->assertSame([], $candidate->shortfalls(ResourceProfile::costs(['ram' => 1.0])), 'Exactly the free room fits');
    }

    public function testShortfallsChargeTheSlotOfACostlessAgent(): void
    {
        $this->assertSame(
            ['slots' => ['cost' => 1.0, 'free' => 0.0]],
            $this->candidate(['worker', 'ram=10'])->shortfalls(ResourceProfile::none()),
            'A node with ram but no slots is short of the one slot every placed agent takes',
        );
        $this->assertSame(
            ['slots' => ['cost' => 1.0, 'free' => 0.0]],
            $this->candidate(['slots=0'])->shortfalls(ResourceProfile::none()),
        );
        $this->assertSame([], $this->candidate(['slots=1'])->shortfalls(ResourceProfile::none()));
    }

    public function testANormalizedCostIsNotChargedTwice(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=1']);

        $this->assertSame([], $candidate->shortfalls(ResourceProfile::costs(['ram' => 1.0])->forPlacement()));
        $this->assertTrue($candidate->accepts([], ResourceProfile::none()->forPlacement()));
    }

    public function testAcceptsRequiresEveryTag(): void
    {
        $this->assertFalse($this->candidate(['ram=10', 'slots=1'])->accepts(['gpu'], ResourceProfile::none()));
        $this->assertTrue($this->candidate(['gpu', 'ram=10', 'slots=1'])->accepts(['gpu'], ResourceProfile::none()));
    }

    public function testAFreeAgentStillNeedsADeclaredCapacity(): void
    {
        $this->assertFalse(
            $this->candidate(['worker'])->accepts(['worker'], ResourceProfile::none()),
            'A node that declares no capacity takes no placed work, even work with no project cost',
        );
        $this->assertTrue($this->candidate(['worker', 'slots=1'])->accepts(['worker'], ResourceProfile::none()));
    }

    public function testADirectAcceptWithNoCostDoesNotWalkPastTheSlot(): void
    {
        $this->assertFalse(
            $this->candidate(['worker', 'ram=10'])->accepts(['worker'], ResourceProfile::none()),
            'Declaring ram is a declaration, but it leaves no slot for the agent',
        );
        $this->assertFalse($this->candidate(['worker', 'ram=0'])->accepts(['worker'], ResourceProfile::none()));
        $this->assertFalse($this->candidate(['worker', 'slots=0'])->accepts(['worker'], ResourceProfile::none()));
        $this->assertTrue($this->candidate(['worker', 'slots=1'])->accepts(['worker'], ResourceProfile::none()));
    }

    public function testEveryLivePlacementHoldsItsSlot(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=3'], ['slots' => 3.0], 3);

        $this->assertFalse($candidate->accepts([], ResourceProfile::none()), 'Three costless agents fill slots=3');
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $candidate->shortfalls(ResourceProfile::none()));
    }

    public function testAcceptsRefusesWhenTheCostDoesNotFit(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=64'], ['ram' => 9.0]);

        $this->assertFalse($candidate->accepts([], ResourceProfile::costs(['ram' => 2.0])));
        $this->assertTrue($candidate->accepts([], ResourceProfile::costs(['ram' => 1.0])));
    }

    public function testLoadAfterIsTheMostLoadedCostedResource(): void
    {
        $candidate = $this->candidate(['ram=10', 'slots=4', 'cpu=2'], ['ram' => 2.0, 'slots' => 2.0, 'cpu' => 2.0]);

        $this->assertSame(0.75, $candidate->loadAfter(ResourceProfile::costs(['ram' => 1.0])), 'The slot is the most loaded here');
        $this->assertSame(
            0.3,
            $this->candidate(['ram=10', 'slots=40', 'cpu=2'], ['ram' => 2.0, 'slots' => 2.0, 'cpu' => 2.0])
                ->loadAfter(ResourceProfile::costs(['ram' => 1.0])),
            'A resource the agent does not cost does not count, however full it is',
        );
    }

    public function testACostlessAgentLoadsTheNodeByItsSlotShare(): void
    {
        $this->assertSame(0.5, $this->candidate(['ram=10', 'slots=4'], ['ram' => 8.0, 'slots' => 1.0])->loadAfter(ResourceProfile::none()));
    }

    public function testRefusalNamesTheTagsTheNodeLacks(): void
    {
        $refusal = $this->candidate(['worker', 'slots=4'])->refusal(['gpu', 'worker', 'ssd'], ResourceProfile::none());

        $this->assertNotNull($refusal);
        $this->assertSame('n1', $refusal->nodeId);
        $this->assertSame(PlacementRefusalCode::MissingCapabilities, $refusal->reasonCode);
        $this->assertSame(['gpu', 'ssd'], $refusal->missingCapabilities);
        $this->assertSame([], $refusal->shortfalls);
    }

    public function testRefusalOfAnEmptyDeclarationIsNoDeclaredCapacity(): void
    {
        $refusal = $this->candidate(['worker'])->refusal(['worker'], ResourceProfile::none());

        $this->assertNotNull($refusal);
        $this->assertSame(PlacementRefusalCode::NoDeclaredCapacity, $refusal->reasonCode);
        $this->assertSame('n1', $refusal->nodeId);
        $this->assertSame([], $refusal->missingCapabilities);
        $this->assertSame([], $refusal->shortfalls);
    }

    public function testRefusalOfANodeWithRamButNoSlotsIsAShortSlot(): void
    {
        $refusal = $this->candidate(['worker', 'ram=10'])->refusal(['worker'], ResourceProfile::none());

        $this->assertNotNull($refusal);
        $this->assertSame(
            PlacementRefusalCode::InsufficientCapacity,
            $refusal->reasonCode,
            'Declaring ram is a declaration: the node is short of the slot, not undeclared',
        );
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $refusal->shortfalls);
        $this->assertSame([], $refusal->missingCapabilities);
    }

    public function testRefusalNamesEveryShortResourceAtTheEffectiveCost(): void
    {
        $refusal = $this->candidate(['ram=10', 'slots=2'], ['ram' => 9.0, 'slots' => 2.0], 2)
            ->refusal([], ResourceProfile::costs(['ram' => 2.0]));

        $this->assertNotNull($refusal);
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $refusal->reasonCode);
        $this->assertSame(
            ['ram' => ['cost' => 2.0, 'free' => 1.0], 'slots' => ['cost' => 1.0, 'free' => 0.0]],
            $refusal->shortfalls,
        );
    }

    public function testRefusalChecksTheGatesInOrder(): void
    {
        $this->assertSame(
            PlacementRefusalCode::MissingCapabilities,
            $this->candidate([])->refusal(['gpu'], ResourceProfile::costs(['ram' => 2.0]))?->reasonCode,
            'A missing tag is named before the empty declaration and the shortfall',
        );
        $this->assertSame(
            PlacementRefusalCode::NoDeclaredCapacity,
            $this->candidate(['gpu'])->refusal(['gpu'], ResourceProfile::costs(['ram' => 2.0]))?->reasonCode,
            'An empty declaration is named before the shortfall it implies',
        );
        $this->assertSame(
            PlacementRefusalCode::InsufficientCapacity,
            $this->candidate(['gpu', 'ram=1'])->refusal(['gpu'], ResourceProfile::costs(['ram' => 2.0]))?->reasonCode,
        );
    }

    public function testRefusalIsNullExactlyWhenTheNodeAccepts(): void
    {
        $fits = $this->candidate(['gpu', 'ram=10', 'slots=1']);
        $full = $this->candidate(['gpu', 'ram=10', 'slots=1'], ['slots' => 1.0], 1);

        $this->assertNull($fits->refusal(['gpu'], ResourceProfile::costs(['ram' => 10.0])));
        $this->assertTrue($fits->accepts(['gpu'], ResourceProfile::costs(['ram' => 10.0])));
        $this->assertNotNull($full->refusal(['gpu'], ResourceProfile::none()));
        $this->assertFalse($full->accepts(['gpu'], ResourceProfile::none()));
    }

    /**
     * Builds a follower candidate from advertised tags and held capacity.
     *
     * @param list<string> $tags Advertised capability tags
     * @param array<string, float> $used Held capacity keyed by resource name
     * @param int $hosted Live placements on the node
     * @return PlacementCandidate Candidate
     */
    private function candidate(array $tags, array $used = [], int $hosted = 0): PlacementCandidate
    {
        return new PlacementCandidate('n1', NodeCapacities::fromTags($tags), $used, $hosted, false);
    }
}

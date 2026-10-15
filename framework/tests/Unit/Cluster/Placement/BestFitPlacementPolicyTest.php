<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\PlacementCandidate;
use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default best-fit node-selection policy (HIL-182, HIL-448): the gate over
 * tags, declared capacity and free room, and the ranking chain — load after placement, the
 * leader last among equals, head count, total capacity, id. Every cost is ranked as placement
 * charges it, with its mandatory slot (HIL-447), so a node that must stay a candidate declares
 * `slots`, and what a node holds counts one slot per live placement.
 */
final class BestFitPlacementPolicyTest extends TestCase
{
    private BestFitPlacementPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BestFitPlacementPolicy();
    }

    public function testNoCandidatesSelectsNothing(): void
    {
        $this->assertNull($this->policy->selectNode([], ResourceProfile::none(), []));
    }

    public function testRequiredTagFiltersOutNodesThatLackIt(): void
    {
        $candidates = $this->candidates(
            $this->candidate('plain', ['cpu=99', 'slots=64']),
            $this->candidate('gpu-node', ['gpu', 'cpu=1', 'slots=1']),
        );

        $this->assertSame(
            'gpu-node',
            $this->policy->selectNode(['gpu'], ResourceProfile::none(), $candidates),
            'A far stronger node without the required tag is still ineligible',
        );
    }

    public function testSpreadIsProportionalToDeclaredCapacity(): void
    {
        // Seven agents of ram=2 over ram=10 and ram=4, each placed where the policy says: the
        // nodes fill to equal shares, so the split is 5/2 — not 4/3 as a head count would give.
        // Each also holds its slot; slots=64 is far from full, so ram decides every share.
        $cost = ResourceProfile::costs(['ram' => 2.0]);
        $used = ['s1' => 0.0, 's2' => 0.0];
        $hosted = ['s1' => 0, 's2' => 0];
        $picks = [];
        for ($i = 0; $i < 7; $i++) {
            $chosen = $this->policy->selectNode([], $cost, $this->candidates(
                $this->candidate('s1', ['worker', 'ram=10', 'slots=64'], ['ram' => $used['s1'], 'slots' => (float)$hosted['s1']], $hosted['s1']),
                $this->candidate('s2', ['worker', 'ram=4', 'slots=64'], ['ram' => $used['s2'], 'slots' => (float)$hosted['s2']], $hosted['s2']),
            ));
            $this->assertNotNull($chosen);
            $picks[] = $chosen;
            $used[$chosen] += 2.0;
            $hosted[$chosen]++;
        }

        $this->assertSame(['s1', 's1', 's2', 's1', 's1', 's2', 's1'], $picks);
        $this->assertSame(['s1' => 5, 's2' => 2], $hosted);
        $this->assertNull(
            $this->policy->selectNode([], $cost, $this->candidates(
                $this->candidate('s1', ['worker', 'ram=10', 'slots=64'], ['ram' => $used['s1'], 'slots' => (float)$hosted['s1']], $hosted['s1']),
                $this->candidate('s2', ['worker', 'ram=4', 'slots=64'], ['ram' => $used['s2'], 'slots' => (float)$hosted['s2']], $hosted['s2']),
            )),
            'With both nodes full the eighth agent has nowhere to go',
        );
    }

    public function testAnExhaustedNodeIsNotChosenHoweverFewItRuns(): void
    {
        $candidates = $this->candidates(
            $this->candidate('full', ['ram=4', 'slots=64'], ['ram' => 4.0, 'slots' => 1.0], 1),
            $this->candidate('busy', ['ram=100', 'slots=64'], ['ram' => 60.0, 'slots' => 30.0], 30),
        );

        $this->assertSame('busy', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 2.0]), $candidates));
    }

    public function testACostlessAgentIsSpreadByItsSlotShare(): void
    {
        $candidates = $this->candidates(
            $this->candidate('node-a', ['worker', 'ram=64', 'slots=8'], ['ram' => 0.0, 'slots' => 3.0], 3),
            $this->candidate('node-b', ['worker', 'ram=2', 'slots=8'], ['ram' => 2.0, 'slots' => 1.0], 1),
        );

        $this->assertSame(
            'node-b',
            $this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates),
            'An agent with no project cost goes where the slots are least used, even onto a node whose ram is full',
        );
    }

    public function testACostlessAgentNeedsAFreeSlot(): void
    {
        $this->assertNull(
            $this->policy->selectNode([], ResourceProfile::none(), $this->candidates($this->candidate('ram-only', ['ram=10']))),
            'Declaring ram without slots leaves no room for any placed agent',
        );
        $this->assertNull($this->policy->selectNode([], ResourceProfile::none(), $this->candidates($this->candidate('zero', ['slots=0']))));
        $this->assertSame(
            'one',
            $this->policy->selectNode([], ResourceProfile::none(), $this->candidates(
                $this->candidate('ram-only', ['ram=10']),
                $this->candidate('one', ['slots=1']),
            )),
        );
    }

    public function testCostlessAgentsFillTheSlotsInProportion(): void
    {
        // Twelve agents with no project cost over slots=8 and slots=4: they are counted, so the
        // split follows the slots, 8/4, and the thirteenth finds no slot anywhere.
        $hosted = ['s1' => 0, 's2' => 0];
        for ($i = 0; $i < 12; $i++) {
            $chosen = $this->policy->selectNode([], ResourceProfile::none(), $this->candidates(
                $this->candidate('s1', ['slots=8'], ['slots' => (float)$hosted['s1']], $hosted['s1']),
                $this->candidate('s2', ['slots=4'], ['slots' => (float)$hosted['s2']], $hosted['s2']),
            ));
            $this->assertNotNull($chosen);
            $hosted[$chosen]++;
        }

        $this->assertSame(['s1' => 8, 's2' => 4], $hosted);
        $this->assertNull($this->policy->selectNode([], ResourceProfile::none(), $this->candidates(
            $this->candidate('s1', ['slots=8'], ['slots' => 8.0], 8),
            $this->candidate('s2', ['slots=4'], ['slots' => 4.0], 4),
        )));
    }

    public function testANodeWithoutDeclaredCapacityIsNeverChosen(): void
    {
        $candidates = $this->candidates($this->candidate('bare', ['worker']));

        $this->assertNull($this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates));
        $this->assertNull($this->policy->selectNode([], ResourceProfile::none(), $candidates));
    }

    public function testTheLeaderIsLastAmongEquals(): void
    {
        // Equal slot shares after placement, 1/10 against 6/60: the follower wins although it
        // already runs five agents to the leader's none.
        $candidates = $this->candidates(
            $this->candidate('leader', ['slots=10'], [], 0, true),
            $this->candidate('follower', ['slots=60'], ['slots' => 5.0], 5),
        );

        $this->assertSame(
            'follower',
            $this->policy->selectNode([], ResourceProfile::none(), $candidates),
            'The leader yields right after the load, ahead of the head count',
        );
    }

    public function testTheLeaderTakesCostlessWorkOnALowerSlotShare(): void
    {
        $candidates = $this->candidates(
            $this->candidate('leader', ['slots=10'], [], 0, true),
            $this->candidate('follower', ['slots=10'], ['slots' => 5.0], 5),
        );

        $this->assertSame(
            'leader',
            $this->policy->selectNode([], ResourceProfile::none(), $candidates),
            'Last among equals is not a bar: the slot share is not equal, and the leader holds the smaller one',
        );
    }

    public function testTheLeaderTakesTheWorkWhenItIsTheOnlyFit(): void
    {
        $candidates = $this->candidates(
            $this->candidate('leader', ['ram=10', 'slots=64'], [], 0, true),
            $this->candidate('follower', ['ram=10', 'slots=64'], ['ram' => 10.0, 'slots' => 5.0], 5),
        );

        $this->assertSame('leader', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 1.0]), $candidates));
    }

    public function testTheLeaderStillWinsOnALowerLoad(): void
    {
        $candidates = $this->candidates(
            $this->candidate('leader', ['ram=10', 'slots=64'], [], 0, true),
            $this->candidate('follower', ['ram=10', 'slots=64'], ['ram' => 5.0, 'slots' => 1.0], 1),
        );

        $this->assertSame('leader', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 1.0]), $candidates));
    }

    public function testAHeavyAgentGoesToTheStrongNode(): void
    {
        $candidates = $this->candidates(
            $this->candidate('small', ['ram=8', 'slots=64']),
            $this->candidate('big', ['ram=64', 'slots=64']),
        );

        $this->assertSame('big', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 6.0]), $candidates));
    }

    public function testEqualSharesTieWithinTolerance(): void
    {
        // (0.1 + 0.2) / 1 and (2.8 + 0.2) / 10 are the same share, but the first one computes to
        // 0.30000000000000004: without the tolerance float noise would pick node-b, and the head
        // count would never be asked.
        $candidates = $this->candidates(
            $this->candidate('node-a', ['ram=1', 'slots=64'], ['ram' => 0.1], 0),
            $this->candidate('node-b', ['ram=10', 'slots=64'], ['ram' => 2.8, 'slots' => 3.0], 3),
        );

        $this->assertSame('node-a', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 0.2]), $candidates));
    }

    public function testALowerLoadOutranksTheHeadCount(): void
    {
        $candidates = $this->candidates(
            $this->candidate('node-a', ['ram=5', 'slots=64'], ['ram' => 2.0], 0),
            $this->candidate('node-b', ['ram=10', 'slots=64'], ['ram' => 2.0, 'slots' => 4.0], 4),
        );

        $this->assertSame('node-b', $this->policy->selectNode([], ResourceProfile::costs(['ram' => 1.0]), $candidates));
    }

    public function testATieFallsToTheGreaterTotalCapacityThenTheSmallerId(): void
    {
        $this->assertSame('strong', $this->policy->selectNode([], ResourceProfile::none(), $this->candidates(
            $this->candidate('weak', ['cpu=2', 'slots=4']),
            $this->candidate('strong', ['cpu=16', 'slots=4']),
        )));
        $this->assertSame('node-a', $this->policy->selectNode([], ResourceProfile::none(), $this->candidates(
            $this->candidate('node-b', ['cpu=4', 'slots=4']),
            $this->candidate('node-a', ['cpu=4', 'slots=4']),
        )));
    }

    /**
     * Builds a candidate.
     *
     * @param string $nodeId Node id
     * @param list<string> $tags Advertised capability tags
     * @param array<string, float> $used Held capacity keyed by resource name
     * @param int $hosted Live placements on the node
     * @param bool $isLeader Whether the node leads
     * @return PlacementCandidate Candidate
     */
    private function candidate(string $nodeId, array $tags, array $used = [], int $hosted = 0, bool $isLeader = false): PlacementCandidate
    {
        return new PlacementCandidate($nodeId, NodeCapacities::fromTags($tags), $used, $hosted, $isLeader);
    }

    /**
     * Keys candidates by node id.
     *
     * @param PlacementCandidate ...$candidates Candidates
     * @return array<string, PlacementCandidate> Candidates keyed by node id
     */
    private function candidates(PlacementCandidate ...$candidates): array
    {
        $keyed = [];
        foreach ($candidates as $candidate) {
            $keyed[$candidate->nodeId] = $candidate;
        }

        return $keyed;
    }
}

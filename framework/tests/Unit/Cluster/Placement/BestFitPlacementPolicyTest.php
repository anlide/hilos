<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default best-fit node-selection policy (HIL-182): the hard gate (required
 * tags plus capacity minimums) and the soft ranking among the nodes that clear it.
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
        $candidates = [
            'plain' => NodeCapacities::fromTags(['cpu=99']),
            'gpu-node' => NodeCapacities::fromTags(['gpu', 'cpu=1']),
        ];

        $this->assertSame(
            'gpu-node',
            $this->policy->selectNode(['gpu'], ResourceProfile::none(), $candidates),
            'A far stronger node without the required tag is still ineligible',
        );
    }

    public function testCapacityMinimumFiltersOutNodesBelowTheFloor(): void
    {
        $candidates = [
            'weak' => NodeCapacities::fromTags(['worker', 'ram=8']),
            'strong' => NodeCapacities::fromTags(['worker', 'ram=64']),
        ];
        $profile = ResourceProfile::create(minimums: ['ram' => 32.0]);

        $this->assertSame('strong', $this->policy->selectNode(['worker'], $profile, $candidates));
    }

    public function testUnmetMinimumAcrossAllNodesSelectsNothing(): void
    {
        $candidates = ['a' => NodeCapacities::fromTags(['worker', 'ram=8'])];
        $profile = ResourceProfile::create(minimums: ['ram' => 32.0]);

        $this->assertNull($this->policy->selectNode(['worker'], $profile, $candidates));
    }

    public function testSoftPreferenceRanksTowardTheStrongerNode(): void
    {
        $candidates = [
            'small' => NodeCapacities::fromTags(['worker', 'gpu=1']),
            'big' => NodeCapacities::fromTags(['worker', 'gpu=4']),
        ];
        $profile = ResourceProfile::create(preferences: ['gpu' => 1.0]);

        $this->assertSame('big', $this->policy->selectNode(['worker'], $profile, $candidates));
    }

    public function testEmptyProfileFallsThroughToTheStrongestNode(): void
    {
        // No preference to rank on, so the tiebreak picks the greater total capacity.
        $candidates = [
            'weak' => NodeCapacities::fromTags(['worker', 'cpu=2']),
            'strong' => NodeCapacities::fromTags(['worker', 'cpu=16']),
        ];

        $this->assertSame('strong', $this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates));
    }

    public function testEqualNodesBreakTieOnLexicographicId(): void
    {
        $candidates = [
            'node-b' => NodeCapacities::fromTags(['worker', 'cpu=4']),
            'node-a' => NodeCapacities::fromTags(['worker', 'cpu=4']),
        ];

        $this->assertSame(
            'node-a',
            $this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates),
            'Fully equal nodes resolve to the smallest id so the pick is deterministic',
        );
    }

    public function testEqualNodesBreakTieTowardTheLeastLoadedOne(): void
    {
        // Declared capacity never drops as agents land, so without occupancy a fleet of
        // identical agents would pile onto whichever node won the first pick.
        $candidates = [
            'node-a' => NodeCapacities::fromTags(['worker', 'cpu=4']),
            'node-b' => NodeCapacities::fromTags(['worker', 'cpu=4']),
        ];

        $this->assertSame(
            'node-b',
            $this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates, ['node-a' => 3, 'node-b' => 1]),
            'The node already running fewer agents takes the next one',
        );
    }

    public function testAStrongerNodeStillLosesToAnIdleWeakerOne(): void
    {
        // Load outranks raw strength, but only among nodes the profile scores equally.
        $candidates = [
            'busy-strong' => NodeCapacities::fromTags(['worker', 'cpu=64']),
            'idle-weak' => NodeCapacities::fromTags(['worker', 'cpu=2']),
        ];

        $this->assertSame(
            'idle-weak',
            $this->policy->selectNode(['worker'], ResourceProfile::none(), $candidates, ['busy-strong' => 5]),
        );
    }

    public function testPreferenceScoreOutranksLoad(): void
    {
        // A declared numeric demand is a hard preference, not a hint: it wins over occupancy.
        $candidates = [
            'gpu-rich' => NodeCapacities::fromTags(['worker', 'gpu=8']),
            'gpu-poor' => NodeCapacities::fromTags(['worker', 'gpu=1']),
        ];
        $profile = ResourceProfile::create(preferences: ['gpu' => 1.0]);

        $this->assertSame(
            'gpu-rich',
            $this->policy->selectNode(['worker'], $profile, $candidates, ['gpu-rich' => 9, 'gpu-poor' => 0]),
        );
    }
}

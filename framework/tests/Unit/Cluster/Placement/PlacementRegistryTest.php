<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementRegistry;
use Hilos\Cluster\Placement\PlacementState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the leader's placement view and its revision (HIL-447): a record replaces the one
 * before it only when it says something else, the diagnosis of a failure included, and equality
 * is by value, never by object identity.
 */
final class PlacementRegistryTest extends TestCase
{
    public function testAFailureForAnotherReasonReplacesTheFirst(): void
    {
        $registry = new PlacementRegistry();
        $registry->put($this->failed('node is not linked', []));
        $revision = $registry->revision();

        $second = $this->failed('no room', [PlacementRefusal::insufficientCapacity('s1', ['slots' => ['cost' => 1.0, 'free' => 0.0]])]);
        $registry->put($second);

        $this->assertSame($revision + 1, $registry->revision(), 'A second Failed with another reason is news');
        $this->assertSame($second, $registry->get('worker:7'));
    }

    public function testAFailureWithOtherRefusalsReplacesTheFirst(): void
    {
        $registry = new PlacementRegistry();
        $registry->put($this->failed('no room', [PlacementRefusal::insufficientCapacity('s1', ['slots' => ['cost' => 1.0, 'free' => 0.0]])]));
        $revision = $registry->revision();

        $registry->put($this->failed('no room', [PlacementRefusal::insufficientCapacity('s1', ['ram' => ['cost' => 2.0, 'free' => 1.0]])]));

        $this->assertSame($revision + 1, $registry->revision(), 'The same text over other refusals is a different failure');
        $record = $registry->get('worker:7');
        $this->assertNotNull($record);
        $this->assertSame(['ram'], array_keys($record->refusals[0]->shortfalls));
    }

    public function testTheSameStructureBuiltApartChangesNothing(): void
    {
        $registry = new PlacementRegistry();
        $first = $this->failed('no room', [
            PlacementRefusal::insufficientCapacity('s1', ['slots' => ['cost' => 1.0, 'free' => 0.0]]),
            PlacementRefusal::unmetCapabilities('s2', ['gpu']),
        ]);
        $registry->put($first);
        $revision = $registry->revision();

        $registry->put($this->failed('no room', [
            PlacementRefusal::insufficientCapacity('s1', ['slots' => ['cost' => 1.0, 'free' => 0.0]]),
            PlacementRefusal::unmetCapabilities('s2', ['gpu']),
        ]));

        $this->assertSame($revision, $registry->revision(), 'Equal by value is equal, whatever the objects');
        $this->assertSame($first, $registry->get('worker:7'), 'The record held is the first one');
    }

    public function testARecordWithoutDiagnosisStillComparesOnNodeAndState(): void
    {
        $registry = new PlacementRegistry();
        $registry->put(new PlacementRecord('worker', '7', 's1', PlacementState::Started));
        $revision = $registry->revision();

        $registry->put(new PlacementRecord('worker', '7', 's1', PlacementState::Started));
        $this->assertSame($revision, $registry->revision());

        $registry->put(new PlacementRecord('worker', '7', 's2', PlacementState::Started));
        $this->assertSame($revision + 1, $registry->revision());
    }

    public function testWithStateKeepsTheDiagnosisOnlyWhileNothingRuns(): void
    {
        $refusals = [PlacementRefusal::noDeclaredCapacity('s1')];
        $failed = $this->failed('no capacity', $refusals);

        $unplaced = $failed->withState(PlacementState::Unplaced);
        $this->assertSame('no capacity', $unplaced->reason);
        $this->assertSame($refusals, $unplaced->refusals);

        foreach ([PlacementState::Placing, PlacementState::Started, PlacementState::Refused] as $state) {
            $moved = $failed->withState($state);
            $this->assertNull($moved->reason, "{$state->value} carries no reason");
            $this->assertSame([], $moved->refusals, "{$state->value} carries no refusals");
        }
    }

    /**
     * Builds a failed record of the same agent on the same node with the given diagnosis.
     *
     * @param string $reason Failure text
     * @param list<PlacementRefusal> $refusals Gate refusals
     * @return PlacementRecord Failed record
     */
    private function failed(string $reason, array $refusals): PlacementRecord
    {
        return new PlacementRecord('worker', '7', 's1', PlacementState::Failed, $reason, $refusals);
    }
}

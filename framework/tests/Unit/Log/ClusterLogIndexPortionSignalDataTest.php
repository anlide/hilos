<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the wire the cluster picture travels on (HIL-756).
 *
 * The frame the aggregator answers a subscriber with, asked the same question the node index is
 * asked one level down: does a slot survive the round trip whole? Anything lost here is lost from
 * the screen an administrator is looking at, and read there as a fact about the disks rather than
 * as a transport fault.
 *
 * {@see ClusterLogIndexPortionSignalData::$snapshot} is checked apart from the slots because it is
 * the one field with no equivalent below it: it is what tells the receiver to replace its picture
 * rather than lay the frame over it, and a frame that lost it would quietly stop nodes from ever
 * disappearing.
 */
final class ClusterLogIndexPortionSignalDataTest extends TestCase
{
    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /**
     * A snapshot of two nodes, with every kind of field the index below carries: the `daemon`
     * stream class and the null of an unfilled day window are the two most likely to be flattened
     * on the way through.
     */
    public function testASnapshotOfTwoNodesSurvivesTheRoundTrip(): void
    {
        $slots = [
            new ClusterLogNodeSlot('node-1', $this->fullIndex('node-1'), self::T0 - 5),
            new ClusterLogNodeSlot('node-2', $this->fullIndex('node-2'), self::T0 - 1),
        ];

        $restored = $this->roundTrip(ClusterLogIndexPortionSignalData::ofSlots($slots, true));

        $this->assertTrue($restored->snapshot);
        $this->assertCount(2, $restored->nodes);
        $this->assertSame('node-1', $restored->nodes[0]->nodeId);
        $this->assertSame(self::T0 - 5, $restored->nodes[0]->receivedAt);
        $this->assertEquals($slots[0]->index, $restored->nodes[0]->index);
        $this->assertSame('node-2', $restored->nodes[1]->nodeId);
        $this->assertSame(self::T0 - 1, $restored->nodes[1]->receivedAt);
        $this->assertEquals($slots[1]->index, $restored->nodes[1]->index);
    }

    /**
     * A single-node installation names itself with nothing at all, and the null has to arrive as a
     * null rather than as the empty string a slot is keyed by: the two are the same key on the
     * receiver, but only one of them is a node id a configuration could produce.
     */
    public function testASlotWithoutANodeIdSurvivesTheRoundTripWithoutTakingAnotherSlot(): void
    {
        $slots = [
            new ClusterLogNodeSlot(null, $this->fullIndex(null), self::T0),
            new ClusterLogNodeSlot('node-2', $this->fullIndex('node-2'), self::T0),
        ];

        $restored = $this->roundTrip(ClusterLogIndexPortionSignalData::ofSlots($slots, true));

        $this->assertCount(2, $restored->nodes);
        $this->assertNull($restored->nodes[0]->nodeId);
        $this->assertSame('node-2', $restored->nodes[1]->nodeId);
        $this->assertCount(2, $restored->toIndex()->nodes());
    }

    /**
     * The same slots sent as a portion rather than as a snapshot: identical on every field but the
     * one that decides whether the receiver keeps what it already had.
     */
    public function testAPortionIsToldApartFromASnapshotByItsFlag(): void
    {
        $slots = [new ClusterLogNodeSlot('node-1', $this->fullIndex('node-1'), self::T0)];

        $portion = $this->roundTrip(ClusterLogIndexPortionSignalData::ofSlots($slots, false));
        $snapshot = $this->roundTrip(ClusterLogIndexPortionSignalData::ofSlots($slots, true));

        $this->assertFalse($portion->snapshot);
        $this->assertTrue($snapshot->snapshot);
        $this->assertEquals($portion->toSlots(), $snapshot->toSlots());
    }

    /**
     * The payload really travels as JSON, so a round trip that only holds inside PHP is worth
     * nothing: an empty list of nodes must come back as an empty list and not as an empty map.
     */
    public function testTheRoundTripHoldsThroughJson(): void
    {
        $frame = ClusterLogIndexPortionSignalData::ofSlots([], true);

        $encoded = json_decode($frame->toJson(), true);
        $this->assertIsArray($encoded);
        $restored = ClusterLogIndexPortionSignalData::fromArray($encoded);

        $this->assertTrue($restored->snapshot);
        $this->assertSame([], $restored->toSlots());
    }

    /**
     * Whether the frame replaces the picture cannot be guessed: a portion read as a snapshot wipes
     * every node it does not mention, and a snapshot read as a portion keeps a node that is gone.
     */
    public function testAPayloadMissingTheSnapshotFlagIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[ClusterLogIndexPortionSignalData::snapshot]);

        $this->expectException(InvalidFormatException::class);
        ClusterLogIndexPortionSignalData::fromArray($payload);
    }

    public function testAPayloadMissingTheArrivalTimeOfASlotIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[ClusterLogIndexPortionSignalData::nodes][0][ClusterLogIndexPortionSignalData::receivedAt]);

        $this->expectException(InvalidFormatException::class);
        ClusterLogIndexPortionSignalData::fromArray($payload);
    }

    public function testANodeThatIsNotAnObjectIsRefused(): void
    {
        $payload = $this->payload();
        $payload[ClusterLogIndexPortionSignalData::nodes] = ['node-1'];

        $this->expectException(InvalidFormatException::class);
        ClusterLogIndexPortionSignalData::fromArray($payload);
    }

    /**
     * The index inside a slot is read by the layout that wrote it, so a frame carrying a broken one
     * is refused here too rather than filed as a node with nothing in it.
     */
    public function testASlotCarryingABrokenIndexIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[ClusterLogIndexPortionSignalData::nodes][0][ClusterLogIndexPortionSignalData::index]['sampledAt']);

        $this->expectException(InvalidFormatException::class);
        ClusterLogIndexPortionSignalData::fromArray($payload);
    }

    /**
     * @param ClusterLogIndexPortionSignalData $frame Frame the aggregator built
     * @return ClusterLogIndexPortionSignalData The same frame after a trip through the wire and back
     * @throws InvalidFormatException When the payload this test built refuses to be read back
     */
    private function roundTrip(ClusterLogIndexPortionSignalData $frame): ClusterLogIndexPortionSignalData
    {
        return ClusterLogIndexPortionSignalData::fromArray($frame->toArray());
    }

    /**
     * @return array<string, mixed> Wire form of a valid one-slot frame, the base every refusal test breaks
     */
    private function payload(): array
    {
        return ClusterLogIndexPortionSignalData::ofSlots(
            [new ClusterLogNodeSlot('node-1', $this->fullIndex('node-1'), self::T0)],
            true,
        )->toArray();
    }

    /**
     * @param ?string $nodeId Node the index was measured on, or null in a single-node installation
     * @return NodeLogIndex Index with every kind of row and an unfilled day window in it
     */
    private function fullIndex(?string $nodeId): NodeLogIndex
    {
        return new NodeLogIndex(
            nodeId: $nodeId,
            available: true,
            sampledAt: self::T0,
            batches: [new LogBatchSummary(self::T0 - 3600, 1, 10, 2, 20, 3, 30, 4, 40)],
            keys: [
                new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [self::T0 - 3600], 100),
                new LogKeySummary('daemon.log', LogKeySummary::CLASS_DAEMON, false, [], 200),
            ],
            workers: [new LogWorkerSummary('worker-0.log', false, true, [], 300)],
            growthBytesPerDay: ['agent-a.log' => 17, 'daemon.log' => null],
        );
    }
}

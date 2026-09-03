<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the wire the node index travels on (HIL-755).
 *
 * One question, asked of every field: does an index survive the round trip to the aggregator
 * unchanged? The frame is the WHOLE index, so anything this loses is lost from the cluster picture
 * until the leaf that reads it is written — and then read as a fact about the disk rather than as a
 * transport fault.
 *
 * The refusals are here for the same reason: a payload that arrived broken must not become an index
 * full of zeros, because a zero on the overview claims a measurement nobody took.
 */
final class NodeLogIndexSignalDataTest extends TestCase
{
    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /**
     * Every field of a fully populated index, the fourth stream class and an unfilled day window
     * included: `daemon` and the null are the two the wire is most likely to flatten.
     */
    public function testAFullIndexSurvivesTheRoundTrip(): void
    {
        $index = new NodeLogIndex(
            nodeId: 'node-1',
            available: true,
            sampledAt: self::T0,
            batches: [
                new LogBatchSummary(self::T0 - 7200, 1, 10, 2, 20, 3, 30, 4, 40, self::T0 - 60),
                new LogBatchSummary(self::T0 - 3600, 5, 50, 6, 60, 7, 70, 8, 80, null, true),
            ],
            keys: [
                new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [self::T0 - 7200], 100),
                new LogKeySummary('daemon.log', LogKeySummary::CLASS_DAEMON, false, [self::T0 - 3600], 200),
                new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], 300),
            ],
            workers: [
                new LogWorkerSummary('worker-0.log', false, true, [], 300),
                new LogWorkerSummary('worker-monopolistic-1.log', true, false, [self::T0 - 3600], 400),
            ],
            growthBytesPerDay: ['agent-a.log' => 17, 'daemon.log' => null, 'worker-0.log' => 0],
            dueBatchTimestamps: [self::T0 - 7200],
        );

        $restored = $this->roundTrip($index);

        $this->assertSame('node-1', $restored->nodeId);
        $this->assertTrue($restored->available);
        $this->assertSame(self::T0, $restored->sampledAt);
        $this->assertEquals($index->batches, $restored->batches);
        $this->assertEquals($index->keys, $restored->keys);
        $this->assertEquals($index->workers, $restored->workers);
        $this->assertSame(['agent-a.log' => 17, 'daemon.log' => null, 'worker-0.log' => 0], $restored->growthBytesPerDay);
        $this->assertSame([self::T0 - 7200], $restored->dueBatchTimestamps);
    }

    /**
     * The verdict is the one key here a node may honestly omit: during a rolling upgrade the node
     * still running the previous build reports an index without it (HIL-871). Refusing the frame
     * over that would take a whole node's history off the screen, so absence reads as "recommends
     * nothing" - the same answer a node with a fresh archive gives.
     */
    public function testAPayloadWithoutTheVerdictReadsAsRecommendingNothing(): void
    {
        $payload = $this->payload();
        unset($payload[NodeLogIndexSignalData::dueBatchTimestamps]);

        $restored = NodeLogIndexSignalData::fromArray($payload)->toIndex();

        $this->assertSame([], $restored->dueBatchTimestamps);
        $this->assertCount(1, $restored->batches);
    }

    /**
     * The other key a node running the previous build honestly omits (HIL-870). A batch nobody
     * said was being carried is a batch sitting in the archive, which is what every batch was
     * before this key existed — so absence reads as false rather than as a broken frame.
     */
    public function testAPayloadWithoutTheCarryingFlagReadsAsArrived(): void
    {
        $payload = $this->payload();
        $rows = $payload[NodeLogIndexSignalData::batches];
        unset($rows[0][NodeLogIndexSignalData::carrying]);
        $payload[NodeLogIndexSignalData::batches] = $rows;

        $restored = NodeLogIndexSignalData::fromArray($payload)->toIndex();

        $this->assertCount(1, $restored->batches);
        $this->assertFalse($restored->batches[0]->carrying);
    }

    /**
     * A node that has just started, or one that could not read its directory, sends an index with
     * nothing in it — and that is a report, not an absence of one.
     */
    public function testAnEmptyIndexSurvivesTheRoundTrip(): void
    {
        $restored = $this->roundTrip(new NodeLogIndex('node-1', false, self::T0, [], [], [], []));

        $this->assertFalse($restored->available);
        $this->assertSame([], $restored->batches);
        $this->assertSame([], $restored->keys);
        $this->assertSame([], $restored->workers);
        $this->assertSame([], $restored->growthBytesPerDay);
    }

    /**
     * A single-node installation names itself with nothing at all, and the null has to arrive as a
     * null: an empty string there would be a node id no configuration can produce.
     */
    public function testAnIndexWithoutANodeIdSurvivesTheRoundTrip(): void
    {
        $restored = $this->roundTrip(new NodeLogIndex(null, true, self::T0, [], [], [], []));

        $this->assertNull($restored->nodeId);
    }

    /**
     * The payload really travels as JSON, so the round trip is worth nothing if it only holds
     * inside PHP: an empty map must not come back as an empty list, and vice versa.
     */
    public function testTheRoundTripHoldsThroughJson(): void
    {
        $index = new NodeLogIndex(
            nodeId: null,
            available: true,
            sampledAt: self::T0,
            batches: [],
            keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [self::T0], 100)],
            workers: [],
            growthBytesPerDay: ['agent-a.log' => null],
        );

        $encoded = json_decode(NodeLogIndexSignalData::fromIndex($index)->toJson(), true);
        $this->assertIsArray($encoded);
        $restored = NodeLogIndexSignalData::fromArray($encoded)->toIndex();

        $this->assertEquals($index->keys, $restored->keys);
        $this->assertSame(['agent-a.log' => null], $restored->growthBytesPerDay);
    }

    public function testAPayloadMissingARequiredKeyIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[NodeLogIndexSignalData::sampledAt]);

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    /**
     * `available` false and an absent `available` are different things, and only the reader can
     * tell them apart: filling it in would report a directory as unreadable that nobody asked about.
     */
    public function testAPayloadMissingTheAvailabilityFlagIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[NodeLogIndexSignalData::available]);

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    public function testARowThatIsNotAnObjectIsRefused(): void
    {
        $payload = $this->payload();
        $payload[NodeLogIndexSignalData::keys] = ['agent-a.log'];

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    public function testARowMissingAFieldIsRefused(): void
    {
        $payload = $this->payload();
        unset($payload[NodeLogIndexSignalData::batches][0][NodeLogIndexSignalData::daemonBytes]);

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    /**
     * A day growth is a byte count or the null that says the window is short; a string is neither,
     * and reading it as a number would put an invented figure on the overview.
     */
    public function testADayGrowthThatIsNeitherNumberNorNullIsRefused(): void
    {
        $payload = $this->payload();
        $payload[NodeLogIndexSignalData::growthBytesPerDay]['agent-a.log'] = 'lots';

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    /**
     * Allowed to be absent is not allowed to be nonsense: a verdict that arrives as anything but
     * timestamps would put a badge on the screen against a batch nobody judged.
     */
    public function testAVerdictThatIsNotATimestampIsRefused(): void
    {
        $payload = $this->payload();
        $payload[NodeLogIndexSignalData::dueBatchTimestamps] = ['soon'];

        $this->expectException(InvalidFormatException::class);
        NodeLogIndexSignalData::fromArray($payload);
    }

    /**
     * @param NodeLogIndex $index Index a node holds
     * @return NodeLogIndex The same index after a trip through the payload and back
     * @throws InvalidFormatException When the payload this test built refuses to be read back
     */
    private function roundTrip(NodeLogIndex $index): NodeLogIndex
    {
        return NodeLogIndexSignalData::fromArray(NodeLogIndexSignalData::fromIndex($index)->toArray())->toIndex();
    }

    /**
     * @return array<string, mixed> Wire form of a valid index, the base every refusal test breaks
     */
    private function payload(): array
    {
        return NodeLogIndexSignalData::fromIndex(new NodeLogIndex(
            nodeId: 'node-1',
            available: true,
            sampledAt: self::T0,
            batches: [new LogBatchSummary(self::T0 - 3600, 1, 10, 2, 20, 3, 30, 4, 40)],
            keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [self::T0 - 3600], 100)],
            workers: [new LogWorkerSummary('worker-0.log', false, true, [], 300)],
            growthBytesPerDay: ['agent-a.log' => 17],
            dueBatchTimestamps: [self::T0 - 3600],
        ))->toArray();
    }
}

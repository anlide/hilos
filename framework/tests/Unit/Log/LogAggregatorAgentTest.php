<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\ClusterLogIndex;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cluster log picture the aggregator holds (HIL-754).
 *
 * The agent is driven through {@see LogAggregatorAgent::applyNodeIndex()} and nothing else, which
 * is the whole of its behavior: it has no tick of its own and never opens a directory, so a frame
 * arriving is the only thing that can happen to it. The frames are built by hand here because the
 * transport that will really send them is HIL-755.
 *
 * The leaf exists so this receiver is not born dead the way HIL-379 was, and what these tests hold
 * down is exactly what has no consumer yet: that a frame replaces a slot whole, that two nodes stay
 * two, and that what the cluster does not know is counted rather than swallowed into a zero.
 *
 * No fixture directory and no journal file, unlike {@see LogStoreAgentIndexTest}: this agent reads
 * no disk at all, so there is nothing for a test to lay out for it.
 */
final class LogAggregatorAgentTest extends TestCase
{
    /** Any fixed instant, so a batch timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    public function testAFrameFromAnUnknownNodeOpensASlotForIt(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [$this->key('agent-a.log', 100)]));

        $slot = $agent->clusterIndex()->node('node-1');
        $this->assertNotNull($slot);
        $this->assertSame('node-1', $slot->nodeId);
        $this->assertSame(1, $agent->clusterIndex()->totals()->nodeCount);
    }

    /**
     * A second frame exchanges the slot rather than merging into it, so a key the node no longer
     * has is gone from the picture too — the point of sending the index whole.
     */
    public function testASecondFrameReplacesTheWholeSlot(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [
            $this->key('agent-a.log', 100),
            $this->key('agent-gone.log', 700),
        ]));

        $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [$this->key('agent-a.log', 250)]));

        $totals = $agent->clusterIndex()->totals();
        $this->assertSame([LogKeySummary::CLASS_AGENT => 1], $totals->streamCountByClass);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 250], $totals->bytesByClass);
    }

    /**
     * No frame is ever dropped as stale: frames from one node travel one link of the mesh, which
     * does not reorder them, while judging them by the sender's clock would stick forever the first
     * time that clock was wound back.
     */
    public function testAFrameSampledEarlierIsStillAccepted(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1', sampledAt: self::T0, keys: [$this->key('agent-a.log', 100)]));

        $agent->applyNodeIndex($this->nodeIndex(
            'node-1',
            sampledAt: self::T0 - 3600,
            keys: [$this->key('agent-a.log', 40)],
        ));

        $slot = $agent->clusterIndex()->node('node-1');
        $this->assertNotNull($slot);
        $this->assertSame(self::T0 - 3600, $slot->index->sampledAt);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 40], $agent->clusterIndex()->totals()->bytesByClass);
    }

    /**
     * The same basename on two nodes is two files, rotated and evicted apart, so it counts twice.
     */
    public function testTheSameKeyOnTwoNodesCountsAsTwoStreams(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [$this->key('worker-0.log', 100, LogKeySummary::CLASS_WORKER)]));
        $agent->applyNodeIndex($this->nodeIndex('node-2', keys: [$this->key('worker-0.log', 300, LogKeySummary::CLASS_WORKER)]));

        $totals = $agent->clusterIndex()->totals();
        $this->assertSame(2, $totals->nodeCount);
        $this->assertSame([LogKeySummary::CLASS_WORKER => 2], $totals->streamCountByClass);
        $this->assertSame([LogKeySummary::CLASS_WORKER => 400], $totals->bytesByClass);
    }

    /**
     * A single-node installation has no node id, and the slot it lands in collides with nothing:
     * an empty CLUSTER_NODE_ID is refused as a configuration error, so no real node is ever called
     * the empty string.
     */
    public function testTheSingleNodeInstallationKeepsASlotOfItsOwn(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex(null, keys: [$this->key('agent-a.log', 100)]));
        $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [$this->key('agent-a.log', 500)]));

        $single = $agent->clusterIndex()->node(null);
        $this->assertNotNull($single);
        $this->assertNull($single->nodeId);
        $this->assertSame(100, $single->index->keys[0]->totalBytes);
        $this->assertSame(2, $agent->clusterIndex()->totals()->nodeCount);
    }

    public function testTotalsAddUpWeightBatchesAndTheNewestRotation(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex(
            'node-1',
            batches: [$this->batch(self::T0 - 7200), $this->batch(self::T0 - 3600)],
            keys: [$this->key('agent-a.log', 100), $this->key('worker-0.log', 200, LogKeySummary::CLASS_WORKER)],
            growthBytesPerDay: ['agent-a.log' => 10, 'worker-0.log' => 20],
        ));
        $agent->applyNodeIndex($this->nodeIndex(
            'node-2',
            batches: [$this->batch(self::T0 - 600)],
            keys: [$this->key('agent-a.log', 400)],
            growthBytesPerDay: ['agent-a.log' => 30],
        ));

        $totals = $agent->clusterIndex()->totals();
        $this->assertSame(3, $totals->batchCount);
        $this->assertSame(self::T0 - 600, $totals->lastRotationAt);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 2, LogKeySummary::CLASS_WORKER => 1], $totals->streamCountByClass);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 500, LogKeySummary::CLASS_WORKER => 200], $totals->bytesByClass);
        $this->assertSame(60, $totals->growthBytesPerDay);
        $this->assertSame(0, $totals->keysWithoutGrowthWindow);
    }

    /**
     * A node that could not read its own directory sends an index saying so, with nothing in it.
     * The sums stay honest by naming that node instead of counting its absence as zero.
     */
    public function testAnUnreadableNodeIsCountedRatherThanSwallowed(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex(
            'node-1',
            keys: [$this->key('agent-a.log', 100)],
            growthBytesPerDay: ['agent-a.log' => 10],
        ));

        $agent->applyNodeIndex($this->nodeIndex('node-2', available: false));

        $totals = $agent->clusterIndex()->totals();
        $this->assertSame(2, $totals->nodeCount);
        $this->assertSame(1, $totals->unavailableNodeCount);
        $this->assertSame(100, $totals->bytesByClass[LogKeySummary::CLASS_AGENT]);
    }

    /**
     * Before a day has passed a key has no honest figure, so it is counted as unmeasured; the day
     * total stays null while not one key anywhere can answer, rather than reporting a zero.
     */
    public function testKeysWithoutADayWindowAreCountedAndLeaveTheDayTotalUnknown(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex(
            'node-1',
            keys: [$this->key('agent-a.log', 100), $this->key('agent-b.log', 200)],
            growthBytesPerDay: ['agent-a.log' => null, 'agent-b.log' => null],
        ));

        $totals = $agent->clusterIndex()->totals();
        $this->assertNull($totals->growthBytesPerDay);
        $this->assertSame(2, $totals->keysWithoutGrowthWindow);
    }

    public function testAPartlyMeasuredClusterReportsWhatItKnowsAndCountsTheRest(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex(
            'node-1',
            keys: [$this->key('agent-a.log', 100), $this->key('agent-b.log', 200)],
            growthBytesPerDay: ['agent-a.log' => 70, 'agent-b.log' => null],
        ));

        $totals = $agent->clusterIndex()->totals();
        $this->assertSame(70, $totals->growthBytesPerDay);
        $this->assertSame(1, $totals->keysWithoutGrowthWindow);
    }

    /**
     * An aggregator nobody has reported to yet knows nothing, which is not the same as knowing
     * that everything is zero: the overview draws a blank tile from the null and a "0" from a zero.
     */
    public function testAnEmptyPictureIsUnknownRatherThanZero(): void
    {
        $totals = ClusterLogIndex::empty()->totals();

        $this->assertSame(0, $totals->nodeCount);
        $this->assertSame(0, $totals->unavailableNodeCount);
        $this->assertNull($totals->lastRotationAt);
        $this->assertSame(0, $totals->batchCount);
        $this->assertSame([], $totals->streamCountByClass);
        $this->assertSame([], $totals->bytesByClass);
        $this->assertNull($totals->growthBytesPerDay);
        $this->assertSame(0, $totals->keysWithoutGrowthWindow);
    }

    public function testTheAggregatorStartsEmptyAndStopsWithoutComplaint(): void
    {
        $agent = $this->startedAgent();

        $this->assertSame([], $agent->clusterIndex()->nodes());

        $agent->onStop();

        $this->assertSame(0, $agent->clusterIndex()->totals()->nodeCount);
    }

    /**
     * @return LogAggregatorAgent Agent past its start hook, the state every test begins from
     */
    private function startedAgent(): LogAggregatorAgent
    {
        $agent = new LogAggregatorAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * @param ?string $nodeId Node the frame came from, null for a single-node installation
     * @param bool $available Whether that node could read its log store
     * @param int $sampledAt Unix timestamp the node stamped its walk with
     * @param list<LogBatchSummary> $batches Rotation batches the node reported
     * @param list<LogKeySummary> $keys Log keys the node reported
     * @param array<string, ?int> $growthBytesPerDay Key → day growth, null while its window is short
     * @return NodeLogIndex Frame as a node would send it
     */
    private function nodeIndex(
        ?string $nodeId,
        bool $available = true,
        int $sampledAt = self::T0,
        array $batches = [],
        array $keys = [],
        array $growthBytesPerDay = [],
    ): NodeLogIndex {
        return new NodeLogIndex($nodeId, $available, $sampledAt, $batches, $keys, [], $growthBytesPerDay);
    }

    /**
     * @param string $key File basename
     * @param int $totalBytes Weight of the key, live file and every batch of it together
     * @param string $class Stream class the key belongs to
     * @return LogKeySummary Key summary as a node's walk would project it
     */
    private function key(string $key, int $totalBytes, string $class = LogKeySummary::CLASS_AGENT): LogKeySummary
    {
        return new LogKeySummary($key, $class, true, [], $totalBytes);
    }

    /**
     * @param int $timestamp Unix timestamp of the rotation folder
     * @return LogBatchSummary Batch summary carrying only the timestamp the totals read
     */
    private function batch(int $timestamp): LogBatchSummary
    {
        return new LogBatchSummary($timestamp, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}

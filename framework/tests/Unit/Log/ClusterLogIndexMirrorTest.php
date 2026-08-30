<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use PHPUnit\Framework\TestCase;

/**
 * The log section's copy of the cluster picture, and its count of who is looking (HIL-756).
 *
 * Two questions, and they are separate on purpose. Filing a frame has to keep the difference
 * between a snapshot and a portion, because that is the only thing telling "this is everything"
 * from "this is what changed". And the picture has to outlive the viewers, because a mirror emptied
 * when the last tab closed would flash an empty screen at the next one to open.
 *
 * The third state is the one thing that cannot be got from the picture itself: "no frame has ever
 * arrived" is a different thing to draw than "the aggregator answered and had nothing", and an
 * empty picture standing in for both would make the overview claim a measurement nobody took.
 */
final class ClusterLogIndexMirrorTest extends TestCase
{
    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    protected function setUp(): void
    {
        $this->emptyTheMirror();
    }

    protected function tearDown(): void
    {
        $this->emptyTheMirror();

        parent::tearDown();
    }

    public function testAMirrorThatHasHeardNothingKnowsNothing(): void
    {
        $this->assertFalse(ClusterLogIndexMirror::known());
        $this->assertNull(ClusterLogIndexMirror::index());
    }

    /**
     * An aggregator with no node to report still answers, and the answer is a picture: from here on
     * the overview draws figures rather than the wait for a first frame.
     */
    public function testAnEmptySnapshotIsStillAPicture(): void
    {
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots([], true));

        $this->assertTrue(ClusterLogIndexMirror::known());
        $this->assertSame([], ClusterLogIndexMirror::index()?->nodes());
    }

    public function testASnapshotPutsTheWholePictureIn(): void
    {
        ClusterLogIndexMirror::applyPortion($this->snapshot($this->slot('node-1', 100), $this->slot('node-2', 300)));

        $index = ClusterLogIndexMirror::index();
        $this->assertNotNull($index);
        $this->assertSame(2, $index->totals()->nodeCount);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 400], $index->totals()->bytesByClass);
    }

    /**
     * A portion is laid over what is there: the slot it names is exchanged whole, and the node it
     * says nothing about keeps exactly what it had.
     */
    public function testAPortionReplacesItsOwnSlotAndLeavesTheNeighborAlone(): void
    {
        ClusterLogIndexMirror::applyPortion($this->snapshot($this->slot('node-1', 100), $this->slot('node-2', 300)));

        ClusterLogIndexMirror::applyPortion($this->portion($this->slot('node-1', 250)));

        $index = ClusterLogIndexMirror::index();
        $this->assertSame(2, $index?->totals()->nodeCount);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 550], $index?->totals()->bytesByClass);
    }

    /**
     * A snapshot is the whole picture, so a node that has left it is gone here too - the one thing
     * a portion can never do, and the reason the frame carries the flag at all.
     */
    public function testASnapshotDropsANodeItNoLongerMentions(): void
    {
        ClusterLogIndexMirror::applyPortion($this->snapshot($this->slot('node-1', 100), $this->slot('node-2', 300)));

        ClusterLogIndexMirror::applyPortion($this->snapshot($this->slot('node-1', 100)));

        $this->assertSame(1, ClusterLogIndexMirror::index()?->totals()->nodeCount);
    }

    /**
     * A portion arriving before any snapshot is filed rather than dropped: the aggregator only
     * sends one to a subscriber it answered, so refusing it here would lose a frame over an order
     * that cannot happen, and going without the picture until the next one would be worse.
     */
    public function testAPortionArrivingFirstStartsThePicture(): void
    {
        ClusterLogIndexMirror::applyPortion($this->portion($this->slot('node-1', 100)));

        $this->assertTrue(ClusterLogIndexMirror::known());
        $this->assertSame(1, ClusterLogIndexMirror::index()?->totals()->nodeCount);
    }

    public function testTheViewerCountRisesAndFalls(): void
    {
        ClusterLogIndexMirror::addViewer('ak-1');
        ClusterLogIndexMirror::addViewer('ak-2');
        $this->assertSame(2, ClusterLogIndexMirror::viewerCount());

        ClusterLogIndexMirror::removeViewer('ak-1');

        $this->assertSame(1, ClusterLogIndexMirror::viewerCount());
        $this->assertSame(['ak-2'], ClusterLogIndexMirror::viewerKeys());
    }

    /**
     * A browser resubscribing over a connection it already holds is the same viewer, and counting
     * it twice would keep the subscription open after that connection closed.
     */
    public function testTheSameConnectionCountedTwiceIsStillOneViewer(): void
    {
        ClusterLogIndexMirror::addViewer('ak-1');
        ClusterLogIndexMirror::addViewer('ak-1');

        $this->assertSame(1, ClusterLogIndexMirror::viewerCount());
    }

    /**
     * The picture outlives the viewers on purpose: the next one to open the page sees the last
     * known figures at once and the fresh ones a moment later, where forgetting would flash an
     * empty screen at every entry.
     */
    public function testTheLastViewerLeavingDoesNotEraseThePicture(): void
    {
        ClusterLogIndexMirror::applyPortion($this->snapshot($this->slot('node-1', 100)));
        ClusterLogIndexMirror::addViewer('ak-1');

        ClusterLogIndexMirror::removeViewer('ak-1');

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
        $this->assertTrue(ClusterLogIndexMirror::known());
        $this->assertSame(1, ClusterLogIndexMirror::index()?->totals()->nodeCount);
    }

    /**
     * The mirror belongs to the worker process, so a case leaves it as it found it.
     */
    private function emptyTheMirror(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        foreach (ClusterLogIndexMirror::viewerKeys() as $acceptKey) {
            ClusterLogIndexMirror::removeViewer($acceptKey);
        }
    }

    /**
     * @param ClusterLogNodeSlot ...$slots Slots the frame carries
     * @return ClusterLogIndexPortionSignalData Frame replacing the whole picture
     */
    private function snapshot(ClusterLogNodeSlot ...$slots): ClusterLogIndexPortionSignalData
    {
        return ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true);
    }

    /**
     * @param ClusterLogNodeSlot ...$slots Slots the frame carries
     * @return ClusterLogIndexPortionSignalData Frame to lay over the picture
     */
    private function portion(ClusterLogNodeSlot ...$slots): ClusterLogIndexPortionSignalData
    {
        return ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), false);
    }

    /**
     * @param string $nodeId Node the slot belongs to
     * @param int $totalBytes Weight of that node's one agent key
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function slot(string $nodeId, int $totalBytes): ClusterLogNodeSlot
    {
        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: true,
                sampledAt: self::T0,
                batches: [],
                keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], $totalBytes)],
                workers: [],
                growthBytesPerDay: [],
            ),
            receivedAt: self::T0,
        );
    }
}

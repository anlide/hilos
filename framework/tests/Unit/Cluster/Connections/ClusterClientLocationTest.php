<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Connections;

use Hilos\Cluster\Connections\ClusterClientLocation;
use PHPUnit\Framework\TestCase;

/**
 * How a node learns which of its peers a browser is attached to (HIL-668).
 *
 * The index is the whole of the answer to "an agent on node B wants to reply to a browser on
 * node A": without it the reply resolves to a local socket that is not there and is dropped.
 * So what is pinned here is the arithmetic of the set — a snapshot replaces, a delta adds and
 * removes, a node that left takes its connections with it — and, just as load-bearing, the two
 * ways of being wrong being kept apart: an unknown key answers null, which routes locally and
 * costs a dropped signal, while a key credited to the wrong node routes to a node that has
 * nowhere to put it and costs the same signal plus a frame. The first is recoverable by the
 * next announcement; the second is what {@see ClusterClientLocation::bind()} exists to prevent.
 */
final class ClusterClientLocationTest extends TestCase
{
    /** @var string Node most of these cases announce from */
    private const string NODE_B = 'node-b';

    /** @var string The other announcing node */
    private const string NODE_C = 'node-c';

    /**
     * The plain case the whole feature rests on.
     */
    public function testASnapshotPlacesEveryKeyItCarriesOnItsNode(): void
    {
        $index = new ClusterClientLocation();

        $index->applySnapshot(self::NODE_B, ['ak-1', 'ak-2']);

        $this->assertSame(self::NODE_B, $index->nodeFor('ak-1'));
        $this->assertSame(self::NODE_B, $index->nodeFor('ak-2'));
    }

    /**
     * A key nobody announced is not an error and not a guess: it is a key this node either
     * holds itself or has never heard of, and both are served by the local path.
     */
    public function testAnUnknownKeyIsNotPlacedAnywhere(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);

        $this->assertNull($index->nodeFor('ak-never-announced'));
    }

    /**
     * A snapshot is the announcing node's whole truth about itself, so what it leaves out is
     * gone. A merge here would keep every connection that node ever had forever.
     */
    public function testASnapshotReplacesEverythingHeldForThatNode(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1', 'ak-2']);

        $index->applySnapshot(self::NODE_B, ['ak-2']);

        $this->assertNull($index->nodeFor('ak-1'));
        $this->assertSame(self::NODE_B, $index->nodeFor('ak-2'));
    }

    /**
     * And it is the truth about that node ALONE - a node re-announcing itself says nothing
     * about anybody else's clients.
     */
    public function testASnapshotLeavesTheOtherNodesConnectionsAlone(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);
        $index->applySnapshot(self::NODE_C, ['ak-2']);

        $index->applySnapshot(self::NODE_B, []);

        $this->assertNull($index->nodeFor('ak-1'));
        $this->assertSame(self::NODE_C, $index->nodeFor('ak-2'));
    }

    /**
     * The steady state: one frame carries both directions, because a tick's diff is one fact.
     */
    public function testADeltaAddsWhatOpenedAndForgetsWhatClosed(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);

        $index->applyDelta(self::NODE_B, ['ak-2'], ['ak-1']);

        $this->assertNull($index->nodeFor('ak-1'));
        $this->assertSame(self::NODE_B, $index->nodeFor('ak-2'));
    }

    /**
     * A close is honoured only from the node the index credits with the connection. A browser
     * that reconnects to another node mints a new key, but a frame from the old holder can
     * still be in flight - and applied blindly it would erase the entry the new holder just
     * made, blinding the cluster to a client that is very much connected.
     */
    public function testACloseFromANodeThatNoLongerHoldsTheKeyIsIgnored(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);
        $index->applyDelta(self::NODE_C, ['ak-1'], []);

        $index->applyDelta(self::NODE_B, [], ['ak-1']);

        $this->assertSame(self::NODE_C, $index->nodeFor('ak-1'));
    }

    /**
     * The other half of the same rule: a key claimed by a second node stops counting towards
     * the first, so the node's own set never keeps an entry the lookup has already reassigned.
     * The emptied node leaves the count altogether - the map names nodes that hold connections,
     * not nodes that once did.
     */
    public function testAKeyClaimedByASecondNodeLeavesTheFirstNodesSet(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);

        $index->applyDelta(self::NODE_C, ['ak-1'], []);

        $this->assertSame([self::NODE_C => 1], $index->countsByNode());
    }

    /**
     * A node that left cannot deliver to anybody, and nothing will ever announce its closes.
     */
    public function testANodeThatLeftTakesItsConnectionsWithIt(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1', 'ak-2']);
        $index->applySnapshot(self::NODE_C, ['ak-3']);

        $index->forgetNode(self::NODE_B);

        $this->assertNull($index->nodeFor('ak-1'));
        $this->assertNull($index->nodeFor('ak-2'));
        $this->assertSame(self::NODE_C, $index->nodeFor('ak-3'));
    }

    /**
     * Forgetting a node nobody announced for is a no-op: node-down fires for every node that
     * leaves, including the ones that never had a browser attached.
     */
    public function testForgettingAnUnknownNodeChangesNothing(): void
    {
        $index = new ClusterClientLocation();
        $index->applySnapshot(self::NODE_B, ['ak-1']);

        $index->forgetNode('node-that-never-spoke');

        $this->assertSame(self::NODE_B, $index->nodeFor('ak-1'));
    }

    /**
     * The local half. Everything this node holds is news the first time it is diffed, because
     * the mesh has been told nothing yet.
     */
    public function testTheFirstDiffReportsEveryConnectionAsOpened(): void
    {
        $index = new ClusterClientLocation();

        $this->assertSame(['opened' => ['ak-1', 'ak-2'], 'closed' => []], $index->diffLocal(['ak-1', 'ak-2']));
    }

    /**
     * And the second says nothing, which is what keeps a quiet node quiet: the diff runs every
     * tick of the daemon loop, and a set that did not move is not an announcement.
     */
    public function testASecondDiffOfTheSameSetIsSilent(): void
    {
        $index = new ClusterClientLocation();
        $index->diffLocal(['ak-1']);

        $this->assertSame(['opened' => [], 'closed' => []], $index->diffLocal(['ak-1']));
    }

    /**
     * A connection that ended is reported once and then forgotten - the case a hook on the
     * close paths could miss, and the reason the local half is a diff at all.
     */
    public function testADisappearedConnectionIsReportedClosedExactlyOnce(): void
    {
        $index = new ClusterClientLocation();
        $index->diffLocal(['ak-1', 'ak-2']);

        $this->assertSame(['opened' => [], 'closed' => ['ak-1']], $index->diffLocal(['ak-2']));
        $this->assertSame(['opened' => [], 'closed' => []], $index->diffLocal(['ak-2']));
    }

    /**
     * What the mesh has been told is what a node linking now is handed, so the snapshot and the
     * deltas tell one story.
     */
    public function testTheAnnouncedSetIsWhatTheLastDiffSettledOn(): void
    {
        $index = new ClusterClientLocation();
        $index->diffLocal(['ak-1', 'ak-2']);
        $index->diffLocal(['ak-2', 'ak-3']);

        $this->assertSame(['ak-2', 'ak-3'], $index->announcedLocalKeys());
    }

    /**
     * The test door announces exactly like a socket does - that is the whole point of it, since
     * `demo/cluster` runs headless and has no sockets to announce.
     */
    public function testAnAttachedKeyIsAnnouncedLikeAConnectedOne(): void
    {
        $index = new ClusterClientLocation();

        $index->attachLocal('ak-attached');

        $this->assertSame(['opened' => ['ak-attached'], 'closed' => []], $index->diffLocal([]));
    }

    /**
     * It also outlives the diff that announced it. A pretend connection with no socket behind
     * it would otherwise be closed by the very next tick, one tick after it was opened.
     */
    public function testAnAttachedKeyStaysAnnouncedAcrossTicks(): void
    {
        $index = new ClusterClientLocation();
        $index->attachLocal('ak-attached');
        $index->diffLocal([]);

        $this->assertSame(['opened' => [], 'closed' => []], $index->diffLocal([]));
        $this->assertSame(['ak-attached'], $index->announcedLocalKeys());
    }

    /**
     * A key that reads as a number is still a key, and it has to leave here as a string: the
     * set is a PHP array, so "12345" lives in it as int 12345, and a delta or a snapshot
     * carrying that int is refused by the frame reader on the far side — one CLI argument
     * away, since the test door takes whatever a harness names.
     */
    public function testANumericAttachedKeyIsAnnouncedAsAString(): void
    {
        $index = new ClusterClientLocation();

        $index->attachLocal('12345');

        $this->assertSame(['opened' => ['12345'], 'closed' => []], $index->diffLocal([]));
        $this->assertSame(['12345'], $index->announcedLocalKeys());
    }

    /**
     * And detaching ends it the way a socket closing does.
     */
    public function testDetachingAnAttachedKeyReportsItClosed(): void
    {
        $index = new ClusterClientLocation();
        $index->attachLocal('ak-attached');
        $index->diffLocal([]);

        $index->detachLocal('ak-attached');

        $this->assertSame(['opened' => [], 'closed' => ['ak-attached']], $index->diffLocal([]));
    }

    /**
     * This node's own connections are not in the remote half, so the lookup sends them nowhere
     * and the local delivery path keeps them.
     */
    public function testThisNodesOwnConnectionsAreNotPlacedOnAnyNode(): void
    {
        $index = new ClusterClientLocation();
        $index->attachLocal('ak-mine');
        $index->diffLocal(['ak-socket']);

        $this->assertNull($index->nodeFor('ak-mine'));
        $this->assertNull($index->nodeFor('ak-socket'));
    }

    /**
     * A node that has taken in nothing says so with a zero and a null rather than by leaving
     * the fields out: a scenario reads a baseline before it sends anything, and an absent field
     * would read as a delivery that never happened.
     */
    public function testAFreshIndexHasTakenInNothing(): void
    {
        $index = new ClusterClientLocation();

        $this->assertSame(0, $index->deliveries());
        $this->assertNull($index->lastAcceptKey());
    }

    /**
     * The tally is what a cluster scenario asserts on, because the delivery itself ends at a
     * socket — and the cluster demo runs headless, so there is no socket to watch.
     */
    public function testAnAddressedDeliveryIsCountedAndNamed(): void
    {
        $index = new ClusterClientLocation();

        $index->noteAddressedDelivery('ak-1');

        $this->assertSame(1, $index->deliveries());
        $this->assertSame('ak-1', $index->lastAcceptKey());
    }

    /**
     * A fan-out is counted the same and names nobody: it addresses no browser, so blanking the
     * last addressed key would lose a fact rather than update one.
     */
    public function testAFanoutIsCountedWithoutTouchingTheLastKey(): void
    {
        $index = new ClusterClientLocation();
        $index->noteAddressedDelivery('ak-1');

        $index->noteFanoutDelivery();

        $this->assertSame(2, $index->deliveries());
        $this->assertSame('ak-1', $index->lastAcceptKey());
    }
}

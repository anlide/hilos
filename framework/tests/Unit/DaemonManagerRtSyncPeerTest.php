<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtClaimMesh;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Cluster\SourceInterestMesh;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\HilosSessionRotations;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtReplicaOriginMap;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How an RT write travels between the nodes of a cluster (HIL-586).
 *
 * An RT collection has exactly one truth source in the whole cluster, so replication is
 * one-way and there is nothing to arbitrate: the node the write happened on announces the
 * fact, and every other node applies it to a copy it never writes to itself. What is pinned
 * here is the shape that keeps that true — the fact leaves the owning node, it is applied on
 * the receiving node and handed to that node's own workers, and it stops there.
 *
 * The last one is the load-bearing case. Phase-1 of this cluster died on a gossip echo, and
 * the defense chosen instead of hop counters was structural: a received frame is never passed
 * on. A change that starts forwarding replicas would read as harmless and would take the mesh
 * down under two nodes and one busy collection.
 */
final class DaemonManagerRtSyncPeerTest extends TestCase
{
    /** @var string Node the replicas in these cases arrive from */
    public const string REMOTE_NODE = 'node-b';

    /** @var string Row id every case syncs */
    public const string ROW_ID = '7';

    /** @var string Row of the same collection that a THIRD node owns, in the freezing cases */
    public const string NEIGHBOUR_ROW_ID = '8';

    /** @var string Row of the same collection that this node wrote itself */
    public const string OWN_ROW_ID = '9';

    /** @var float Microtime the freezing cases lose their link at */
    public const float FROZE_AT = 1000.5;

    /** @var string One-time rotation ticket the session cases hand around */
    public const string TICKET = 'ticket-1';

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        // Process-wide, so without this one case's frozen rows would be read by the next.
        RtStaleness::reset();

        parent::tearDown();
    }

    /**
     * What makes the write this node's to announce is an agent of this node owning the
     * collection - the map says so, and everything below turns on it.
     *
     * @throws AgentException When routing the signal fails
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAnRtWriteOnThisNodeIsAnnouncedToTheMeshOnce(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->queueRtSyncCreated('Ada');
        $daemon->dispatch();

        $this->assertSame(
            [$daemon->peerServer],
            $daemon->announcedThrough,
            'The dispatch pass announces through the peer server it already found for this pass',
        );
        $announced = $daemon->mesh->singleAnnouncement();
        $this->assertSame(SignalTypeConstants::RT_SYNC_CREATED, $announced->signalType);
        $this->assertSame(SignalTypeConstants::RT_SYNC_CREATED, $announced->signal->signalType->getType());
    }

    /**
     * The signal is announced whole rather than unpacked into fields of a frame, so the peers
     * apply the very fact this node's worker produced.
     *
     * @throws AgentException When routing the signal fails
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheAnnouncementCarriesTheWrittenRowItself(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->queueRtSyncCreated('Ada');
        $daemon->dispatch();

        $data = $daemon->mesh->singleAnnouncement()->signal->data;
        $this->assertInstanceOf(RtSyncCreatedSignalData::class, $data);
        $this->assertSame(DaemonManagerRtSyncPeerTestRtContext::ROWS, $data->collectionKey);
        $this->assertSame(self::ROW_ID, $data->stateId);
        $this->assertSame('Ada', $data->row[DaemonManagerRtSyncPeerTestState::name] ?? null);
    }

    /**
     * A DB fact travels the same branch of the dispatch pass and must not be announced: the
     * nodes share one database, so a peer applying it would be writing a second copy of one
     * write.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testADatabaseSyncTravellingTheSameBranchIsNotAnnounced(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announce($this->dbSyncCreated());

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * A write no agent of this node stands behind is not this node's to announce, and the
     * protected-mode freeze row is the framework's own case of it: every master registers itself
     * as its truth source and writes its own node's copy - the leader by decision, each follower
     * in reaction to the peer frame carrying that decision. Announced, one node's freeze would
     * overwrite another's, and the receiving side could not refuse it: the ownership map is built
     * from what agents report, and no agent stands behind this write to be reported.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheFreezeRowEveryMasterWritesItselfIsNotAnnounced(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announce($daemon->protectedModeFrozen());

        $this->assertSame(
            [],
            $daemon->mesh->announcements,
            'A collection this node writes on its own behalf stays this node\'s business',
        );
    }

    /**
     * The one collection a master writes beside its owning agent, and the reason the filter above
     * is not simply "what the map says". The agent announces a rotation from a worker, and the
     * master that receives the 101 spending its ticket burns the row (HIL-582) - on whichever
     * node that connection lands, agent or no agent. A burn kept at home would leave the spent
     * ticket standing where the agent lives, good for a second handshake until its TTL runs out.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheBurnOfASpentTicketIsAnnouncedThoughNoAgentHereOwnsTheStore(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announce($daemon->rotationBurned(self::TICKET));

        $announced = $daemon->mesh->singleAnnouncement();
        $this->assertSame(SignalTypeConstants::RT_SYNC_DELETED, $announced->signalType);
        $this->assertSame(SignalTypeConstants::RT_SYNC_DELETED, $announced->signal->signalType->getType());
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAnRtWriteIsAnnouncedNowhereWhenTheNodeIsOffCluster(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announceWithoutMesh($daemon->rtSyncCreated('Ada'));

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaFromAnotherNodeLandsInThisNodesCopy(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $state = $collection->get(self::ROW_ID);
        $this->assertInstanceOf(DaemonManagerRtSyncPeerTestState::class, $state);
        $this->assertSame('Grace', $state->name);
    }

    /**
     * The workers keep their own copy of RT state, so a replica the master applied and kept to
     * itself would leave every subscriber on this node reading the row that never changed.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaFromAnotherNodeReachesThisNodesWorkers(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertSame([WorkerConstants::MESSAGE_RT_SYNC_CREATED], $daemon->workerServer->frameTypes());
    }

    /**
     * The other half of the same rule (HIL-717): a worker that does not read the collection is not
     * written to. It could not apply the frame - it holds no copy to apply it into - so the frame
     * would be a socket write, a decode and a lookup, all of it to reach nobody.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaSkipsAWorkerThatDoesNotReadTheCollection(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->workerReads(StateHilosSessionRotation::RT_COLLECTION);

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertSame([], $daemon->workerServer->frameTypes());
    }

    /**
     * A worker that let go of one collection keeps receiving the others: the report replaces the
     * whole list, so the frames have to follow the list and not the last thing that changed.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAWorkerThatDroppedOneCollectionStillHearsAboutTheRest(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->workerReads(DaemonManagerRtSyncPeerTestRtContext::ROWS);

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertSame([WorkerConstants::MESSAGE_RT_SYNC_CREATED], $daemon->workerServer->frameTypes());
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaFromAnotherNodeIsNotAnnouncedOnwards(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertSame(
            [],
            $daemon->mesh->announcements,
            'A received replica is applied, never passed on: one hop is the whole echo defense',
        );
    }

    /**
     * The node-level half of the same addressing: what this node reads is told to the mesh, so
     * the nodes writing those collections know a frame about them is worth a hop here.
     */
    public function testTheMeshIsToldWhichCollectionsThisNodeReads(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announceInterest();

        $this->assertSame(
            [[DaemonManagerRtSyncPeerTestRtContext::ROWS, StateHilosSessionRotation::RT_COLLECTION]],
            $daemon->mesh->interests,
        );
    }

    /**
     * And only when it moved. The loop runs this step every pass, and a node whose readers are
     * settled would otherwise announce the same list a thousand times a second.
     */
    public function testAUnionThatHasNotMovedIsAnnouncedOnlyOnce(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->announceInterest();
        $daemon->announceInterest();

        $this->assertCount(1, $daemon->mesh->interests);
    }

    /**
     * A second worker taking up a collection this node already read changes who holds it here,
     * not whether the node does - and the mesh is told the latter. This is the ordinary case: a
     * page subscribing on another worker would otherwise be a frame to every peer.
     */
    public function testASecondWorkerReadingWhatIsAlreadyReadIsNotNewsForTheMesh(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->announceInterest();

        $daemon->anotherWorkerReads(DaemonManagerRtSyncPeerTestRtContext::ROWS);
        $daemon->announceInterest();

        $this->assertCount(1, $daemon->mesh->interests);
    }

    /**
     * The collection whose last reader here went away is announced as gone, and the peers stop
     * spending hops on it.
     */
    public function testACollectionNobodyReadsAnyMoreIsTakenOutOfTheAnnouncement(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->announceInterest();

        $daemon->workerReads(DaemonManagerRtSyncPeerTestRtContext::ROWS);
        $daemon->announceInterest();

        $this->assertSame(
            [[DaemonManagerRtSyncPeerTestRtContext::ROWS]],
            array_slice($daemon->mesh->interests, 1),
        );
    }

    /**
     * A worker that died reports nothing, so its link gives its list back - and the node that is
     * left reading nothing says exactly that. An empty list is not the absence of news: without
     * it the mesh would go on addressing this node forever.
     */
    public function testANodeLeftReadingNothingAnnouncesAnEmptyList(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->announceInterest();

        $daemon->workerLinkClosed(DaemonManagerRtSyncPeerTestWorkerClient::WORKER_INDEX);
        $daemon->announceInterest();

        $this->assertSame([[]], array_slice($daemon->mesh->interests, 1));
    }

    /**
     * What a node reads stands beside what it owns in the inspect reply, and that is the whole
     * outside view of addressing: an operator asking why a row did or did not arrive on a node is
     * asking this flag. Both halves come from the maps the delivery logic itself reads.
     */
    public function testTheInspectReplyNamesWhatThisNodeReadsBesideWhatItOwns(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->workerReads(DaemonManagerRtSyncPeerTestRtContext::ROWS);

        $collections = $daemon->inspectRtReplicas()[ClusterCommandConstants::FIELD_RT_COLLECTIONS];

        $this->assertTrue(
            $collections[DaemonManagerRtSyncPeerTestRtContext::ROWS][ClusterCommandConstants::FIELD_RT_READ],
        );
        $this->assertFalse(
            $collections[StateHilosSessionRotation::RT_COLLECTION][ClusterCommandConstants::FIELD_RT_READ],
        );
    }

    /**
     * The defect this ticket is about, in the only form a running node can notice it: a replica
     * for a collection this node owns means the collection has a truth source on two nodes. The
     * model has no arbitration, so the only honest answer is to keep what this node wrote, drop
     * the other write and name whose it was.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaForACollectionThisNodeOwnsIsDroppedAndTheSplitIsNamed(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has(self::ROW_ID), 'The local write stands; the remote one is refused');
        $this->assertSame([], $daemon->workerServer->frameTypes());
        $this->assertStringContainsString(DaemonManagerRtSyncPeerTestRtContext::ROWS, $logged);
        $this->assertStringContainsString('truth sources on two nodes', $logged);
        $this->assertStringContainsString(self::REMOTE_NODE, $logged);
    }

    /**
     * The narrowing HIL-688 put on that refusal, from the receiving side. A node that says in
     * the frame it holds only part of the right over the collection is not a second owner: the
     * arrangement is that each node writes the operations it was granted, and refusing the other
     * half would break the very thing the operation axis was built for. Nothing is logged - a
     * line per legitimate write would bury the one line that means something.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaFromAPartialOwnerIsAppliedByTheNodeThatOwnsTheRest(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace'), partialOwner: true);
        $logged = (string)ob_get_clean();

        $this->assertTrue($collection->has(self::ROW_ID), 'A co-owner wrote what it was entitled to write');
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    /**
     * The other side of the same narrowing: this node owns the collection only partly, so it has
     * no standing to call another node's write a split - it does not hold the whole truth about
     * the collection to begin with.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testANodeThatOwnsOnlyPartOfACollectionRefusesNothing(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'rooms_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
        );

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $logged = (string)ob_get_clean();

        $this->assertTrue($collection->has(self::ROW_ID), 'Holding part of a right is no ground to refuse the rest');
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    /**
     * And the mark is not a way out of the refusal: a frame claiming the whole right, arriving
     * where the whole right is already held, is the split whatever else changed around it.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaClaimingTheWholeRightIsStillDroppedWhereItIsHeld(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS], ['someOtherCollection']);

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has(self::ROW_ID));
        $this->assertStringContainsString('truth sources on two nodes', $logged);
    }

    /**
     * The fleet arrangement, which is what the row axis is for: every node of the mesh runs an
     * agent writing its own rows of one shared collection. A frame about a row this node did not
     * claim is ordinary traffic and is applied, workers and all - before HIL-589 the map knew
     * only collections, so this very frame read as a split and the fleet never converged.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaAboutARowThisNodeDidNotClaimIsApplied(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace', '9'));
        $logged = (string)ob_get_clean();

        $this->assertTrue($collection->has('9'), 'The rows this node never claimed are the neighbour\'s to write');
        $this->assertSame([WorkerConstants::MESSAGE_RT_SYNC_CREATED], $daemon->workerServer->frameTypes());
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    /**
     * And the same claim still refuses a frame about the row it does hold: two nodes writing one
     * entity is the split, told apart from the case above by the row alone. The line is the one
     * the collection-wide guard has always printed - what changed is the question, not the form
     * of the answer.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaAboutTheRowThisNodeClaimedIsDroppedAndTheSplitIsNamed(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        ob_start();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has(self::ROW_ID), 'The entity this node owns stays as this node wrote it');
        $this->assertSame([], $daemon->workerServer->frameTypes());
        $this->assertStringContainsString(DaemonManagerRtSyncPeerTestRtContext::ROWS, $logged);
        $this->assertStringContainsString('truth sources on two nodes', $logged);
        $this->assertStringContainsString(self::REMOTE_NODE, $logged);
    }

    /**
     * An owner of named rows announces its writes as a partial owner would, because that is what
     * it is on the row axis: the rest of the collection belongs to somebody, and the receiving
     * node must not read this fact as a claim over it.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAnOwnerOfNamedRowsAnnouncesWithTheMark(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        $daemon->announce($daemon->rtSyncCreated('Ada'));

        $this->assertTrue($daemon->mesh->singleAnnouncement()->partialOwner);
    }

    /**
     * What this node announces says how completely it owns what it wrote, because the node on
     * the other end judges the frame by exactly that.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAPartialOwnerSaysSoInWhatItAnnounces(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'rooms_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
        );

        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->announce($daemon->rtSyncCreated('Ada'));

        $this->assertTrue($daemon->mesh->singleAnnouncement()->partialOwner);
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAWholeOwnerAnnouncesWithoutTheMark(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->announce($daemon->rtSyncCreated('Ada'));

        $this->assertFalse($daemon->mesh->singleAnnouncement()->partialOwner);
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaForACollectionOwnedElsewhereIsApplied(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent('other_agent', ['someOtherCollection']);

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertTrue($collection->has(self::ROW_ID), 'Owning one collection says nothing about another');
    }

    /**
     * The receiving half of the co-written store: here an agent of this node does own the
     * collection, so the guard above would refuse the frame as a split - and refusing this one
     * is exactly what leaves a spent ticket usable. The master on the far node is not a second
     * owner, it is the other half of one design.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheBurnOfASpentTicketIsAppliedWhereTheOwningAgentLives(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $rotations = $daemon->mountRotationStore();
        $rotations->add(StateHilosSessionRotation::create(self::TICKET, 'session-token', [], 0.0));
        $daemon->noteOwnAgent('session_agent', [StateHilosSessionRotation::RT_COLLECTION]);

        ob_start();
        $daemon->receive($daemon->rotationBurned(self::TICKET));
        $logged = (string)ob_get_clean();

        $this->assertFalse($rotations->has(self::TICKET), 'A ticket spent on another node is spent here too');
        $this->assertSame([WorkerConstants::MESSAGE_RT_SYNC_DELETED], $daemon->workerServer->frameTypes());
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    /**
     * The sink is reachable from the wire, so the one thing it may not do is apply whatever a
     * peer calls an RT sync: a frame declaring an RT type over a signal of another one would
     * otherwise run that other signal's apply arm on this node.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAFrameCarryingAnotherSignalThanItDeclaresIsDropped(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();

        ob_start();
        $daemon->applyRemoteRtSync(self::REMOTE_NODE, SignalTypeConstants::RT_SYNC_CREATED, $this->dbSyncCreated());
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has(self::ROW_ID));
        $this->assertSame([], $daemon->workerServer->frameTypes());
        $this->assertStringContainsString(self::REMOTE_NODE, $logged);
    }

    /**
     * The type is not enough on its own: the apply step reads the signal type and the fan-out
     * reads its NAME, so a frame typed as an RT create and named as a database clear would be
     * refused by neither and would reach every worker of this node as that clear.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAFrameNamedAfterAnotherSyncFactThanItsTypeIsDropped(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();

        ob_start();
        $daemon->applyRemoteRtSync(
            self::REMOTE_NODE,
            SignalTypeConstants::RT_SYNC_CREATED,
            new SignalDTO(
                new SignalSource(SignalSource::RT),
                new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
                new SignalName(SignalConstants::DB_SYNC_CLEARED),
                new RtSyncCreatedSignalData(
                    DaemonManagerRtSyncPeerTestRtContext::ROWS,
                    self::ROW_ID,
                    [
                        DaemonManagerRtSyncPeerTestState::id => self::ROW_ID,
                        DaemonManagerRtSyncPeerTestState::name => 'Ada',
                    ],
                ),
            ),
        );
        $logged = (string)ob_get_clean();

        $this->assertSame([], $daemon->workerServer->frameTypes(), 'Nothing reaches the workers under a false name');
        $this->assertFalse($collection->has(self::ROW_ID));
        $this->assertStringContainsString(SignalConstants::DB_SYNC_CLEARED, $logged);
    }

    /**
     * The ownership question is asked of the collection the payload names, so a payload that
     * names none cannot be judged at all - and what cannot be judged is refused, the way the
     * announcing side refuses to put such a fact on the wire. Applying it would also leave the
     * apply step converting a payload of unknown shape by a peer's word.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAFrameWhosePayloadNamesNoCollectionIsDropped(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();

        ob_start();
        $daemon->applyRemoteRtSync(
            self::REMOTE_NODE,
            SignalTypeConstants::RT_SYNC_CREATED,
            new SignalDTO(
                new SignalSource(SignalSource::RT),
                new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
                new SignalName(SignalConstants::RT_SYNC_CREATED),
                new DaemonManagerRtSyncPeerTestOpaqueSignalData(),
            ),
        );
        $logged = (string)ob_get_clean();

        $this->assertSame([], $daemon->workerServer->frameTypes());
        $this->assertStringContainsString('names no collection', $logged);
    }

    /**
     * A node that has just come up has no history for the deltas to apply to, so the owner
     * hands the collection over whole — and whole means whole: what this node held and the
     * snapshot does not carry is gone, because the owner's copy is the truth about it.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testASnapshotReplacesTheCollectionRatherThanAddingToIt(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Ada'));

        $daemon->receiveSnapshot(DaemonManagerRtSyncPeerTestRtContext::ROWS, ['9' => ['id' => '9', 'name' => 'Grace']]);

        $this->assertFalse($collection->has(self::ROW_ID), 'A row the snapshot does not carry no longer exists');
        $state = $collection->get('9');
        $this->assertInstanceOf(DaemonManagerRtSyncPeerTestState::class, $state);
        $this->assertSame('Grace', $state->name);
    }

    /**
     * The workers hold their own copy, and a create alone leaves a row they already have as it
     * was — so a replacement has to reach them as a drop of what was there and a create of what
     * is, or this node would agree with the owner while its own workers did not.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheWorkersAreToldToDropWhatTheyHeldBeforeTheSnapshot(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Ada'));

        $daemon->receiveSnapshot(DaemonManagerRtSyncPeerTestRtContext::ROWS, ['9' => ['id' => '9', 'name' => 'Grace']]);

        $this->assertSame(
            [
                WorkerConstants::MESSAGE_RT_SYNC_CREATED,
                WorkerConstants::MESSAGE_RT_SYNC_DELETED,
                WorkerConstants::MESSAGE_RT_SYNC_CREATED,
            ],
            $daemon->workerServer->frameTypes(),
            'The delta, then the drop of what it wrote, then the row the snapshot carries',
        );
    }

    /**
     * A scoped snapshot speaks for its own rows and no others: what the sender named is brought
     * in line with what it sent, and every other row of the collection - this node's own, or a
     * third node's - is left exactly as it was, workers and all.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testAScopedSnapshotReplacesItsOwnRowsAndLeavesTheRestAlone(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => '8', 'name' => 'stale']));
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        $daemon->receiveSnapshot(
            DaemonManagerRtSyncPeerTestRtContext::ROWS,
            ['9' => ['id' => '9', 'name' => 'Grace']],
            ['8', '9'],
        );

        $this->assertTrue($collection->has(self::ROW_ID), 'The row this node owns is outside the scope');
        $this->assertFalse($collection->has('8'), 'A row of the scope the frame does not carry is gone');
        $this->assertTrue($collection->has('9'));
        $this->assertSame(
            [WorkerConstants::MESSAGE_RT_SYNC_DELETED, WorkerConstants::MESSAGE_RT_SYNC_CREATED],
            $daemon->workerServer->frameTypes(),
            'The workers hear about the scope only: one row dropped, one row written',
        );
    }

    /**
     * A row carried past the scope the frame declares is dropped, and this is the case that says
     * why: the two-owner refusal is asked of the SCOPE, so a row outside it was judged by nobody.
     * Taken, it would let any frame overwrite a row this node owns by simply not naming it -
     * and would tell this node's workers to create it.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testAScopedSnapshotDoesNotReachPastItsOwnScope(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        ob_start();
        $daemon->receiveSnapshot(
            DaemonManagerRtSyncPeerTestRtContext::ROWS,
            [
                '9' => ['id' => '9', 'name' => 'Grace'],
                self::ROW_ID => ['id' => self::ROW_ID, 'name' => 'stolen'],
            ],
            ['9'],
        );
        $logged = (string)ob_get_clean();

        $this->assertTrue($collection->has('9'), 'What the frame speaks for is applied');
        $this->assertSame(
            'Ada',
            $collection[self::ROW_ID]->name,
            'The row this node owns is untouched: the frame never claimed to speak for it',
        );
        $this->assertSame(
            [WorkerConstants::MESSAGE_RT_SYNC_CREATED],
            $daemon->workerServer->frameTypes(),
            'And the workers hear about the scope alone',
        );
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    /**
     * The two-owner refusal, asked of a scoped frame: a fleet member takes its neighbours' rows
     * and refuses a frame reaching for one of its own. Judging such a frame by the collection
     * would refuse every hand-over the fleet ever makes.
     */
    public function testAScopedSnapshotReachingForARowThisNodeOwnsIsRefused(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        ob_start();
        $daemon->receiveSnapshot(
            DaemonManagerRtSyncPeerTestRtContext::ROWS,
            [self::ROW_ID => ['id' => self::ROW_ID, 'name' => 'Grace']],
            [self::ROW_ID],
        );
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has(self::ROW_ID), 'Nobody hands this node the row it writes itself');
        $this->assertStringContainsString('truth sources on two nodes', $logged);
    }

    /**
     * And the same node takes a scoped frame about rows it never claimed - the ordinary case of
     * a fleet reconnecting, and what makes the convergence the ticket asks for possible at all.
     */
    public function testAScopedSnapshotAboutOtherRowsIsAcceptedWhereRowsAreOwned(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        ob_start();
        $daemon->receiveSnapshot(
            DaemonManagerRtSyncPeerTestRtContext::ROWS,
            ['9' => ['id' => '9', 'name' => 'Grace']],
            ['9'],
        );
        $logged = (string)ob_get_clean();

        $this->assertTrue($collection->has('9'));
        $this->assertStringNotContainsString('truth sources on two nodes', $logged);
    }

    public function testASnapshotForACollectionThisNodeOwnsIsRefused(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        ob_start();
        $daemon->receiveSnapshot(DaemonManagerRtSyncPeerTestRtContext::ROWS, ['9' => ['id' => '9', 'name' => 'Grace']]);
        $logged = (string)ob_get_clean();

        $this->assertFalse($collection->has('9'), 'Nobody replaces the collection this node is the source of');
        $this->assertStringContainsString('truth sources on two nodes', $logged);
    }

    /**
     * The exemption the burn gets is for deltas alone. A whole collection is only ever offered by
     * the node whose own agent owns it, so one arriving where an agent owns it too means two
     * agents on two nodes - the split itself, and the master's second half has nothing to do
     * with it. Handing this one over would drop every rotation the local agent has written.
     */
    public function testASnapshotOfTheRotationStoreIsRefusedWhereTheOwningAgentLives(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $rotations = $daemon->mountRotationStore();
        $daemon->noteOwnAgent('session_agent', [StateHilosSessionRotation::RT_COLLECTION]);

        ob_start();
        $daemon->receiveSnapshot(StateHilosSessionRotation::RT_COLLECTION, [
            self::TICKET => [
                StateHilosSessionRotation::ticket => self::TICKET,
                StateHilosSessionRotation::sessionToken => 'session-token',
                StateHilosSessionRotation::acceptKeysToDrop => [],
                StateHilosSessionRotation::expiresAtMs => 0.0,
            ],
        ]);
        $logged = (string)ob_get_clean();

        $this->assertFalse($rotations->has(self::TICKET), 'Nobody replaces the store an agent here writes');
        $this->assertStringContainsString('truth sources on two nodes', $logged);
    }

    /**
     * Only what this node owns is offered. Passing on a collection it merely holds a copy of
     * would make it a second source of somebody else's state — the defect, not the fix.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testANewlyLinkedNodeIsOfferedTheCollectionsThisNodeOwns(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->handshaked('node-c');

        $this->assertSame(
            [[
                'nodeId' => 'node-c',
                'collectionKey' => DaemonManagerRtSyncPeerTestRtContext::ROWS,
                'rows' => [self::ROW_ID => ['id' => self::ROW_ID, 'name' => 'Ada']],
                'scopeKeys' => [],
            ]],
            $daemon->mesh->snapshots,
            'The collection this node owns travels whole, rows and all, under no scope',
        );
    }

    /**
     * An owner of named rows hands over those rows and says so with the scope. Without this the
     * fleet never converged: nothing was handed over at all, delivery has no retries (HIL-183),
     * and everything written while a link was down stayed lost.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testAnOwnerOfNamedRowsHandsOverThoseRowsUnderTheirScope(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => '9', 'name' => 'Grace']));
        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        $daemon->handshaked('node-c');

        $this->assertSame(
            [[
                'nodeId' => 'node-c',
                'collectionKey' => DaemonManagerRtSyncPeerTestRtContext::ROWS,
                'rows' => [self::ROW_ID => ['id' => self::ROW_ID, 'name' => 'Ada']],
                'scopeKeys' => [self::ROW_ID],
            ]],
            $daemon->mesh->snapshots,
            'The neighbour\'s row is held here but is not this node\'s to hand over',
        );
    }

    /**
     * The rotation store is not handed over on a new link (P-084): a ticket lives for seconds
     * and is spent once, so the tickets outstanding before a node existed are of no use to it,
     * and the deltas bring it everything it can ever trade.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testTheRotationStoreIsNotHandedOverToANodeThatJustLinked(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $rotations = $daemon->mountRotationStore();
        $rotations->add(StateHilosSessionRotation::create(self::TICKET, 'session-token', [], 0.0));
        $daemon->noteOwnAgent('session_agent', [StateHilosSessionRotation::RT_COLLECTION]);

        $daemon->handshaked('node-c');

        $this->assertSame([], $daemon->mesh->snapshots);
    }

    /**
     * And it stays out of the hand-over on the scoped branch as well - the exclusion is about
     * the collection, not about the shape of the claim over it.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testTheRotationStoreIsNotHandedOverUnderAScopeEither(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $rotations = $daemon->mountRotationStore();
        $rotations->add(StateHilosSessionRotation::create(self::TICKET, 'session-token', [], 0.0));
        $daemon->noteOwnAgent(
            'session_agent',
            [StateHilosSessionRotation::RT_COLLECTION],
            [],
            [StateHilosSessionRotation::RT_COLLECTION => [self::TICKET]],
        );

        $daemon->handshaked('node-c');

        $this->assertSame([], $daemon->mesh->snapshots);
    }

    /**
     * A collection this node owns only partly is not offered at all. A snapshot claims to be the
     * whole collection, and a partial owner's copy is not: the rows only the other owner writes
     * may be missing from it, and handing that over as the collection would delete them on the
     * receiving node.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testACollectionOwnedOnlyPartlyIsNotHandedOverAsASnapshot(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->noteOwnAgent(
            'rooms_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
        );

        $daemon->handshaked('node-c');

        $this->assertSame([], $daemon->mesh->snapshots);
    }

    public function testANodeThatOwnsNothingOffersNothingToTheNodeItLinkedTo(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();

        $daemon->handshaked('node-c');

        $this->assertSame([], $daemon->mesh->snapshots);
    }

    /**
     * The other order, and the one a fleet meets every time it starts (HIL-589): the nodes are
     * already linked, and an OWNER appears afterwards. The hand-over hangs off the handshake, so
     * nothing would ask again - and the row's only CREATE may already have gone unannounced,
     * having been written in the same breath as the claim that lets this node announce it. Every
     * write after that is an UPDATE, which a node without the row drops.
     *
     * @throws AgentException When routing the loop pass fails
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testAClaimThatArrivesAfterTheLinkIsOfferedToTheNodesAlreadyLinked(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->mesh->linked = ['node-c'];
        $daemon->offerOnOwnershipChange();
        $this->assertSame([], $daemon->mesh->snapshots, 'A node that owns nothing offers nothing');

        $daemon->noteOwnAgent(
            'worker_agent',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS],
            [],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );
        $daemon->offerOnOwnershipChange();

        $this->assertSame(
            [[
                'nodeId' => 'node-c',
                'collectionKey' => DaemonManagerRtSyncPeerTestRtContext::ROWS,
                'rows' => [self::ROW_ID => ['id' => self::ROW_ID, 'name' => 'Ada']],
                'scopeKeys' => [self::ROW_ID],
            ]],
            $daemon->mesh->snapshots,
            'The rows it has just started owning go to the node it was already linked to',
        );
    }

    /**
     * And nothing is offered while the ownership stands still, or a snapshot would follow every
     * delta - the deltas are what keeps a linked node current.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testOwnershipThatHasNotChangedOffersNothing(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->mesh->linked = ['node-c'];
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);
        $daemon->offerOnOwnershipChange();
        $this->assertCount(1, $daemon->mesh->snapshots);

        $daemon->offerOnOwnershipChange();
        $daemon->offerOnOwnershipChange();

        $this->assertCount(1, $daemon->mesh->snapshots, 'The same ownership is offered once, not per pass');
    }

    /**
     * A join is not a link. On a mesh of three this node hears of the third from the second and
     * marks it a member at once, with nothing to send over yet; the handshake that finally opens
     * the link merges no membership change and so asks nothing again. Hanging the hand-over off
     * the membership hook is how two nodes end up permanently without each other's collections.
     *
     * @throws InvalidFormatException When the test row is not one the state can be built from
     */
    public function testAMembershipJoinAloneHandsOverNothing(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = $daemon->mountCollection();
        $collection->add(DaemonManagerRtSyncPeerTestState::fromRow(['id' => self::ROW_ID, 'name' => 'Ada']));
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->nodeJoined('node-c');

        $this->assertSame([], $daemon->mesh->snapshots, 'The link the handshake opens is what carries this');
    }

    /**
     * Where a replica came from is written down as it is applied (HIL-711).
     *
     * Nothing else can say it afterwards: the row goes into the same collection as everything
     * else, and by the time a link drops, the frame that knew its origin is long gone. This is
     * the whole basis of telling a frozen copy from a current one.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaIsRememberedAsHavingComeFromTheNodeThatSentIt(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();

        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $this->assertSame(
            self::REMOTE_NODE,
            $daemon->originMap()->nodeOfRow(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID),
        );
    }

    /**
     * A deleted row has no origin left to remember. Kept, it would freeze on the next dropped
     * link a row that no longer exists — and the collection would report itself frozen over it.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAReplicaDeletedByItsOwnerIsForgotten(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));

        $daemon->receive($daemon->rtSyncDeleted());

        $this->assertNull(
            $daemon->originMap()->nodeOfRow(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID),
        );
    }

    /**
     * The fleet case (HIL-589), and the reason the mark is per row rather than per collection:
     * one collection has several remote owners at once, so losing one of them must freeze its
     * rows and leave every other row of the same collection current.
     *
     * @throws InvalidArgumentException When the signal name is empty
     * @throws InvalidFormatException When the local row is not one the state can be built from
     */
    public function testALostLinkFreezesThatNodesRowsAndNothingElse(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->receiveFrom('node-c', $daemon->rtSyncCreated('Ada', self::NEIGHBOUR_ROW_ID));
        $daemon->writeOwnRow(self::OWN_ROW_ID, 'Hedy');

        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $this->assertSame(
            self::FROZE_AT,
            RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID),
        );
        $this->assertNull(
            RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::NEIGHBOUR_ROW_ID),
            'A neighbour still linked keeps its rows current',
        );
        $this->assertNull(
            RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::OWN_ROW_ID),
            'A row this node wrote itself has no link that could stop it being current',
        );
    }

    /**
     * A frame about a frozen row IS freshness, whoever sends it — which is the case a node dying
     * for good produces: the leader re-places its fleet agents onto another node, that node
     * writes the SAME row ids, and its deltas arrive here. Nothing else would ever lift the mark,
     * because the two cues that do are a handshake with the node that is gone and a hand-over of
     * its rows.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testADeltaFromTheNodeThatTookTheRowOverMakesItCurrentAgain(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $daemon->receiveFrom('node-c', $daemon->rtSyncCreated('Ada'));

        $this->assertNull(RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID));
        $this->assertSame(
            'node-c',
            $daemon->originMap()->nodeOfRow(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID),
        );
    }

    /**
     * And a row its owner deleted takes its mark with it. Left behind, the mark would sit on a
     * row that no longer exists — with its origin forgotten, no later event could reach it, and
     * the collection would report itself frozen over a row nobody can see.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testADeletedRowTakesItsFrozenMarkWithIt(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $daemon->receiveFrom('node-c', $daemon->rtSyncDeleted());

        $this->assertNull(RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS));
    }

    /**
     * The link coming back is the first of the two cues that lift the mark, and it lifts it at
     * once rather than when the hand-over that follows lands: deltas already flow again.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testALinkComingBackMakesThatNodesRowsCurrentAgain(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $daemon->noteNodeReachable(self::REMOTE_NODE);

        $this->assertNull(RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID));
    }

    /**
     * The workers keep their own copy, so a freeze the master kept to itself would leave every
     * page on this node reading a frozen row as current. The frame rides the interest filter
     * with the rows themselves (HIL-717) — a worker that does not read the collection has
     * nothing to apply it to.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheWorkersAreToldWhichRowsFroze(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->workerServer->forgetFrames();

        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $this->assertSame([WorkerConstants::MESSAGE_RT_STALENESS], $daemon->workerServer->frameTypes());
    }

    /**
     * A worker that reads nothing of the collection is written nothing about it, for the reason
     * it is written no delta about it: it holds no copy the frame could be applied to.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAWorkerThatDoesNotReadTheCollectionIsNotToldItFroze(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->workerReads(StateHilosSessionRotation::RT_COLLECTION);
        $daemon->workerServer->forgetFrames();

        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $this->assertSame([], $daemon->workerServer->frameTypes());
    }

    /**
     * The lift travels the same way, and only when something was actually frozen: both cues that
     * reach it run whether or not anything ever froze, and a frame saying nothing changed is a
     * socket write per worker for no reader at all.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheWorkersAreToldOfTheLiftOnlyWhenSomethingHadFrozen(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);
        $daemon->workerServer->forgetFrames();

        $daemon->noteNodeReachable(self::REMOTE_NODE);
        $daemon->noteNodeReachable(self::REMOTE_NODE);

        $this->assertSame([WorkerConstants::MESSAGE_RT_STALENESS], $daemon->workerServer->frameTypes());
    }

    /**
     * The second cue, and the reason the mark needs no expiry of its own (Design D8): a scoped
     * snapshot IS the owner's current answer about the rows it names, so applying one makes them
     * fresh by that very act.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAScopedSnapshotMakesTheRowsItCarriesCurrentAgain(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->mountCollection();
        $daemon->receive($daemon->rtSyncCreated('Grace'));
        $daemon->noteNodeUnreachable(self::REMOTE_NODE, self::FROZE_AT);

        $daemon->receiveSnapshot(
            DaemonManagerRtSyncPeerTestRtContext::ROWS,
            [self::ROW_ID => [
                DaemonManagerRtSyncPeerTestState::id => self::ROW_ID,
                DaemonManagerRtSyncPeerTestState::name => 'Grace',
            ]],
            [self::ROW_ID],
        );

        $this->assertNull(RtStaleness::staleSince(DaemonManagerRtSyncPeerTestRtContext::ROWS, self::ROW_ID));
    }

    /**
     * Builds the DB fact the two negative cases here offer where an RT one is expected.
     *
     * @return SignalDTO Signal announcing a created database row
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function dbSyncCreated(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DB),
            new SignalType(SignalTypeConstants::DB_SYNC_CREATED),
            new SignalName(SignalConstants::DB_SYNC_CREATED),
            new DbSyncCreatedSignalData('users', self::ROW_ID, ['id' => self::ROW_ID]),
        );
    }

    /**
     * The claim itself is what the leader is told, and it is told per agent (HIL-696): the
     * verdict it may reach stops ONE agent, so a node-level answer would name nobody to stop.
     * Both axes travel with it, and the identity a placement frame is addressed with comes off
     * the agent id, which is the only identity a worker report carries.
     */
    public function testWhatThisNodeReportsIsEachAgentsOwnClaim(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent(
            'rooms_agent:4',
            [DaemonManagerRtSyncPeerTestRtContext::ROWS, StateHilosSessionRotation::RT_COLLECTION],
            [StateHilosSessionRotation::RT_COLLECTION],
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
        );

        $claims = $daemon->ownClaims();

        $this->assertCount(1, $claims);
        $this->assertSame('rooms_agent:4', $claims[0]->agentId);
        $this->assertSame('rooms_agent', $claims[0]->agentType);
        $this->assertSame('4', $claims[0]->agentIndex);
        $this->assertSame(
            [StateHilosSessionRotation::RT_COLLECTION],
            $claims[0]->partialCollectionKeys,
            'A claim short of an operation says so, or a legitimate co-owner would read as a split',
        );
        $this->assertSame(
            [DaemonManagerRtSyncPeerTestRtContext::ROWS => [self::ROW_ID]],
            $claims[0]->keysByCollection,
        );
    }

    /**
     * An agent that stopped claims nothing, and the report says so rather than falling silent:
     * silence would leave the leader holding a right nobody has, and the next node to claim it
     * would be refused in favour of an agent that is gone.
     */
    public function testANodeWhoseAgentsStoppedReportsAnEmptyClaim(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);
        $daemon->noteOwnAgent('rooms_agent', []);

        $this->assertSame([], $daemon->ownClaims());
    }

    /**
     * The whole point of the guard, seen from the counter an acceptance run reads: the split is
     * named from the DECLARATIONS, before either owner has written anything, so the counter that
     * moves is the claim one and the replica counters stay where they were.
     */
    public function testTheLeaderNamesTheSplitBeforeAnyReplicaHasBeenSent(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;

        $daemon->applyRemoteRtClaims('node-b', [new PeerRtClaimEntry('library', 'library', null, [$collection])]);
        $daemon->applyRemoteRtClaims('node-c', [new PeerRtClaimEntry('twin', 'twin', null, [$collection])]);

        $inspect = $daemon->inspectRtReplicas();
        $this->assertSame(1, $inspect[ClusterCommandConstants::FIELD_RT_CLAIM_CONFLICTS]);
        $this->assertSame(
            0,
            $inspect[ClusterCommandConstants::FIELD_RT_REFUSED],
            'Nothing was written, so the replica path had nothing to refuse',
        );
    }

    /**
     * And the node the verdict is against counts it at its own end, which is what an operator
     * reading one node has to go on.
     */
    public function testTheRefusedNodeCountsTheVerdictAgainstIt(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();

        $daemon->applyRtClaimRefusal(new PeerRtClaimRefusedDTO(
            collectionKey: DaemonManagerRtSyncPeerTestRtContext::ROWS,
            stateIds: [],
            agentType: 'twin',
            agentIndex: null,
            agentId: 'twin',
            holderNodeId: 'node-b',
            holderAgentId: 'library',
        ));

        $this->assertSame(1, $daemon->inspectRtReplicas()[ClusterCommandConstants::FIELD_RT_CLAIM_REFUSALS]);
    }

    /**
     * A claim is ANNOUNCED to everybody, not addressed at the leader, and that is the difference
     * between a guard that works and one that is silent exactly where it is needed (HIL-696).
     * Consensus runs on the master set alone, so a data-plane node keeps an inert leadership seam
     * and can never name the leader — and data-plane nodes are where placed agents live. An
     * addressed report was dropped on every one of them, which the cluster stand caught: a second
     * owner ran for a full minute with nothing said.
     */
    public function testAClaimGoesToTheWholeMeshBecauseANodeCannotAlwaysNameTheLeader(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->reportClaims(null);

        $this->assertCount(1, $daemon->mesh->announcedClaims);
        $this->assertSame('rooms_agent', $daemon->mesh->announcedClaims[0][0]->agentId);
        $this->assertSame([], $daemon->mesh->claimsByNode, 'Nobody was singled out to hear it');
    }

    /**
     * The other cue names one peer — a link that has just appeared — and then only that peer is
     * short of the answer. Re-announcing to the whole mesh on every link would re-tell every
     * other node what it already holds, on the cue that fires most often of the three.
     */
    public function testALinkThatJustAppearedIsToldOnItsOwn(): void
    {
        $daemon = new DaemonManagerRtSyncPeerTestManager();
        $daemon->noteOwnAgent('rooms_agent', [DaemonManagerRtSyncPeerTestRtContext::ROWS]);

        $daemon->reportClaims(DaemonManagerRtSyncPeerTest::REMOTE_NODE);

        $this->assertSame([], $daemon->mesh->announcedClaims);
        $this->assertCount(1, $daemon->mesh->claimsByNode);
        $this->assertSame(DaemonManagerRtSyncPeerTest::REMOTE_NODE, $daemon->mesh->claimsByNode[0]['nodeId']);
        $this->assertSame('rooms_agent', $daemon->mesh->claimsByNode[0]['claims'][0]->agentId);
    }
}

/**
 * Daemon manager whose mesh and worker pool are stand-ins, with the private announce step and
 * the signal drain around it reachable from a test.
 */
final class DaemonManagerRtSyncPeerTestManager extends DaemonManager
{
    /** The stand-in mesh every announcement is written to */
    public readonly DaemonManagerRtSyncPeerTestMesh $mesh;

    /** The stand-in worker pool a replica is handed to */
    public readonly DaemonManagerRtSyncPeerTestWorkerServer $workerServer;

    /** The peer server the dispatch pass is meant to find among the registered servers */
    public readonly PeerServer $peerServer;

    /** @var list<?RtSyncMesh> Port the announce step was handed, once per call */
    public array $announcedThrough = [];

    public function __construct()
    {
        parent::__construct();

        $this->mesh = new DaemonManagerRtSyncPeerTestMesh();
        $this->workerServer = new DaemonManagerRtSyncPeerTestWorkerServer();
        $this->workerServer->addWorker();
        $this->registerServer($this->workerServer);
        // A frame goes to the workers that read the collection, so a pool whose worker never said
        // what it reads would receive nothing at all - and every case below would pass for the
        // wrong reason. This is that worker's report, in the shape its own link would deliver.
        $this->workerReads(DaemonManagerRtSyncPeerTestRtContext::ROWS, StateHilosSessionRotation::RT_COLLECTION);

        $this->peerServer = new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            [],
        );
        $this->registerServer($this->peerServer);
    }

    /**
     * Records what the one worker of this pool reads, as its own report to the master would.
     *
     * Replacement and not a delta, because that is what the report is: the list a worker sends is
     * everything it reads, so calling this again says what the worker reads NOW.
     *
     * @param string ...$collectionKeys RT collections that worker reads
     */
    public function workerReads(string ...$collectionKeys): void
    {
        $this->getAgentManagerDaemon()->handleSourceInterest(
            new WorkerSourceInterestDTO(array_values($collectionKeys), []),
            DaemonManagerRtSyncPeerTestWorkerClient::WORKER_INDEX,
        );
    }

    /**
     * Records what a SECOND worker of this pool reads, so the union has two holders behind it.
     *
     * No client stands behind this index, and none is wanted: what the cases using it are about
     * is what the node announces to the mesh, which is the map's business and not the pool's.
     *
     * @param string ...$collectionKeys RT collections that worker reads
     */
    public function anotherWorkerReads(string ...$collectionKeys): void
    {
        $this->getAgentManagerDaemon()->handleSourceInterest(
            new WorkerSourceInterestDTO(array_values($collectionKeys), []),
            DaemonManagerRtSyncPeerTestWorkerClient::WORKER_INDEX + 1,
        );
    }

    /**
     * Drops what one worker of this pool read, as the closing of its link would.
     *
     * @param int $workerIndex Index of the worker whose link closed
     */
    public function workerLinkClosed(int $workerIndex): void
    {
        $this->getAgentManagerDaemon()->releaseReaderInterestOfWorker($workerIndex);
    }

    /**
     * Runs the loop step that tells the mesh what this node reads.
     */
    public function announceInterest(): void
    {
        $this->announceReaderInterest($this->mesh);
    }

    /**
     * Mounts the runtime of this node and hands back the collection these cases replicate.
     *
     * The collection is taken from the context that built it rather than looked up by key:
     * reaching backing RT state from outside `Runtime/` is what the RT-STATE-REACH guard
     * refuses, and a test is no exception to it.
     *
     * @return DaemonManagerRtSyncPeerTestStates Empty collection a replica is applied to
     */
    public function mountCollection(): DaemonManagerRtSyncPeerTestStates
    {
        return $this->mountRuntime()->rows;
    }

    /**
     * Mounts the runtime of this node and hands back the framework store a master co-writes.
     *
     * The real collection rather than a stand-in keyed like it: what the cases around it turn on
     * is which collection the frames name, and naming it any other way would prove the exemption
     * for a key that does not exist.
     *
     * @return HilosSessionRotations Empty rotation store, as a node carrying sessions holds it
     */
    public function mountRotationStore(): HilosSessionRotations
    {
        return $this->mountRuntime()->rotations;
    }

    /**
     * Builds the RT sync fact these cases travel, as the runtime of the owning node produces it.
     *
     * @param string $name Row label the fact carries
     * @param string $stateId Row the fact is about
     * @return SignalDTO Signal announcing that row
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function rtSyncCreated(string $name, string $stateId = DaemonManagerRtSyncPeerTest::ROW_ID): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            new SignalName(SignalConstants::RT_SYNC_CREATED),
            new RtSyncCreatedSignalData(
                DaemonManagerRtSyncPeerTestRtContext::ROWS,
                $stateId,
                [
                    DaemonManagerRtSyncPeerTestState::id => $stateId,
                    DaemonManagerRtSyncPeerTestState::name => $name,
                ],
            ),
        );
    }

    /**
     * Builds the fact the owning node produces when it drops the row these cases sync.
     *
     * @param string $stateId Row the fact is about
     * @return SignalDTO Signal announcing that the row is gone
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function rtSyncDeleted(string $stateId = DaemonManagerRtSyncPeerTest::ROW_ID): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_DELETED),
            new SignalName(SignalConstants::RT_SYNC_DELETED),
            new RtSyncDeletedSignalData(DaemonManagerRtSyncPeerTestRtContext::ROWS, $stateId),
        );
    }

    /**
     * Builds the fact a master produces when a handshake spends a rotation ticket.
     *
     * @param string $ticket One-time ticket the handshake traded
     * @return SignalDTO Signal announcing that the rotation row is gone
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function rotationBurned(string $ticket): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_DELETED),
            new SignalName(SignalConstants::RT_SYNC_DELETED),
            new RtSyncDeletedSignalData(StateHilosSessionRotation::RT_COLLECTION, $ticket),
        );
    }

    /**
     * Builds the fact a master produces when it writes its own node's freeze row.
     *
     * @return SignalDTO Signal announcing this node's protected-mode state
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function protectedModeFrozen(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            new SignalName(SignalConstants::RT_SYNC_UPDATED),
            new RtSyncUpdatedSignalData(
                StateProtectedModeRuntime::RT_ITEM,
                StateProtectedModeRuntime::ID,
                [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    /**
     * Queues an RT write made on this node, the way the runtime does.
     *
     * @param string $name Row label the write carries
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function queueRtSyncCreated(string $name): void
    {
        $signal = $this->rtSyncCreated($name);
        Hilos::$sr->queueSignal(
            signalSource: $signal->signalSource,
            signalType: $signal->signalType,
            signalName: $signal->signalName,
            signalData: $signal->data,
        );
    }

    /**
     * Runs the loop step that dispatches whatever is queued.
     *
     * @throws AgentException When routing a queued signal fails
     */
    public function dispatch(): void
    {
        $drain = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $drain($this);
    }

    /**
     * Offers one signal to the mesh, skipping the rest of the dispatch pass.
     *
     * @param SignalDTO $signal Signal the announce step judges
     */
    public function announce(SignalDTO $signal): void
    {
        $this->broadcastRtSyncToPeers($this->mesh, $signal);
    }

    /**
     * Offers one signal to the announce step of a node that has no mesh at all.
     *
     * @param SignalDTO $signal Signal the announce step judges
     */
    public function announceWithoutMesh(SignalDTO $signal): void
    {
        $this->broadcastRtSyncToPeers(null, $signal);
    }

    /**
     * Records an agent of this node as the truth source of some collections, as its worker
     * reports after the agent's onStart.
     *
     * @param string $agentId Agent that registered here
     * @param list<string> $collectionKeys RT collections it owns
     * @param list<string> $partialCollectionKeys Those of them it owns with only part of the operations
     * @param array<string, list<string>> $keysByCollection Those of them it claimed by key, and the keys
     */
    public function noteOwnAgent(
        string $agentId,
        array $collectionKeys,
        array $partialCollectionKeys = [],
        array $keysByCollection = [],
    ): void {
        $this->agentManagerDaemon->handleRtSourceRegistered(
            new WorkerRtSourceRegisteredDTO($agentId, $collectionKeys, $partialCollectionKeys, $keysByCollection),
        );
    }

    /**
     * What this node would report to the leader, as the claim frame carries it.
     *
     * @return list<PeerRtClaimEntry> One entry per agent of this node that owns anything
     */
    public function ownClaims(): array
    {
        return $this->agentManagerDaemon->rtNodeSourceMap()->claims();
    }

    /**
     * Runs the loop step that offers this node's RT state when its ownership has changed.
     */
    public function offerOnOwnershipChange(): void
    {
        $this->offerRtSnapshotsOnOwnershipChange($this->mesh);
    }

    /**
     * Runs the claim report the way one of its cues does: to the whole mesh, or to one node.
     *
     * @param ?string $nodeId Node to report to, or null to announce to the whole mesh
     */
    public function reportClaims(?string $nodeId): void
    {
        $this->sendRtClaims($this->mesh, $nodeId);
    }

    /**
     * Delivers a replica the way a handshaked link does.
     *
     * @param SignalDTO $signal RT sync fact another node announced
     * @param bool $partialOwner Whether the announcing node marked itself a partial owner
     */
    public function receive(SignalDTO $signal, bool $partialOwner = false): void
    {
        $this->receiveFrom(DaemonManagerRtSyncPeerTest::REMOTE_NODE, $signal, $partialOwner);
    }

    /**
     * Delivers a replica the way a handshaked link to a NAMED node does.
     *
     * The fleet cases need a second remote owner writing rows of the same collection, which is
     * the arrangement ownership by keys allows (HIL-589) and the one a per-collection mark would
     * get wrong.
     *
     * @param string $originNodeId Node the write happened on
     * @param SignalDTO $signal RT sync fact that node announced
     * @param bool $partialOwner Whether the announcing node marked itself a partial owner
     */
    public function receiveFrom(string $originNodeId, SignalDTO $signal, bool $partialOwner = false): void
    {
        $this->applyRemoteRtSync(
            $originNodeId,
            $signal->signalType->getType(),
            $signal,
            $partialOwner,
        );
    }

    /**
     * Delivers a whole collection the way the node that owns it hands it over.
     *
     * @param string $collectionKey RT collection the owner hands over
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @param list<string> $scopeKeys Rows the owner speaks for; empty when it hands over the collection
     */
    public function receiveSnapshot(string $collectionKey, array $rows, array $scopeKeys = []): void
    {
        $this->applyRemoteRtSnapshot(
            DaemonManagerRtSyncPeerTest::REMOTE_NODE,
            $collectionKey,
            $rows,
            $scopeKeys,
        );
    }

    /**
     * Reports a link to another node, the way the transport does once it handshakes.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function handshaked(string $nodeId): void
    {
        $this->handOverRtSnapshots($nodeId);
    }

    /**
     * What this node has written down about where its replicas came from.
     *
     * @return RtReplicaOriginMap Origin map as the frames received so far have filled it
     */
    public function originMap(): RtReplicaOriginMap
    {
        return $this->agentManagerDaemon->rtReplicaOriginMap();
    }

    /**
     * Puts one row into the collection under test as an agent of THIS node would have.
     *
     * A locally written row is what the freezing cases are judged against: no remote node stands
     * behind it, so no dropped link may touch it. Put there directly and not through a frame,
     * because a frame is precisely what would make it a replica.
     *
     * @param string $stateId Row to write
     * @param string $name Row label to write
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public function writeOwnRow(string $stateId, string $name): void
    {
        $this->mountCollection()->add(DaemonManagerRtSyncPeerTestState::fromRow([
            DaemonManagerRtSyncPeerTestState::id => $stateId,
            DaemonManagerRtSyncPeerTestState::name => $name,
        ]));
    }

    /**
     * Reports a node joining the mesh, through the daemon's own membership hook.
     *
     * @param string $nodeId Node that joined
     */
    public function nodeJoined(string $nodeId): void
    {
        $this->onNodeJoined(ClusterNode::fromIdentity(
            NodeIdentity::of($nodeId, NodeRole::Master, []),
            online: true,
            lastSeen: 0.0,
        ));
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerRtSyncPeerTestAgentManagerDaemon();
    }

    /**
     * Records the port the announce step was handed and sends it on to the recording mesh.
     *
     * The real {@see PeerServer} is final and announces over live links, so the fake stands one
     * port lower: what the dispatch pass found is asserted from {@see $announcedThrough}, and
     * what it announced from the mesh. A pass that found nothing is passed on as nothing, or the
     * off-cluster case would be answered by the substitution rather than by the code.
     *
     * @param ?RtSyncMesh $mesh Peer server the dispatch pass found, or null off-cluster
     * @param SignalDTO $signal Signal being dispatched
     */
    protected function broadcastRtSyncToPeers(?RtSyncMesh $mesh, SignalDTO $signal): void
    {
        $this->announcedThrough[] = $mesh;

        parent::broadcastRtSyncToPeers($mesh === null ? null : $this->mesh, $signal);
    }

    /**
     * Redirects the snapshots this node hands over to the recording mesh, for the reason given
     * on {@see broadcastRtSyncToPeers()}.
     *
     * @param ?RtSyncMesh $mesh Peer server this node found, or null off-cluster
     * @param string $nodeId Node that joined
     */
    protected function sendRtSnapshotsToNode(?RtSyncMesh $mesh, string $nodeId): void
    {
        parent::sendRtSnapshotsToNode($mesh === null ? null : $this->mesh, $nodeId);
    }

    /**
     * Mounts this node's runtime once, so a case can reach two collections of the same context.
     *
     * @return DaemonManagerRtSyncPeerTestRtContext Runtime of this node, with its collections
     */
    private function mountRuntime(): DaemonManagerRtSyncPeerTestRtContext
    {
        if (Hilos::$rt instanceof DaemonManagerRtSyncPeerTestRtContext) {
            return Hilos::$rt;
        }

        $context = new DaemonManagerRtSyncPeerTestRtContext();
        $context->configure();
        Hilos::$rt = $context;

        return $context;
    }
}

final class DaemonManagerRtSyncPeerTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A signal payload that carries no collection at all, as a frame built against another
 * version of this transport could.
 */
final class DaemonManagerRtSyncPeerTestOpaqueSignalData implements SignalDataInterface
{
    /**
     * @return array<string, mixed> Nothing a sync step could read
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Signal payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * A mesh that keeps what was announced to it instead of holding links to other nodes.
 */
final class DaemonManagerRtSyncPeerTestMesh implements RtClaimMesh, RtSyncMesh, SourceInterestMesh
{
    /** @var list<string> Nodes this stand-in reports a live link to */
    public array $linked = [];

    /** @var list<list<PeerRtClaimEntry>> Claim reports announced to the whole mesh, in order */
    public array $announcedClaims = [];

    /** @var list<array{nodeId: string, claims: list<PeerRtClaimEntry>}> Claim reports sent to one node, in order */
    public array $claimsByNode = [];

    /**
     * @param list<PeerRtClaimEntry> $claims What each agent of the announcing node owns
     */
    public function announceRtClaims(array $claims): void
    {
        $this->announcedClaims[] = $claims;
    }

    /**
     * @param string $nodeId Node told on its own
     * @param list<PeerRtClaimEntry> $claims What each agent of the reporting node owns
     */
    public function sendRtClaimsToNode(string $nodeId, array $claims): void
    {
        $this->claimsByNode[] = ['nodeId' => $nodeId, 'claims' => $claims];
    }

    /**
     * Not exercised here: a fresh leader's re-query is answered through the sink, not sent by it.
     */
    public function broadcastRtClaimsQuery(): void
    {
    }

    /**
     * Not exercised here: the verdict travels the leader's own send, which this stand-in never is.
     *
     * @param string $nodeId Node whose claim lost
     * @param PeerRtClaimRefusedDTO $refusal What was claimed and who holds it
     */
    public function sendRtClaimRefused(string $nodeId, PeerRtClaimRefusedDTO $refusal): void
    {
    }

    /** @var list<list<string>> Reader-interest lists announced to the mesh, in order */
    public array $interests = [];

    /** @var list<list<string>> Database reader-interest lists announced to the mesh, in order */
    public array $dbInterests = [];

    /**
     * @param list<string> $rtCollections RT collections the announcing node reads
     * @param list<string> $dbCollections DB collections the announcing node reads
     */
    public function announceSourceInterest(array $rtCollections, array $dbCollections): void
    {
        $this->interests[] = $rtCollections;
        $this->dbInterests[] = $dbCollections;
    }

    /** @var list<DaemonManagerRtSyncPeerTestAnnouncement> Facts offered to the mesh, in order */
    public array $announcements = [];

    /** @var list<array{nodeId: string, collectionKey: string, rows: array<string, array<string, mixed>>}> Snapshots handed over, in order */
    public array $snapshots = [];

    /**
     * @param string $signalType RT sync signal type being announced
     * @param SignalDTO $signal RT sync signal the other nodes apply
     * @param bool $partialOwner Whether the announcing node holds only part of the right
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal, bool $partialOwner = false): void
    {
        $this->announcements[] = new DaemonManagerRtSyncPeerTestAnnouncement($signalType, $signal, $partialOwner);
    }

    /**
     * @return list<string> Nodes this stand-in has been told are reachable
     */
    public function linkedNodeIds(): array
    {
        return $this->linked;
    }

    /**
     * @param string $nodeId Node that joined
     * @param string $collectionKey RT collection this node owns
     * @param array<string, array<string, mixed>> $rows Rows by state id
     * @param list<string> $scopeKeys Rows this node speaks for; empty when it owns the collection
     */
    public function sendRtSnapshotToNode(
        string $nodeId,
        string $collectionKey,
        array $rows,
        array $scopeKeys = [],
    ): void {
        $this->snapshots[] = [
            'nodeId' => $nodeId,
            'collectionKey' => $collectionKey,
            'rows' => $rows,
            'scopeKeys' => $scopeKeys,
        ];
    }

    /**
     * @return DaemonManagerRtSyncPeerTestAnnouncement The one fact this node announced
     * @throws RuntimeException When it announced any other number of facts
     */
    public function singleAnnouncement(): DaemonManagerRtSyncPeerTestAnnouncement
    {
        if (count($this->announcements) !== 1) {
            throw new RuntimeException('One RT write announces exactly one fact.');
        }

        return $this->announcements[0];
    }
}

/**
 * One announcement as the mesh received it.
 */
final readonly class DaemonManagerRtSyncPeerTestAnnouncement
{
    /**
     * @param string $signalType RT sync signal type that was announced
     * @param SignalDTO $signal Signal that was announced
     * @param bool $partialOwner Whether the announcing node marked itself a partial owner
     */
    public function __construct(
        public string $signalType,
        public SignalDTO $signal,
        public bool $partialOwner = false,
    ) {
    }
}

/**
 * A worker server that records what the daemon wrote to its pool, instead of holding sockets.
 */
final class DaemonManagerRtSyncPeerTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * Adds the one worker these cases fan out to.
     */
    public function addWorker(): void
    {
        $this->clients[] = new DaemonManagerRtSyncPeerTestWorkerClient();
    }

    /**
     * @return list<string> Message type of each frame written to the pool, in order
     */
    public function frameTypes(): array
    {
        $types = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerRtSyncPeerTestWorkerClient) {
                $types = [...$types, ...$client->frameTypes()];
            }
        }

        return $types;
    }

    /**
     * Drops what has been written so far, so a case can assert on what one step wrote.
     *
     * The freezing cases need a replica in place before they cut the link, and putting it there
     * is itself a write to the pool - without this, every one of them would be asserting on the
     * frames of its own setup.
     */
    public function forgetFrames(): void
    {
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerRtSyncPeerTestWorkerClient) {
                $client->forgetFrames();
            }
        }
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that keeps what was written to it, so the fan-out can be read back.
 */
final class DaemonManagerRtSyncPeerTestWorkerClient extends WorkerClient
{
    /** @var int Index the daemon addresses this link by, and the key its reader interest is held under */
    public const int WORKER_INDEX = 1;

    /** @var list<string> Raw frames the daemon wrote to this link, in order */
    private array $written = [];

    public function __construct()
    {
        $this->setWorkerIndex(self::WORKER_INDEX);
    }

    public function isRegistered(): bool
    {
        return true;
    }

    /**
     * @param string $data Frame the daemon wants written to this worker
     */
    public function send(string $data): void
    {
        $this->written[] = $data;
    }

    /**
     * Drops what has been written to this link so far.
     */
    public function forgetFrames(): void
    {
        $this->written = [];
    }

    /**
     * @return list<string> Message type of each frame written to this link, in order
     */
    public function frameTypes(): array
    {
        $types = [];
        foreach ($this->written as $frame) {
            $decoded = json_decode($frame, true);
            $type = is_array($decoded) ? $decoded[WorkerDTO::TYPE] ?? null : null;
            if (is_string($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }
}

/**
 * Runtime context holding the one collection these cases replicate.
 */
final class DaemonManagerRtSyncPeerTestRtContext extends RtContext
{
    public const string ROWS = 'daemonManagerRtSyncPeerTestRows';

    /** The mounted collection, kept so a case can read it back without a lookup by key */
    public DaemonManagerRtSyncPeerTestStates $rows;

    /** The framework store every master co-writes, mounted as a node carrying sessions has it */
    public HilosSessionRotations $rotations;

    /**
     * Registers the collection these cases replicate and the framework store beside it.
     */
    public function configure(): void
    {
        $this->rows = DaemonManagerRtSyncPeerTestStates::init();
        $this->_stateCollections[self::ROWS] = $this->rows;

        $this->rotations = HilosSessionRotations::init();
        $this->_stateCollections[StateHilosSessionRotation::RT_COLLECTION] = $this->rotations;
    }
}

final class DaemonManagerRtSyncPeerTestStates extends RtStates
{
    public const string STATE_CLASS = DaemonManagerRtSyncPeerTestState::class;
}

/**
 * The replicated row: an id and a label, which is all these cases read back.
 */
final class DaemonManagerRtSyncPeerTestState extends RtState
{
    public const string id = 'id';

    public const string name = 'name';

    private(set) string $id = '';

    public string $name = '';

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->name = self::requireString($row, self::name);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
        ];
    }
}

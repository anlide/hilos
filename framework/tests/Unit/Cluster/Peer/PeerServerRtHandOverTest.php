<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Core\Daemon\SignalDispatch;
use Closure;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtSyncSink;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtReplicaConfirmation;
use Hilos\TruthSource\RtReplicaOriginMap;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * When this node offers its RT collections to another one (HIL-586).
 *
 * The offer rides the handshake rather than the membership transition, and the difference is
 * not cosmetic: on a mesh of three, a node is a member here from the moment a peer mentions it,
 * which is before this node holds any link to it. The hand-over sent at that moment reaches
 * nothing, and the handshake that finally opens the link changes no membership, so nothing asks
 * again - the two nodes would sit forever without each other's collections.
 *
 * The cases about what a delta does are here for the same reason (HIL-717): a delta now leaves
 * only toward a node that said it reads the collection, so the snapshot and the delta - the
 * unaddressed half and the addressed half of one link - are judged side by side.
 */
final class PeerServerRtHandOverTest extends TestCase
{
    /** @var string What {@see self::frameTypesOf()} names a stream part opening a snapshot of a collection */
    private const string SNAPSHOT_PART = PeerRtSyncStreamDTO::MESSAGE_TYPE . ' ' . RtSyncStreamSnapshot::SIGNAL_TYPE;

    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var ?Socket Dummy socket kept alive for the link under test */
    private ?Socket $socket = null;

    /** @var list<Socket> Both ends of the pair the ordering cases read their frames back from */
    private array $pair = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_NAME=Local node');
        putenv('CLUSTER_NODE_ROLE=master');

        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }

        foreach ($this->pair as $socket) {
            socket_close($socket);
        }
        $this->pair = [];

        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testACompletedHandshakeAsksTheDaemonToHandOverItsRt(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));

        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', 'Node node-b', NodeRole::Master, []));

        $this->assertSame(['node-b'], $sink->handedOverTo);
    }

    /**
     * The other direction of the same cue (HIL-823): the peer is asked for what this node holds
     * nothing of, and asked after the hand-over rather than before it. The hand-over is the
     * cheaper route to the same rows wherever an owner exists, and the answer to the request is
     * filtered by the reader map the interest frame ahead of both fills in.
     */
    public function testACompletedHandshakeAlsoAsksThePeerForWhatThisNodeIsMissing(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));

        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', 'Node node-b', NodeRole::Master, []));

        $this->assertSame(['node-b'], $sink->askedOf);
        $this->assertSame(['node-b'], $sink->handedOverTo, 'The hand-over is not replaced by the request');
    }

    /**
     * A request that arrives reaches the daemon, which is the only side that can tell what this
     * node holds of the collections named.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testARequestThatArrivesReachesTheDaemon(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $this->feed($link, $far, new PeerRtSnapshotQueryDTO('node-b', ['unitRows']));

        $this->assertSame([['node-b', ['unitRows']]], $sink->answered);
    }

    /**
     * An offer that arrives reaches the daemon as a top-up rather than as a replacement, so the
     * origin it names is the node that WROTE the rows and travels through untouched.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testAnOfferThatArrivesReachesTheDaemonUnderTheOriginItNames(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $this->feed($link, $far, new PeerRtReplicaOfferDTO('node-c', 'unitRows', ['7' => ['id' => '7']]));

        $this->assertSame([['node-c', 'unitRows', ['7' => ['id' => '7']]]], $sink->offered);
    }

    /**
     * The set scope reaches the apply port with the empty row map: this is how a sender says it
     * deleted its last row while the neighbour was cut off.
     *
     * @throws SocketException When the pair under test refuses the queued frame
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testASetScopedSnapshotReachesTheDaemon(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $parts = [];
        $owner = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            },
            static fn (): null => null,
        );
        $owner->sendSnapshot('unitRows', RtSnapshotScope::sets(['42']), 'node-b', true);
        foreach ($parts as $part) {
            $this->feed($link, $far, new PeerRtSyncStreamDTO($part));
        }

        $this->assertSame([['node-b', 'unitRows', [], [], ['42']]], $sink->snapshots);
    }

    /**
     * A linked node's request for one more snapshot of a collection reaches the daemon, which cuts
     * the areas this node owns of it into the link's stream.
     *
     * @throws SocketException When the pair under test refuses the queued frame
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testASnapshotRequestReachesTheDaemon(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $this->feed($link, $far, new PeerRtSnapshotRequestDTO('node-b', 'unitRows'));

        $this->assertSame([['node-b', 'unitRows']], $sink->requested);
    }

    /**
     * A request naming another node than the one at the other end of the link is refused: a node
     * asks for its own copy alone, and the snapshot would go into the stream of the node it names.
     *
     * @throws SocketException When the pair under test refuses the queued frame
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testASnapshotRequestNamingAnotherNodeIsRefused(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $this->feed($link, $far, new PeerRtSnapshotRequestDTO('node-c', 'unitRows'));

        $this->assertSame([], $sink->requested);
    }

    /**
     * The first row the link's stream holds of a collection asks the linked node for a snapshot of
     * it, once: a second refusal waits for the one asked. A snapshot of the collection arriving on
     * the link is the answer, and the next refusal asks again.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testAHeldRowAsksTheLinkedNodeForOneSnapshotUntilOneArrives(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);
        $link->write();
        $this->drain($far);
        $parts = [];
        $node = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            },
            static fn (): null => null,
        );
        $refused = function (string $stateId) use ($node, &$parts, $link, $far): void {
            // A write naming a third node is refused on this link, and its row is held.
            $node->send(new RtSyncUpdatedSignalData('unitRows', $stateId, ['name' => 'x']), 'node-x');
            foreach (array_splice($parts, 0) as $part) {
                $this->feed($link, $far, new PeerRtSyncStreamDTO($part));
            }
        };

        $refused('7');
        $refused('8');
        $link->write();
        $requests = array_values(array_filter($this->frameTypesOf($far), static fn (string $type): bool => $type
            === PeerRtSnapshotRequestDTO::MESSAGE_TYPE));
        $this->assertSame([PeerRtSnapshotRequestDTO::MESSAGE_TYPE], $requests, 'One request per collection waits at a time');

        $node->sendSnapshot('unitRows', RtSnapshotScope::rows(['7']), 'node-b', true);
        foreach (array_splice($parts, 0) as $part) {
            $this->feed($link, $far, new PeerRtSyncStreamDTO($part));
        }
        $refused('9');
        $link->write();
        $requests = array_values(array_filter($this->frameTypesOf($far), static fn (string $type): bool => $type
            === PeerRtSnapshotRequestDTO::MESSAGE_TYPE));
        $this->assertSame([PeerRtSnapshotRequestDTO::MESSAGE_TYPE], $requests, 'The snapshot answered the request; a new hold asks again');
        RtStaleness::clear('unitRows', ['7', '8', '9']);
    }

    /**
     * A snapshot canceled here before its publication asks the linked node for another, and a hold
     * of the collection asks nothing more while that request waits. The second canceled in a row by
     * a live change of its area here is a writer every cut of that node misses - two owners: it is
     * logged and not asked again, and the next event, a hold, asks. A cancellation for another cause
     * ends the run, as the publication of a snapshot of the collection does.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testASnapshotCanceledTwiceInARowByAWriteHereIsNotAskedForAgain(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);
        $link->write();
        $this->drain($far);
        $parts = [];
        $node = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            },
            static fn (): null => null,
        );
        $feed = function () use (&$parts, $link, $far): void {
            foreach (array_splice($parts, 0) as $part) {
                $this->feed($link, $far, new PeerRtSyncStreamDTO($part));
            }
        };
        $snapshot = static function () use ($node, $feed): string {
            ob_start();
            $node->sendSnapshot('unitRows', RtSnapshotScope::rows(['7']), 'node-b', true);
            $feed();

            return (string)ob_get_clean();
        };
        $held = static function (string $stateId) use ($node, $feed): void {
            // A write naming a third node is refused on this link, and its row is held.
            $node->send(new RtSyncUpdatedSignalData('unitRows', $stateId, ['name' => 'x']), 'node-x');
            $feed();
        };
        $requests = function () use ($link, $far): int {
            $link->write();

            return count(array_filter($this->frameTypesOf($far), static fn (string $type): bool => $type
                === PeerRtSnapshotRequestDTO::MESSAGE_TYPE));
        };
        $sink->cancellations = [true, true, true, false, true];

        $snapshot();
        $this->assertSame(1, $requests(), 'A snapshot canceled by a write here asks for another');
        $held('7');
        $this->assertSame(0, $requests(), 'A hold asks nothing while the request waits');
        $logged = $snapshot();
        $this->assertSame(0, $requests(), 'The second canceled in a row by a write here is not asked for again');
        $this->assertStringContainsString("A write here canceled the RT snapshot of unitRows from node 'node-b' twice in a row:"
            . ' two owners of its area', $logged);
        $held('8');
        $this->assertSame(1, $requests(), 'The next event asks');
        $snapshot();
        $this->assertSame(0, $requests(), 'The run goes on while writes here cancel the snapshots asked');
        $held('9');
        $this->assertSame(1, $requests());
        $snapshot();
        $this->assertSame(1, $requests(), 'A cancellation for another cause asks again, and ends the run');
        $snapshot();
        $this->assertSame(1, $requests(), 'A write here canceling the next one is the first of a new run');
        $link->recoverRtSyncStream('unitRows');
        $sink->cancellations = [true];
        $snapshot();
        $this->assertSame(1, $requests(), 'A publication ends the run as well');
        RtStaleness::clear('unitRows', ['7', '8', '9']);
    }

    /**
     * The answer obeys the same reader filter the owner's hand-over does, and for the same
     * reason: a copy sent where no delta follows it stays as current as the second it landed.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testAnOfferIsNotSentToANodeThatNeverSaidItReadsTheCollection(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $link->write();
        $this->drain($far);

        $server->sendRtReplicaOfferToNode('node-b', 'node-c', 'unitRows', ['7' => ['id' => '7']]);
        $link->write();

        $this->assertSame([], $this->frameTypesOf($far));
    }

    /**
     * And the same node, once it says what it reads, is offered what this one holds — signed with
     * this node's own id when the rows are its own, since the transport is where that id lives.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testAnOfferOfThisNodesOwnRowsTravelsUnderThisNodesId(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        $this->drain($far);

        $server->sendRtReplicaOfferToNode('node-b', null, 'unitRows', ['7' => ['id' => '7']]);
        $link->write();

        $frames = $this->framesOf($far);
        $this->assertCount(1, $frames);
        $offer = $frames[0];
        $this->assertInstanceOf(PeerRtReplicaOfferDTO::class, $offer);
        $this->assertSame('node-a', $offer->originNodeId);
    }

    /**
     * The freezing half of the same cue (HIL-711): the link is what carries deltas, so the link
     * closing is the moment this node's copies of that peer's rows stop being kept up to date.
     *
     * @throws EnvException When the link cannot read its socket and keepalive settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws SocketException When the pair refuses the hello
     */
    public function testTheLastLinkToAPeerClosingFreezesTheReplicasItSent(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);

        $server->onLinkClosed($link);

        $this->assertSame(['node-b'], array_keys($sink->frozen));
        $this->assertGreaterThan(0.0, $sink->frozen['node-b'], 'The moment is this node\'s own clock');
    }

    /**
     * A peer already believed offline — marked so by its own leave frame while its link is still
     * up — losing its last link is exactly the case that must not go unnoticed: the registry
     * changes nothing, so the branch that reports a departure stays silent, while replication has
     * only now stopped.
     *
     * @throws EnvException When the link cannot read its socket and keepalive settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws SocketException When the pair refuses the hello
     */
    public function testAPeerAlreadyBelievedOfflineStillFreezesWhenItsLinkGoes(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->handshake($link, $far);
        $server->onNodeLeaving(new PeerNodeLeavingDTO('node-b', false, null));
        $sink->frozen = [];

        $server->onLinkClosed($link);

        $this->assertSame(['node-b'], array_keys($sink->frozen));
    }

    /**
     * The handshake tells the sink that the peer is reachable. The sink thaws its router row;
     * individual replicas wait for the hand-over or another confirmation.
     */
    public function testACompletedHandshakeNotifiesTheSinkThatPeerIsReachable(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));

        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', 'Node node-b', NodeRole::Master, []));

        $this->assertSame(['node-b'], $sink->thawed);
    }

    /**
     * The second handshake with a peer already in the registry - a reconnect, or the third node
     * this one had only heard about. It merges no membership change, so a hand-over waiting on
     * that change would never happen; this one does.
     */
    public function testAHandshakeWithAPeerAlreadyKnownHandsOverAllTheSame(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));
        $remote = NodeIdentity::of('node-b', 'Node node-b', NodeRole::Master, []);

        $server->onHandshakeComplete($link, $remote);
        $server->onHandshakeComplete($link, $remote);

        $this->assertSame(['node-b', 'node-b'], $sink->handedOverTo);
    }

    /**
     * The order a reconnecting node sees, and the reason no sequencing is built for it (D4).
     *
     * A snapshot is addressed ({@see PeerServer::sendToNode()}) and a delta is broadcast
     * ({@see PeerServer::broadcastToNodes()}), which looks like two roads until you follow them:
     * both end at {@see PeerLink::sendFrame()} on the SAME link, appending to one buffer that
     * drains in order. So a delta cannot overtake the snapshot it follows, and the guard against
     * it is the transport's shape rather than a counter - the same way HIL-586 answered the echo
     * with one hop instead of hop counters.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testASnapshotAndTheDeltaAfterItLeaveOnOneLinkInOrder(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        // Clears the welcome and roster the handshake itself queued, so what is asserted below is
        // the two frames these cases are about and nothing else.
        $this->drain($far);

        $server->streamRtSnapshotToNode('node-b', 'unitRows', RtSnapshotScope::rows(['7']), true);
        $server->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $this->rtSyncUpdated(), partialOwner: true);
        $link->write();

        $this->assertSame(
            [self::SNAPSHOT_PART, PeerRtSyncStreamDTO::MESSAGE_TYPE],
            $this->frameTypesOf($far),
            'One link, one buffer, FIFO: the delta cannot arrive before the snapshot it follows',
        );
    }

    /**
     * The other half of D4: the only way a second road could exist is a duplicate link, and a
     * link that lost the tie-break gets neither frame. Both sends look the remote identity up,
     * and {@see PeerLink::discardAsDuplicate()} clears it - which is also why the collapse has to
     * happen before the hand-over, as {@see PeerServer::onHandshakeComplete()} orders it.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testALinkDiscardedAsADuplicateIsSentNeitherOfThem(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        $this->drain($far);

        $link->discardAsDuplicate();
        $server->streamRtSnapshotToNode('node-b', 'unitRows', RtSnapshotScope::rows(['7']), true);
        $server->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $this->rtSyncUpdated(), partialOwner: true);

        $this->assertSame([], $this->frameTypesOf($far), 'A discarded link is nobody\'s address any more');
    }

    /**
     * The filter itself: a node that never said it reads the collection is written to at all.
     *
     * Both halves are asserted at once on purpose. Skipping the delta alone would leave the node
     * holding a copy that stays exactly as current as the second it arrived, and whatever started
     * reading it later would be served that - so the hand-over answers to the same interest the
     * delta does, and a node that reads nothing holds nothing.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testANodeThatNeverSaidItReadsTheCollectionIsSentNothingAboutIt(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $link->write();
        $this->drain($far);

        $server->streamRtSnapshotToNode('node-b', 'unitRows', RtSnapshotScope::rows(['7']), true);
        $server->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $this->rtSyncUpdated(), partialOwner: true);
        $link->write();

        $this->assertSame(
            [],
            $this->frameTypesOf($far),
            'Neither the copy nor the deltas after it: a node that reads nothing holds nothing',
        );
    }

    /**
     * And the same node, once it says what it reads, is handed the collection it now holds.
     *
     * The pair with the case above: what the filter turns on is the report, not the link, so the
     * hand-over a node missed by being silent is not lost - its own announcement asks again.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testANodeThatSaidItReadsTheCollectionIsHandedIt(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        $this->drain($far);

        $server->streamRtSnapshotToNode('node-b', 'unitRows', RtSnapshotScope::rows(['7']), true);
        $link->write();

        $this->assertSame([self::SNAPSHOT_PART], $this->frameTypesOf($far));
    }

    /**
     * A node reporting a shorter list has stopped reading what it left out, and stops being told.
     *
     * The report is a replacement, so this is the only way a reader ever goes away short of the
     * node itself leaving - and it is the ordinary one: the last page reading a collection closes
     * and the node goes on running everything else.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testANodeThatStoppedReadingIsNoLongerToldAboutTheCollection(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', [], [], 1));
        $link->write();
        $this->drain($far);

        $server->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $this->rtSyncUpdated(), partialOwner: true);
        $link->write();

        $this->assertSame([], $this->frameTypesOf($far), 'A key left out of a report is a key that stopped being read');
    }

    /**
     * Interest naming a collection for the first time asks the owner to hand it over.
     *
     * A node that has just started reading holds no copy, so the delta stream alone would land on
     * nothing: every write after the first is an update, and a node without the row drops it. The
     * hand-over is what gives the deltas something to land on.
     */
    public function testInterestNamingANewCollectionAsksTheDaemonToHandItOver(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));

        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows'], [], 1));

        $this->assertSame([['node-b', ['unitRows'], 1]], $sink->interestsAnswered);
    }

    /**
     * Interest that names nothing new does not, because the node already holds those copies.
     *
     * A node re-announces its whole list every time any part of it moves, so most reports repeat
     * keys the sender is already current on. Handing those over again would replace a copy the
     * deltas have kept fresh with one built before the frames in flight.
     */
    public function testInterestRepeatingWhatANodeAlreadyReadsHandsOverNothing(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $socket = $this->makeSocket();
        $link = new PeerLink($socket, $server, $local, dialer: false, transport: new PlainSocketTransport($socket));

        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows'], [], 1));
        $sink->interestsAnswered = [];
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows'], [], 2));

        $this->assertSame([['node-b', [], 2]], $sink->interestsAnswered, 'Answered, with nothing to hand over');
    }

    /**
     * What this node reads is told to a peer that links up later, not only to the ones linked now.
     *
     * The announcement is a broadcast, so it reaches the links of its own moment and no others. A
     * node joining afterwards would filter every frame away from this one, and nothing would ask
     * again until the interest happened to move - which on a settled node it never does.
     *
     * @throws SocketException When the pair under test refuses the queued frames
     * @throws HilosException When a queued frame refuses to become wire input
     */
    public function testAPeerLinkingUpLaterIsToldWhatThisNodeReads(): void
    {
        $this->registerSink();
        $local = NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $server->announceSourceInterest(['unitRows'], [], 1);

        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', 'Node node-b', NodeRole::Master, []));
        $link->write();

        $this->assertContains(
            PeerSourceInterestDTO::MESSAGE_TYPE,
            $this->frameTypesOf($far),
            'A peer that linked up after the announcement hears it on its handshake',
        );
    }

    /**
     * Delivers a peer's report that it reads the collection these cases replicate.
     *
     * @param PeerServer $server Server holding the node reader map
     * @param PeerLink $link Link the report is treated as having arrived on
     */
    private function declareInterest(PeerServer $server, PeerLink $link): void
    {
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows'], [], 1));
    }

    /**
     * Drives the real handshake over the pair, so the link learns who is on the other end.
     *
     * The identity is what both sends look a link up by, and only the frame exchange sets it -
     * calling the server's hook alone would leave the link unaddressable and the cases below
     * green for the wrong reason.
     *
     * @param PeerLink $link Accepting side of the pair
     * @param Socket $far Far end, standing in for the dialing node
     * @throws SocketException When the pair refuses the hello
     * @throws HilosException When the hello refuses to become a frame
     */
    private function handshake(PeerLink $link, Socket $far): void
    {
        $hello = new PeerHelloDTO(
            PeerProtocol::VERSION,
            'node-b',
            'Node node-b',
            NodeRole::Master,
            [],
            PeerTestMarkers::onWire(),
            null,
        );
        socket_write($far, $hello->toJson() . "\n");
        $link->read();
        socket_write($far, (new PeerReadyDTO(false))->toJson() . "\n");
        $link->read();
    }

    /**
     * Puts one link on the server's list, as an accepted connection would be.
     *
     * These cases open no real connection - there is nothing to dial to - so the link is placed
     * where the accept loop would have placed it. Through a bound closure rather than a subclass
     * or Reflection: {@see PeerServer} is final, and a bound closure is how a test already
     * reaches into the daemon next door.
     *
     * @param PeerServer $server Server the link belongs to
     * @param PeerLink $link Link to register as if it had been accepted
     */
    private function attach(PeerServer $server, PeerLink $link): void
    {
        $attach = Closure::bind(
            static function (PeerServer $server, PeerLink $link): void {
                $server->addClient($link);
                $server->attachClient($link);
            },
            null,
            PeerServer::class,
        );

        $attach($server, $link);
    }

    /**
     * Builds the RT fact the ordering cases announce after a hand-over.
     *
     * @return SignalDTO Signal announcing an updated row
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function rtSyncUpdated(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            new SignalName(SignalConstants::RT_SYNC_UPDATED),
            new RtSyncUpdatedSignalData('unitRows', '7', ['id' => '7']),
        );
    }

    /**
     * Opens a link over a socket pair, so what the link writes can be read back verbatim.
     *
     * A real pair rather than a probe over the buffer: {@see PeerLink} is final, and what these
     * cases are about is the bytes that leave it in order - which is exactly what a pair shows
     * and an accessor would only paraphrase.
     *
     * @param PeerServer $server Server the link belongs to
     * @param NodeIdentity $local Identity the link announces
     * @return array{PeerLink, Socket} The link under test and the far end of its pair
     * @throws EnvException When the link cannot read its socket and keepalive settings
     */
    private function makeLinkedPair(PeerServer $server, NodeIdentity $local): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->pair = $pair;

        // Every pair is handshaked as node-b ({@see handshake()}), so that is the name its
        // transport vouches for.
        return [
            new PeerLink($near, $server, $local, dialer: false, transport: new NamedPeerTestTransport($near, 'node-b')),
            $far,
        ];
    }

    /**
     * Delivers one frame to a handshaked link the way the node on the other end would.
     *
     * @param PeerLink $link Accepting side of the pair
     * @param Socket $far Far end, standing in for the sending node
     * @param PeerDTO $frame Frame that node sends
     * @throws SocketException When the pair refuses the frame
     * @throws HilosException When the frame refuses to become wire input
     */
    private function feed(PeerLink $link, Socket $far, PeerDTO $frame): void
    {
        socket_write($far, $frame->toJson() . "\n");
        $link->read();
    }

    /**
     * Parses back the frames that have arrived at the far end of a pair, in arrival order.
     *
     * The reading twin of {@see frameTypesOf()}, for the cases whose subject is a field inside
     * the frame rather than the fact that one was sent.
     *
     * @param Socket $far Far end of the pair
     * @return list<PeerDTO> Frames as the receiving node would parse them, oldest first
     * @throws PeerTransportException When a frame is not one this protocol knows
     */
    private function framesOf(Socket $far): array
    {
        $frames = [];
        foreach (explode("\n", $this->drain($far)) as $line) {
            if ($line === '') {
                continue;
            }

            $frames[] = PeerDTO::fromWire($line);
        }

        return $frames;
    }

    /**
     * Names the frame types that have arrived at the far end of a pair, in arrival order.
     *
     * @param Socket $far Far end of the pair
     * @return list<string> Wire message types, oldest first
     */
    private function frameTypesOf(Socket $far): array
    {
        $types = [];
        foreach (explode("\n", $this->drain($far)) as $line) {
            if ($line === '') {
                continue;
            }
            $frame = json_decode($line, true);
            $this->assertIsArray($frame, 'A link writes whole JSON frames or nothing');
            $type = $frame[PeerDTO::TYPE] ?? null;
            $this->assertIsString($type, 'Every peer frame names its own type');
            $part = $type === PeerRtSyncStreamDTO::MESSAGE_TYPE ? PeerRtSyncStreamDTO::fromArray($frame)->frame : null;
            $types[] = $part !== null && $part->isPart() && $part->part === 0
                && str_contains($part->data, '"' . RtSyncStreamSnapshot::SIGNAL_TYPE . '"') ? self::SNAPSHOT_PART : $type;
        }

        return $types;
    }

    /**
     * Reads whatever is waiting at the far end of a pair without blocking on an empty one.
     *
     * @param Socket $far Far end of the pair
     * @return string Bytes read, empty when nothing was waiting
     */
    private function drain(Socket $far): string
    {
        $read = socket_read($far, 65536);

        return $read === false ? '' : $read;
    }

    /**
     * Registers a stand-in for the daemon's RT seam and hands it back.
     *
     * @return RtSyncSink Sink recording the nodes it was asked to hand collections to
     */
    private function registerSink(): RtSyncSink
    {
        $sink = new class implements RtSyncSink {
            /** @var list<string> Nodes this one was asked to hand its collections to */
            public array $handedOverTo = [];

            /** @var list<string> Nodes this one was asked for what it is missing */
            public array $askedOf = [];

            /** @var list<array{string, list<string>}> Requests it was asked to answer */
            public array $answered = [];

            /** @var list<array{string, string, array<string, array<string, mixed>>}> Offers it was handed */
            public array $offered = [];

            /** @var list<array{string, string, array<string, array<string, mixed>>, list<string>, list<string>}> Snapshots */
            public array $snapshots = [];

            /** @var array<string, float> Nodes it was told had become unreachable, and when */
            public array $frozen = [];

            /** @var list<string> Nodes it was told were reachable again */
            public array $thawed = [];

            /** @var list<array{string, list<string>, int}> Announcements of what a node reads it was asked to answer */
            public array $interestsAnswered = [];

            /** @var list<array{string, int}> Answers to this node's announcements it was handed */
            public array $answersNoted = [];

            /**
             * @param string $originNodeId Id of the node the write happened on
             * @param string $signalType RT sync signal type the frame carried
             * @param SignalDTO $signal RT sync signal to apply
             * @param bool $partialOwner Whether the origin holds only part of the right
             * @param ?RtReplicaConfirmation $receipt Receipt taken when it arrived
                     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
            public function applyRemoteRtSync(
                string $originNodeId,
                string $signalType,
                SignalDTO $signal,
                bool $partialOwner = false,
                ?RtReplicaConfirmation $receipt = null,
        ?SignalDispatch $origin = null,
    ): void
            {
            }

            /**
             * @param string $originNodeId Id of the node the fact arrives from
             * @return RtReplicaConfirmation A receipt nothing here ever applies with
             */
            public function captureRtSyncReceipt(string $originNodeId): RtReplicaConfirmation
            {
                return new RtReplicaOriginMap()->captureConfirmation($originNodeId, new RtNodeSourceMap());
            }

            /** @var list<array{string, string}> Snapshot requests it was asked to answer, by node and collection */
            public array $requested = [];

            /**
             * @var list<bool> How the stagings of the next snapshots end before their publication, each telling whether a
             *      live change here canceled it; a snapshot with none left is kept
             */
            public array $cancellations = [];

            /**
             * Takes the snapshot's rows as they arrive and keeps them with its area once it is whole.
             *
             * @param string $originNodeId Id of the node that owns the area
             * @param RtSyncStreamSnapshot $snapshot Snapshot at its head
             * @param Closure(bool): void $settled The stream's answer
             * @param Closure(): void $recover Lifts the link's holds of the collection
             * @param Closure(bool): void $askAgain Asks the node for another snapshot, told whether a live change canceled this one
             * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     * @return Generator<int, RtWork, int, void> The staging
                     */
            public function stageRemoteRtSnapshot(
                string $originNodeId,
                RtSyncStreamSnapshot $snapshot,
                Closure $settled,
                Closure $recover,
                Closure $askAgain,
        ?SignalDispatch $origin = null,
    ): Generator {
                $rows = [];
                while (!$snapshot->isWhole() || $snapshot->hasRows()) {
                    $portion = $snapshot->takeRows();
                    if ($portion === null) {
                        yield new RtWork();
                        continue;
                    }
                    foreach ($portion->ids as $position => $stateId) {
                        $rows[$stateId] = RtRowValues::nativeMap($portion->values[$position]);
                    }
                }
                if ($this->cancellations !== []) {
                    $settled(false);
                    $askAgain(array_shift($this->cancellations));

                    return;
                }
                $this->snapshots[] = [$originNodeId, $snapshot->collectionKey, $rows, $snapshot->scope->rowKeys() ?? [],
                    $snapshot->scope->setKeys() ?? []];
                $settled(true);
            }

            /**
             * @param string $nodeId Node that asked
             * @param string $collectionKey RT collection it asked about
             */
            public function answerRtSnapshotRequest(string $nodeId, string $collectionKey): void
            {
                $this->requested[] = [$nodeId, $collectionKey];
            }

            /**
             * @param string $nodeId Node this one can now reach
             */
            public function handOverRtSnapshots(string $nodeId): void
            {
                $this->handedOverTo[] = $nodeId;
            }

            /**
             * @param string $nodeId Node that announced
             * @param list<string> $addedCollectionKeys RT collections the announcement added
             * @param int $announcement Number of the announcement
                     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
            public function answerSourceInterest(string $nodeId, array $addedCollectionKeys, int $announcement, ?SignalDispatch $origin = null): void
            {
                $this->interestsAnswered[] = [$nodeId, $addedCollectionKeys, $announcement];
            }

            /**
             * @param string $nodeId Node that answered
             * @param int $announcement Number of the announcement it answered
                     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
            public function noteSourceInterestAnswered(string $nodeId, int $announcement, ?SignalDispatch $origin = null): void
            {
                $this->answersNoted[] = [$nodeId, $announcement];
            }

            /**
             * @param string $nodeId Node this one can now reach
             */
            public function askRtSnapshotsFromNode(string $nodeId): void
            {
                $this->askedOf[] = $nodeId;
            }

            /**
             * @param string $nodeId Node that asked
             * @param list<string> $collectionKeys RT collections it asked about
             */
            public function answerRtSnapshotQuery(string $nodeId, array $collectionKeys): void
            {
                $this->answered[] = [$nodeId, $collectionKeys];
            }

            /**
             * @param string $originNodeId Id of the node that wrote these rows
             * @param string $collectionKey RT collection being topped up
             * @param array<string, array<string, mixed>> $rows Rows by state id
                     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
            public function applyRemoteRtReplicaOffer(
                string $originNodeId,
                string $collectionKey,
                array $rows,
        ?SignalDispatch $origin = null,
    ): void {
                $this->offered[] = [$originNodeId, $collectionKey, $rows];
            }

            /**
             * @param string $nodeId Node that can no longer be reached
             * @param float $at Microtime of this node's clock when the link closed
             */
            public function noteNodeUnreachable(string $nodeId, float $at): void
            {
                $this->frozen[$nodeId] = $at;
            }

            /**
             * @param string $nodeId Node this one can reach again
             */
            public function noteNodeReachable(string $nodeId): void
            {
                $this->thawed[] = $nodeId;
            }
        };
        Hilos::$cluster->registerRtSyncSink($sink);

        return $sink;
    }

    /**
     * Creates a bare socket that satisfies the link constructor without any I/O.
     *
     * @return Socket Unconnected socket kept alive for the test's lifetime
     */
    private function makeSocket(): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->socket = $socket;

        return $socket;
    }
}

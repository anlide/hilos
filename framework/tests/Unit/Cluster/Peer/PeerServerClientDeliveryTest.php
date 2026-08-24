<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClientSignalSink;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerClientFanoutDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsDeltaDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsSnapshotDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The peer server's browser-facing half: offering this node's connections, taking in another
 * node's, and executing a signal forwarded to a browser here (HIL-668).
 *
 * The offer rides the handshake for the reason the RT hand-over does
 * ({@see PeerServerRtHandOverTest}): membership is the wrong cue, because on a mesh of three a
 * node is a member from the moment a peer mentions it — well before this node holds any link
 * to reach it with — and the handshake that finally opens the link changes no membership, so
 * nothing would ever ask again. Two nodes would then sit forever unable to answer each other's
 * browsers, which is the very defect this ticket exists to close.
 *
 * The receiving side is pinned here too, and its load-bearing property is that it STOPS: a
 * received announcement is applied and never passed on. Phase-1 of this cluster died on a
 * gossip echo, and every frame added since has had to be structurally incapable of one.
 */
final class PeerServerClientDeliveryTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the case */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the case */
    private ?ClusterContext $previousCluster = null;

    /** @var ?Socket Dummy socket kept alive for the link under test */
    private ?Socket $socket = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }

        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testACompletedHandshakeAsksTheDaemonToHandOverItsConnections(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();

        $server->onHandshakeComplete($this->makeLink($server), NodeIdentity::of('node-b', NodeRole::Master, []));

        $this->assertSame(['node-b'], $sink->handedOverTo);
    }

    /**
     * The second handshake with a peer already in the registry — a reconnect, or the third node
     * this one had only heard about. It merges no membership change, so an offer waiting on that
     * change would never happen; this one does. And it has to: while the link was down this node
     * accepted and lost browsers the peer heard nothing about.
     */
    public function testAHandshakeWithAKnownPeerHandsOverAllTheSame(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();
        $link = $this->makeLink($server);
        $remote = NodeIdentity::of('node-b', NodeRole::Master, []);

        $server->onHandshakeComplete($link, $remote);
        $server->onHandshakeComplete($link, $remote);

        $this->assertSame(['node-b', 'node-b'], $sink->handedOverTo);
    }

    public function testAReceivedSnapshotLandsInThisNodesIndex(): void
    {
        $index = $this->registerIndex();
        $server = $this->makeServer();

        $server->onConnectionsSnapshotReceived(
            $this->makeLink($server),
            new PeerConnectionsSnapshotDTO('node-b', ['ak-1']),
        );

        $this->assertSame('node-b', $index->nodeFor('ak-1'));
    }

    public function testAReceivedDeltaLandsInThisNodesIndex(): void
    {
        $index = $this->registerIndex();
        $server = $this->makeServer();
        $link = $this->makeLink($server);
        $server->onConnectionsSnapshotReceived($link, new PeerConnectionsSnapshotDTO('node-b', ['ak-1']));

        $server->onConnectionsDeltaReceived($link, new PeerConnectionsDeltaDTO('node-b', ['ak-2'], ['ak-1']));

        $this->assertNull($index->nodeFor('ak-1'));
        $this->assertSame('node-b', $index->nodeFor('ak-2'));
    }

    /**
     * A node that never registered an index is one whose daemon is not up yet, or is not a
     * daemon at all. Dropping the frame keeps a frame that arrived early from ending the loop.
     */
    public function testAnAnnouncementWithNoIndexRegisteredIsDropped(): void
    {
        $server = $this->makeServer();

        $server->onConnectionsSnapshotReceived(
            $this->makeLink($server),
            new PeerConnectionsSnapshotDTO('node-b', ['ak-1']),
        );

        $this->assertNull(Hilos::$cluster->clientConnections());
    }

    /**
     * The forward is executed, not re-routed: the sending node already decided, and deciding
     * again here is how a frame would loop or fan out twice.
     */
    public function testAForwardedClientSignalIsHandedToTheLocalSocket(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();

        $server->onClientSignalReceived(
            $this->makeLink($server),
            new PeerClientSignalDTO('node-b', 'node-a', 'ak-1', $this->innerSignal()),
        );

        $this->assertSame([['ak-1', 'room_renamed']], $sink->delivered);
    }

    /**
     * A frame addressed to somebody else is dropped rather than delivered: the accept key it
     * names belongs to another node's socket table, and any key of ours it happened to match
     * would be a different browser entirely.
     */
    public function testAClientSignalAddressedToAnotherNodeIsDropped(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();

        $server->onClientSignalReceived(
            $this->makeLink($server),
            new PeerClientSignalDTO('node-b', 'node-c', 'ak-1', $this->innerSignal()),
        );

        $this->assertSame([], $sink->delivered);
    }

    /**
     * A fan-out arrives undecided on purpose — the sending node could not know who here is
     * subscribed — so the receiving side is where it is expanded, against this node's own
     * registry and its own sockets.
     */
    public function testAReceivedFanoutIsHandedToThisNodeToExpand(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();

        $server->onClientFanoutReceived(
            $this->makeLink($server),
            new PeerClientFanoutDTO('node-b', $this->innerSignal()),
        );

        $this->assertSame([['node-b', 'room_renamed']], $sink->fannedOut);
    }

    /**
     * And it is expanded ONLY here: nothing is addressed and nothing is passed on. A receiver
     * that re-broadcast what it took in is the gossip echo phase-1 of this cluster died on, and
     * on a fan-out it would also deliver the signal to every browser more than once.
     */
    public function testAReceivedFanoutIsNotTurnedIntoAnAddressedDelivery(): void
    {
        $sink = $this->registerSink();
        $server = $this->makeServer();

        $server->onClientFanoutReceived(
            $this->makeLink($server),
            new PeerClientFanoutDTO('node-b', $this->innerSignal()),
        );

        $this->assertSame([], $sink->delivered);
    }

    /**
     * Builds the application signal a forwarded frame carries.
     *
     * @return SignalDTO One signal an agent would answer a browser with
     */
    private function innerSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType('ws_user'),
            new SignalName('room_renamed'),
            new SignalData(['room' => 'Ada']),
        );
    }

    /**
     * Registers a stand-in for the daemon's client seam and hands it back.
     *
     * @return ClientSignalSink Sink recording the nodes it was asked to hand connections to
     */
    private function registerSink(): ClientSignalSink
    {
        $sink = new class implements ClientSignalSink {
            /** @var list<string> Nodes this one was asked to hand its connections to */
            public array $handedOverTo = [];

            /** @var list<array{0: string, 1: string}> Accept key and signal name of each delivery, in order */
            public array $delivered = [];

            /** @var list<array{0: string, 1: string}> Origin node and signal name of each fan-out, in order */
            public array $fannedOut = [];

            /**
             * @param string $acceptKey Accept key of the connection to deliver to
             * @param SignalDTO $signal Signal to write to that connection
             */
            public function deliverSignalToClient(string $acceptKey, SignalDTO $signal): void
            {
                $this->delivered[] = [$acceptKey, $signal->signalName->getName()];
            }

            /**
             * @param string $originNodeId Id of the node the fan-out started on
             * @param SignalDTO $signal Signal to expand against this node's subscriptions
             */
            public function deliverFanoutToClients(string $originNodeId, SignalDTO $signal): void
            {
                $this->fannedOut[] = [$originNodeId, $signal->signalName->getName()];
            }

            /**
             * @param string $nodeId Node this one can now reach
             */
            public function handOverConnections(string $nodeId): void
            {
                $this->handedOverTo[] = $nodeId;
            }
        };
        Hilos::$cluster->registerClientConnections(new ClusterClientLocation());
        Hilos::$cluster->registerClientSignalSink($sink);

        return $sink;
    }

    /**
     * Registers this node's connection index and hands it back.
     *
     * @return ClusterClientLocation Index the received announcements are applied to
     */
    private function registerIndex(): ClusterClientLocation
    {
        $index = new ClusterClientLocation();
        Hilos::$cluster->registerClientConnections($index);

        return $index;
    }

    /**
     * @return PeerServer Peer server of this node, listening on nothing
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', NodeRole::Master, []), []);
    }

    /**
     * @param PeerServer $server Server the link belongs to
     * @return PeerLink Link over a bare socket, enough for the frame hand-off
     */
    private function makeLink(PeerServer $server): PeerLink
    {
        return new PeerLink(
            $this->makeSocket(),
            $server,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            dialer: false,
        );
    }

    /**
     * Creates a bare socket that satisfies the link constructor without any I/O.
     *
     * @return Socket Unconnected socket kept alive for the case's lifetime
     */
    private function makeSocket(): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->socket = $socket;

        return $socket;
    }
}

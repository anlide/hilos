<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
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
use Hilos\Socket\SocketException;
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
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testACompletedHandshakeAsksTheDaemonToHandOverItsRt(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $link = new PeerLink($this->makeSocket(), $server, $local, dialer: false);

        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', NodeRole::Master, []));

        $this->assertSame(['node-b'], $sink->handedOverTo);
    }

    /**
     * The second handshake with a peer already in the registry - a reconnect, or the third node
     * this one had only heard about. It merges no membership change, so a hand-over waiting on
     * that change would never happen; this one does.
     */
    public function testAHandshakeWithAPeerAlreadyKnownHandsOverAllTheSame(): void
    {
        $sink = $this->registerSink();
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $link = new PeerLink($this->makeSocket(), $server, $local, dialer: false);
        $remote = NodeIdentity::of('node-b', NodeRole::Master, []);

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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        // Clears the welcome and roster the handshake itself queued, so what is asserted below is
        // the two frames these cases are about and nothing else.
        $this->drain($far);

        $server->sendRtSnapshotToNode('node-b', 'unitRows', ['7' => ['id' => '7']], ['7']);
        $server->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $this->rtSyncUpdated(), partialOwner: true);
        $link->write();

        $this->assertSame(
            [PeerRtSnapshotDTO::MESSAGE_TYPE, PeerRtSyncDTO::MESSAGE_TYPE],
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        $this->drain($far);

        $link->discardAsDuplicate();
        $server->sendRtSnapshotToNode('node-b', 'unitRows', ['7' => ['id' => '7']], ['7']);
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $link->write();
        $this->drain($far);

        $server->sendRtSnapshotToNode('node-b', 'unitRows', ['7' => ['id' => '7']], ['7']);
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $link->write();
        $this->drain($far);

        $server->sendRtSnapshotToNode('node-b', 'unitRows', ['7' => ['id' => '7']], ['7']);
        $link->write();

        $this->assertSame([PeerRtSnapshotDTO::MESSAGE_TYPE], $this->frameTypesOf($far));
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $this->declareInterest($server, $link);
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', []));
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $link = new PeerLink($this->makeSocket(), $server, $local, dialer: false);

        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows']));

        $this->assertSame(['node-b'], $sink->handedOverTo);
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $link = new PeerLink($this->makeSocket(), $server, $local, dialer: false);

        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows']));
        $sink->handedOverTo = [];
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows']));

        $this->assertSame([], $sink->handedOverTo);
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
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $server->announceSourceInterest(['unitRows']);

        [$link, $far] = $this->makeLinkedPair($server, $local);
        $this->attach($server, $link);
        $this->handshake($link, $far);
        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', NodeRole::Master, []));
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
        $server->onSourceInterestReceived($link, new PeerSourceInterestDTO('node-b', ['unitRows']));
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
            NodeRole::Master,
            [],
            null,
        );
        socket_write($far, $hello->toJson() . "\n");
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
                $server->clients[] = $link;
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

        return [new PeerLink($near, $server, $local, dialer: false), $far];
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
            $types[] = $type;
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

            /**
             * @param string $originNodeId Id of the node the write happened on
             * @param string $signalType RT sync signal type the frame carried
             * @param SignalDTO $signal RT sync signal to apply
             */
            public function applyRemoteRtSync(
                string $originNodeId,
                string $signalType,
                SignalDTO $signal,
                bool $partialOwner = false,
            ): void
            {
            }

            /**
             * @param string $originNodeId Id of the node that owns the collection
             * @param string $collectionKey RT collection being replaced
             * @param array<string, array<string, mixed>> $rows Rows by state id
             * @param list<string> $scopeKeys Rows the snapshot speaks for
             */
            public function applyRemoteRtSnapshot(
                string $originNodeId,
                string $collectionKey,
                array $rows,
                array $scopeKeys = [],
            ): void {
            }

            /**
             * @param string $nodeId Node this one can now reach
             */
            public function handOverRtSnapshots(string $nodeId): void
            {
                $this->handedOverTo[] = $nodeId;
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

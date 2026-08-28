<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Hilos\Tests\Unit\DaemonManagerWorkerBroadcastTest;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Socket;

/**
 * What one broadcast to the other nodes COSTS the master loop (HIL-744).
 *
 * The frame a broadcast sends is the same object for every link it reaches, so packing it once
 * per link instead of once per broadcast is work paid for nothing - and it is paid on the
 * master loop, which every RT and DB sync fact passes through. These cases pin that cost, which
 * no caller can observe for itself: the frames delivered look identical either way. The
 * worker-link twin of this file is {@see DaemonManagerWorkerBroadcastTest} (HIL-701).
 *
 * The counter hangs on a test frame rather than on a real one because that is the only place a
 * packing is visible exactly once: {@see PeerDTO::toJson()} asks the frame for its array, and no
 * real frame says it was asked.
 *
 * Two more cases are about the filters the shared pass took over. Neither is held anywhere
 * through a real {@see PeerServer} today - the coordinator's own test drives consensus through
 * its mesh stand-in and never reaches the role filter - and a filter that moved into shared code
 * is exactly the kind that goes quiet without anything saying so. The interest filter is the one
 * exception and is not repeated here: {@see PeerServerRtHandOverTest} already holds it on a real
 * server, and its cases are the regression net for that filter's move.
 *
 * Stand-ins are impossible in any case: {@see PeerServer} and {@see PeerLink} are both final, so
 * these cases run the real pair over real socket pairs and read the bytes back from the far end.
 */
final class PeerBroadcastEncodingTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Every socket opened for the links under test, closed together */
    private array $sockets = [];

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
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses the queued frames
     */
    public function testTheFrameIsSerializedOncePerBroadcastNotPerLink(): void
    {
        $server = $this->makeServer();
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $second = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $second);
        $frame = new PeerBroadcastEncodingTestFrame();

        $server->broadcastToNodes($frame);

        $this->assertSame(1, $frame->encodes);
        $toFirst = $this->sentTo($first);
        $this->assertSame($toFirst, $this->sentTo($second), 'One packing means one string, byte for byte');
        $this->assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($toFirst));
    }

    /**
     * A filter that lets nobody through must not pay for a frame nobody receives - a mesh whose
     * only peer is a slave, a node whose links have not handshaked yet, a collection no peer
     * reads. This is the case that makes the packing lazy rather than done before the loop.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses the queued frames
     */
    public function testNothingIsSerializedWhenNoLinkPassesTheFilter(): void
    {
        $server = $this->makeServer();
        $slave = $this->linkTo($server, 'node-b', NodeRole::Slave);
        $this->clearQueued($slave);
        $frame = new PeerBroadcastEncodingTestFrame();

        $server->broadcastToMasters($frame);

        $this->assertSame(0, $frame->encodes);
        $this->assertSame('', $this->sentTo($slave));
    }

    /**
     * The other side of the same trade: a saving must not turn into "we send the wrong thing".
     *
     * A real {@see PeerAnnounceDTO} rather than the counting frame, because the restore is the
     * point here and {@see PeerDTO::fromWire()} would refuse a type it does not know - which is
     * also the reason the counting frame can never travel anywhere but these cases.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws PeerTransportException When the shared string does not come back as a peer frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses the queued frames
     */
    public function testEveryLinkGetsTheSameFrameAndItStillRestores(): void
    {
        $server = $this->makeServer();
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $second = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $second);
        $entry = new PeerNodeEntry('node-d', NodeRole::Slave, ['storage'], null, true);

        $server->broadcastToNodes(new PeerAnnounceDTO($entry));

        $toFirst = $this->sentTo($first);
        $this->assertSame($toFirst, $this->sentTo($second));
        $restored = PeerDTO::fromWire(trim($toFirst));
        $this->assertInstanceOf(PeerAnnounceDTO::class, $restored);
        $this->assertSame('node-d', $restored->node->nodeId);
        $this->assertSame(NodeRole::Slave, $restored->node->role);
        $this->assertSame(['storage'], $restored->node->capabilities);
    }

    /**
     * The role filter, which decides who counts as a vote and who merely watches.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses the queued frames
     */
    public function testAConsensusBroadcastSkipsASlaveLink(): void
    {
        $server = $this->makeServer();
        $master = $this->linkTo($server, 'node-b', NodeRole::Master);
        $slave = $this->linkTo($server, 'node-c', NodeRole::Slave);
        $this->clearQueued($master, $slave);
        $frame = new PeerBroadcastEncodingTestFrame();

        $server->broadcastToMasters($frame);

        $this->assertSame(
            [PeerBroadcastEncodingTestFrame::MESSAGE_TYPE],
            $this->frameTypesOf($this->sentTo($master)),
        );
        $this->assertSame('', $this->sentTo($slave), 'A slave holds no vote and is told of none');
    }

    /**
     * The source filter, which is what keeps membership gossip from echoing back where it came
     * from: the link whose handshake caused the announcement is the one link that already knows.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses the queued frames
     */
    public function testAnAnnouncementIsNotSentBackToTheLinkItCameFrom(): void
    {
        $server = $this->makeServer();
        $standing = $this->linkTo($server, 'node-b', NodeRole::Master);
        $this->clearQueued($standing);

        $joining = $this->linkTo($server, 'node-c', NodeRole::Master);

        $this->assertSame(
            [PeerAnnounceDTO::MESSAGE_TYPE],
            $this->frameTypesOf($this->sentTo($standing)),
            'The peer that was already there hears who joined',
        );
        $this->assertNotContains(
            PeerAnnounceDTO::MESSAGE_TYPE,
            $this->frameTypesOf($this->sentTo($joining)),
            'And the joiner is not told about itself',
        );
    }

    /**
     * Builds the server under test, with no seeds to dial and no port to listen on.
     *
     * @return PeerServer Server the cases broadcast through
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', NodeRole::Master, []), []);
    }

    /**
     * Raises one handshaked link to a named peer and registers it on the server.
     *
     * The handshake is driven for real, over a socket pair, because the identity it sets is what
     * every filter here asks the link about; calling the server's hook alone would leave the link
     * nameless and the cases green for the wrong reason.
     *
     * @param PeerServer $server Server the link belongs to
     * @param string $nodeId Node id the far end announces itself as
     * @param NodeRole $role Role the far end announces
     * @return array{PeerLink, Socket} The link and the far end of its pair
     * @throws EnvException When the link cannot read its socket and keepalive settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When the pair refuses the hello
     */
    private function linkTo(PeerServer $server, string $nodeId, NodeRole $role): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->sockets[] = $near;
        $this->sockets[] = $far;

        $link = new PeerLink($near, $server, NodeIdentity::of('node-a', NodeRole::Master, []), dialer: false);
        $this->attach($server, $link);
        socket_write($far, (new PeerHelloDTO(PeerProtocol::VERSION, $nodeId, $role, [], null))->toJson() . "\n");
        $link->read();

        return [$link, $far];
    }

    /**
     * Puts one link on the server's list, as the accept loop would have.
     *
     * These cases dial nothing, so the link is placed where an accepted one would be. Through a
     * bound closure rather than a subclass or Reflection: {@see PeerServer} is final, and a bound
     * closure is how the test next door already reaches the same list.
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
     * Drops whatever the handshakes themselves queued - welcome, roster, the announcement a
     * later link caused - so what a case asserts on is the broadcast it made and nothing else.
     *
     * @param array{PeerLink, Socket} ...$pairs Links to flush
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws SocketException When a pair refuses the queued frames
     */
    private function clearQueued(array ...$pairs): void
    {
        foreach ($pairs as $pair) {
            $this->sentTo($pair);
        }
    }

    /**
     * Flushes one link and hands back the bytes that reached the far end.
     *
     * @param array{PeerLink, Socket} $pair Link and the far end of its pair
     * @return string Bytes written since the last read, empty when none
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws SocketException When the pair refuses the queued frames
     */
    private function sentTo(array $pair): string
    {
        [$link, $far] = $pair;
        $link->write();
        $read = socket_read($far, 65536);

        return $read === false ? '' : $read;
    }

    /**
     * Names the frame types inside a run of bytes, in arrival order.
     *
     * @param string $bytes Newline-delimited frames read off a far end
     * @return list<string> Wire message types, oldest first
     */
    private function frameTypesOf(string $bytes): array
    {
        $types = [];
        foreach (explode("\n", $bytes) as $line) {
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
}

/**
 * A peer frame that counts how many times it was asked to serialize itself.
 *
 * One packing of a broadcast calls this exactly once, which is what makes the count readable as
 * "packings per broadcast". It carries no payload beyond its type: what is being measured is how
 * often the packing happens, not what the frame holds. {@see PeerDTO::fromWire()} does not know
 * this type and would refuse it, which is correct - it never leaves this file.
 */
final class PeerBroadcastEncodingTestFrame extends PeerDTO
{
    /** @var string Wire message type for the counting frame */
    public const string MESSAGE_TYPE = 'peer_broadcast_encoding_test';

    /** How many times this frame was asked to serialize itself */
    public int $encodes = 0;

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the counting frame to its wire array, counting the call.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        $this->encodes++;

        return [self::TYPE => self::MESSAGE_TYPE];
    }

    /**
     * Restores a counting frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame, counting nothing yet
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

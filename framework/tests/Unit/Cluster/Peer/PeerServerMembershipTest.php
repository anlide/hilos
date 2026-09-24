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
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Socket;

/**
 * Unit tests that a node's liveness comes from its own link and a neighbour's gossip carries
 * membership only (HIL-338, HIL-1059).
 *
 * Three events move liveness and each one reaches the membership observer: this node's own
 * handshake (a join), the close of its last link to a node and the leave frame the node sends
 * about itself (a leave). A roster or an announcement changes what a node is made of and never
 * whether it is alive, so the observer hears gossip in one case only: a node it holds online
 * announcing its own new make-up. The first case is HIL-1034 replayed exactly - a roster that
 * named a node in the window between its link dropping and its new handshake used to put it back
 * online silently, and the handshake was then taken for no change.
 *
 * Every link here is handshaked for real over a socket pair, because the identity a handshake
 * sets is what the gossip filters ask a link about; what a case asserts on the wire is read back
 * off the far end of a pair.
 */
final class PeerServerMembershipTest extends TestCase
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
        // Long thresholds so no link closes or pings on its own during the test.
        putenv('CLUSTER_LINK_KEEPALIVE_INTERVAL_MS=60000');
        putenv('CLUSTER_LINK_TIMEOUT_MS=60000');

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
        foreach ([
            'SOCKET_READ_BUFFER_SIZE',
            'CLUSTER_ENABLED',
            'CLUSTER_NODE_ID',
            'CLUSTER_NODE_ROLE',
            'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS',
            'CLUSTER_LINK_TIMEOUT_MS',
        ] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAHandshakeIsAJoinAndTheLastLinkClosingIsALeave(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();

        [$linkB] = $this->linkTo($server, 'node-b');
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame([], $observer->left);

        $this->closeLink($server, $linkB);
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame(['node-b'], $observer->left);
    }

    /**
     * HIL-1034 replayed: the link to B drops, a roster from C names B in the window before B's new
     * handshake, and B's return must still be the join the placement hears.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testARosterInTheWindowBeforeAHandshakeLeavesTheReturnToTheHandshake(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];

        $this->closeLink($server, $linkB);
        $this->assertSame(['node-b'], $observer->left);

        // C still links to B and says so in the roster its next handshake hands over.
        $this->feed($linkC, $farC, new PeerRosterDTO([
            $this->entry('node-a'),
            $this->entry('node-b'),
            $this->entry('node-c'),
        ]));

        $this->assertSame([], $observer->joined, 'A neighbour\'s word is not a return');
        $this->assertSame(['node-b'], $observer->left);
        $this->assertNotContains('node-b', $server->onlineNodeIds(), 'B stays offline until this node sees it');

        $this->linkTo($server, 'node-b');

        $this->assertSame(['node-b'], $observer->joined, 'The handshake is the return, and it is reported');
        $this->assertContains('node-b', $server->onlineNodeIds());
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAnAnnouncedUnknownNodeIsKnownOfflineAndGoesNoFurther(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerAnnounceDTO($this->entry('node-d')));

        $this->assertTrue($server->nodeHasLeftTheMesh('node-d'), 'Known, not yet seen');
        $this->assertNotContains('node-d', $server->onlineNodeIds());
        $this->assertSame([], $observer->joined);
        $this->assertSame('', $this->drain($linkC, $farC), 'An announcement is never relayed onward');
    }

    /**
     * How a newcomer that came in through one seed becomes known to everyone else.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws PeerTransportException When a frame read back is not one this protocol knows
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testARosterSpreadsAnUnknownNodesMembershipButNotItsLiveness(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerRosterDTO([$this->entry('node-b'), $this->entry('node-d')]));

        $this->assertTrue($server->nodeHasLeftTheMesh('node-d'), 'Known, not yet seen');
        $this->assertNotContains('node-d', $server->onlineNodeIds());
        $this->assertSame([], $observer->joined);
        $frames = $this->framesOf($this->drain($linkC, $farC));
        $this->assertCount(1, $frames, 'C hears of D, and of nothing that did not change');
        $this->assertInstanceOf(PeerAnnounceDTO::class, $frames[0]);
        $this->assertSame('node-d', $frames[0]->node->nodeId);
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testANodeAnnouncingItsOwnNewCapabilityIsAJoin(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        $observer->joined = [];

        $this->feed($linkB, $farB, new PeerAnnounceDTO($this->entry('node-b', ['gpu-local'])));

        $this->assertSame(['node-b'], $observer->joined, 'The leader retries what it could not place');
        $this->assertSame(['gpu-local'], $server->nodeCapabilities('node-b'));
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testANeighboursWordAboutALinkedNodeIsDropped(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];

        $this->feed($linkC, $farC, new PeerAnnounceDTO($this->entry('node-b', ['gpu-local'])));

        $this->assertSame([], $observer->joined);
        $this->assertSame([], $server->nodeCapabilities('node-b'), 'B told its make-up itself, on its handshake');
    }

    /**
     * Each neighbour hears a leave from the node that leaves, or sees its own link drop - nobody
     * passes a leave on.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testNeitherALeaveFrameNorALinkDroppingIsPassedOn(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        [$linkD] = $this->linkTo($server, 'node-d');
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerNodeLeavingDTO('node-b', false, null));
        $this->closeLink($server, $linkB);
        $this->closeLink($server, $linkD);

        $this->assertSame(['node-b', 'node-d'], $observer->left);
        $this->assertSame('', $this->drain($linkC, $farC));
    }

    /**
     * Registers the observer the cases read membership transitions from.
     *
     * @return RecordingMembershipObserver Observer recording every join and leave
     */
    private function registerObserver(): RecordingMembershipObserver
    {
        $observer = new RecordingMembershipObserver();
        Hilos::$cluster->registerMembershipObserver($observer);

        return $observer;
    }

    /**
     * Builds the server under test, with no seeds to dial and no port to listen on.
     *
     * @return PeerServer Server the cases drive
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', NodeRole::Master, []), [], PeerTestTls::unread());
    }

    /**
     * Raises one handshaked link to a named master and registers it on the server.
     *
     * @param PeerServer $server Server the link belongs to
     * @param string $nodeId Node id the far end introduces itself as
     * @return array{PeerLink, Socket} The link and the far end of its pair
     * @throws EnvException When the link cannot read its socket and keepalive settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When the pair refuses the hello
     */
    private function linkTo(PeerServer $server, string $nodeId): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->sockets[] = $near;
        $this->sockets[] = $far;

        $link = new PeerLink(
            $near,
            $server,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            dialer: false,
            transport: new NamedPeerTestTransport($near, $nodeId),
        );
        $this->attach($server, $link);
        $this->feed($link, $far, new PeerHelloDTO(PeerProtocol::VERSION, $nodeId, NodeRole::Master, [], null));
        $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId, 'The hello must complete the handshake');

        return [$link, $far];
    }

    /**
     * Puts one link on the server's list, as the accept loop would have.
     *
     * Through a bound closure rather than a subclass or Reflection: {@see PeerServer} is final, and
     * a bound closure is how the tests next door reach the same list.
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
     * Takes one link down the way the server does: the close is announced, then the link is gone.
     *
     * The socket itself is left open for the teardown to close, which is all the real close adds.
     *
     * @param PeerServer $server Server the link belongs to
     * @param PeerLink $link Handshaked link that closes
     */
    private function closeLink(PeerServer $server, PeerLink $link): void
    {
        $server->onLinkClosed($link);
        $server->removeClient($link);
    }

    /**
     * Delivers one frame to a link the way the node on the other end would.
     *
     * @param PeerLink $link Link the frame arrives on
     * @param Socket $far Far end, standing in for the sending node
     * @param PeerDTO $frame Frame that node sends
     * @throws HilosException When the frame refuses to become wire input
     * @throws SocketException When the pair refuses the frame
     */
    private function feed(PeerLink $link, Socket $far, PeerDTO $frame): void
    {
        socket_write($far, $frame->toJson() . "\n");
        $link->read();
    }

    /**
     * Flushes one link and hands back the bytes that reached the far end.
     *
     * @param PeerLink $link Link to flush
     * @param Socket $far Far end of its pair
     * @return string Bytes written since the last read, empty when none
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws SocketException When the pair refuses the queued frames
     */
    private function drain(PeerLink $link, Socket $far): string
    {
        $link->write();
        $read = socket_read($far, 65536);

        return $read === false ? '' : $read;
    }

    /**
     * Parses back the frames inside a run of bytes, in arrival order.
     *
     * @param string $bytes Newline-delimited frames read off a far end
     * @return list<PeerDTO> Frames as the receiving node would parse them, oldest first
     * @throws PeerTransportException When a frame is not one this protocol knows
     */
    private function framesOf(string $bytes): array
    {
        $frames = [];
        foreach (explode("\n", $bytes) as $line) {
            if ($line !== '') {
                $frames[] = PeerDTO::fromWire($line);
            }
        }

        return $frames;
    }

    /**
     * @param string $nodeId Node id the entry describes
     * @param list<string> $capabilities Capability tags the entry carries
     * @return PeerNodeEntry Gossip entry for a master node with no address
     */
    private function entry(string $nodeId, array $capabilities = []): PeerNodeEntry
    {
        return PeerNodeEntry::fromIdentity(NodeIdentity::of($nodeId, NodeRole::Master, $capabilities));
    }
}

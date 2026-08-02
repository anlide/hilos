<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests that membership gossip cannot echo around the mesh.
 *
 * On a live five-node stand two nodes holding opposite views of a third node's
 * liveness flipped each other's record forever: every merged announcement was
 * re-announced onward, so each flip re-entered the mesh and came straight back,
 * saturating the peer links until the daemons died of exhausted memory. Liveness is
 * observed rather than relayed, and these tests hold that line: a peer is
 * authoritative only about itself, and a merged announcement is never forwarded.
 * Outbound frames are inspected by flushing a link and reading the far end of its
 * socket pair.
 */
final class PeerAnnounceEchoTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<\Socket> Sockets kept alive for the links under test */
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
            @socket_close($socket);
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
    }

    public function testAnnouncementAboutAPeerThisNodeLinksToIsIgnored(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB] = $this->makeHandshakedLink($server, 'node-b');
        $this->makeHandshakedLink($server, 'node-c');

        // node-b claims node-c is gone while this node still holds its own link to node-c.
        $server->onAnnounceReceived($linkB, new PeerAnnounceDTO($this->entry('node-c', false)));

        $this->assertSame([], $observer->left, 'A third node stays online while this node observes it over its own link');
    }

    public function testAnnouncementFromThePeerItDescribesIsAccepted(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB] = $this->makeHandshakedLink($server, 'node-b');

        // A peer speaks authoritatively about itself, so its own leave is merged.
        $server->onAnnounceReceived($linkB, new PeerAnnounceDTO($this->entry('node-b', false)));

        $this->assertSame(['node-b'], $observer->left, 'A peer announcing its own departure is believed');
    }

    public function testMergedAnnouncementIsNotForwardedToTheOtherPeers(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB] = $this->makeHandshakedLink($server, 'node-b');
        [$linkC, $farC] = $this->makeHandshakedLink($server, 'node-c');
        $this->flushAndRead($linkC, $farC);

        // node-d is unknown and unlinked, so the entry merges - and must stop there.
        $server->onAnnounceReceived($linkB, new PeerAnnounceDTO($this->entry('node-d', true)));

        $this->assertContains('node-d', $observer->joined, 'An announcement about an unknown node is merged');
        $this->assertSame('', $this->flushAndRead($linkC, $farC), 'A merged announcement is not relayed onward');
    }

    /**
     * Registers a membership observer that records the reported transitions.
     *
     * @return MembershipObserver Observer exposing `joined` and `left` node id lists
     */
    private function registerObserver(): MembershipObserver
    {
        $observer = new class implements MembershipObserver {
            /** @var list<string> Node ids reported joined */
            public array $joined = [];

            /** @var list<string> Node ids reported left */
            public array $left = [];

            public function onNodeJoined(ClusterNode $node): void
            {
                $this->joined[] = $node->nodeId;
            }

            public function onNodeLeft(ClusterNode $node): void
            {
                $this->left[] = $node->nodeId;
            }
        };
        Hilos::$cluster->registerMembershipObserver($observer);

        return $observer;
    }

    /**
     * Builds a handshaked link to one peer and puts it on the server's client list.
     *
     * The handshake is driven through the link's own read path so the link ends up
     * carrying the remote identity, the way a live peer would set it. The client list
     * is the server's own; a unit test never accepts a real connection, so the link is
     * registered the way the accept path would have added it.
     *
     * @param PeerServer $server Server owning the link
     * @param string $nodeId Remote node id the handshake reports
     * @return array{0: PeerLink, 1: \Socket} Link and the far end of its socket pair
     */
    private function makeHandshakedLink(PeerServer $server, string $nodeId): array
    {
        [$near, $far] = $this->makeSocketPair();
        $link = new PeerLink($near, $server, $this->localIdentity(), dialer: true);

        $clients = new \ReflectionProperty($server, 'clients');
        $clients->setValue($server, [...$clients->getValue($server), $link]);

        socket_write($far, new PeerWelcomeDTO(PeerProtocol::VERSION, $nodeId, NodeRole::Master, [])->toJson() . "\n");
        $link->read();

        $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId, 'The welcome must complete the handshake');

        return [$link, $far];
    }

    /**
     * Flushes a link's outbound buffer and returns everything readable on the far end.
     *
     * @param PeerLink $link Link to flush
     * @param \Socket $far Far end of the link's socket pair
     * @return string Bytes the far end received
     */
    private function flushAndRead(PeerLink $link, \Socket $far): string
    {
        $link->write();

        $received = socket_read($far, 65536, PHP_BINARY_READ);

        return $received === false ? '' : $received;
    }

    /**
     * @param string $nodeId Node id the entry describes
     * @param bool $online Liveness the entry claims
     * @return PeerNodeEntry Gossip entry for a master node without capabilities
     */
    private function entry(string $nodeId, bool $online): PeerNodeEntry
    {
        return PeerNodeEntry::fromIdentity(NodeIdentity::of($nodeId, NodeRole::Master, []), $online);
    }

    /**
     * @return PeerServer Peer server backing the links under test
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, $this->localIdentity(), []);
    }

    /**
     * @return NodeIdentity Local node identity for the links under test
     */
    private function localIdentity(): NodeIdentity
    {
        return NodeIdentity::of('node-a', NodeRole::Master, []);
    }

    /**
     * Creates a connected socket pair so a link's output can be read back.
     *
     * @return array{0: \Socket, 1: \Socket} Near end (wrapped by the link) and far end (the test reads here)
     */
    private function makeSocketPair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return [$pair[0], $pair[1]];
    }
}

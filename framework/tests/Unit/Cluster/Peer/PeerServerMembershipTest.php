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
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Unit tests that a peer-mesh membership transition reaches the daemon observer (HIL-338).
 *
 * The transport merges peers into the master registry and, on a meaningful
 * change, must forward the join/leave to whoever registered as the cluster
 * membership observer.
 */
final class PeerServerMembershipTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var ?Socket Dummy socket kept alive for the link under test */
    private ?\Socket $socket = null;

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
    }

    public function testHandshakeAndLeaveAnnounceReachTheObserver(): void
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

        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $link = new PeerLink($this->makeSocket(), $server, $local, dialer: false);

        // A completed handshake with a new peer is a join.
        $server->onHandshakeComplete($link, NodeIdentity::of('node-b', NodeRole::Master, []));
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame([], $observer->left);

        // A gossiped offline announcement for that peer is a leave.
        $offline = PeerNodeEntry::fromIdentity(NodeIdentity::of('node-b', NodeRole::Master, []), false);
        $server->onAnnounceReceived($link, new PeerAnnounceDTO($offline));
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame(['node-b'], $observer->left);
    }

    /**
     * Creates a bare socket that satisfies the link constructor without any I/O.
     *
     * @return Socket Unconnected socket kept alive for the test's lifetime
     */
    private function makeSocket(): \Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->socket = $socket;

        return $socket;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerDial;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/**
 * Unit tests that a peer re-handshaking on a changed address re-points its dial (HIL-343).
 *
 * The registry already refreshes a node's advertised address on re-handshake, but the
 * dial-on-learn target is created once and pinned to the first-seen endpoint. When a
 * node restarts on a new host/port, {@see PeerServer::reconcilePeerDials()} must move the
 * existing dial to the new endpoint — tearing down a pending connect and clearing the
 * backoff — while leaving a live established link and a momentarily address-less node alone.
 */
final class PeerServerAddressChurnTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Sockets kept alive for the test's lifetime and closed on teardown */
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
    }

    public function testReconcileRepointsDialWhenPeerReAdvertisesAChangedAddress(): void
    {
        $local = NodeIdentity::of('node-a', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $local, []);
        $registry = Hilos::$cluster->registry();
        $now = microtime(true);

        // First handshake at the original endpoint lazily opens the dial.
        $registry->merge(NodeIdentity::of('node-b', NodeRole::Master, [], new PeerAddress('10.0.0.1', 1111)), true, $now);
        $this->reconcile($server);

        $dials = $this->peerDials($server);
        $this->assertArrayHasKey('node-b', $dials);
        $dial = $dials['node-b'];
        $this->assertSame('10.0.0.1:1111', $dial->address->toString());

        // Put the dial in-flight to the old endpoint and hand it a live link, to prove the
        // refresh tears down the pending connect yet preserves an established link. The
        // connecting socket is closed by the refresh, so it is not tracked for teardown.
        $connecting = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($connecting);
        $dial->socket = $connecting;
        $dial->connecting = true;
        $dial->connectStartedAt = $now;
        $dial->nextAttemptAt = $now + 5.0;
        $link = new PeerLink($this->newSocket(), $server, $local, dialer: true);
        $dial->link = $link;

        // The peer restarts on a new port; the same node re-handshakes with a changed address.
        $registry->merge(NodeIdentity::of('node-b', NodeRole::Master, [], new PeerAddress('10.0.0.2', 2222)), true, $now);
        $this->reconcile($server);

        $this->assertSame('10.0.0.2:2222', $dial->address->toString(), 'dial re-points at the new endpoint');
        $this->assertFalse($dial->connecting, 'the in-flight connect is torn down');
        $this->assertNull($dial->socket, 'the stale connecting socket is cleared');
        $this->assertSame(0.0, $dial->nextAttemptAt, 'backoff is reset so the new address dials promptly');
        $this->assertSame($link, $dial->link, 'a live established link is preserved');

        // A node that momentarily stops advertising an address leaves its dial untouched.
        $registry->merge(NodeIdentity::of('node-b', NodeRole::Master, []), true, $now);
        $this->reconcile($server);
        $this->assertSame('10.0.0.2:2222', $dial->address->toString(), 'a null incoming address does not drop reachability');
    }

    /**
     * Invokes the private reconcile pass that opens and refreshes dial-on-learn targets.
     *
     * @param PeerServer $server Server under test
     */
    private function reconcile(PeerServer $server): void
    {
        new ReflectionMethod($server, 'reconcilePeerDials')->invoke($server);
    }

    /**
     * Reads the server's private dial-on-learn map.
     *
     * @param PeerServer $server Server under test
     * @return array<string, PeerDial> Dial-on-learn state keyed by node id
     */
    private function peerDials(PeerServer $server): array
    {
        return new ReflectionProperty($server, 'peerDials')->getValue($server);
    }

    /**
     * Creates a bare socket kept alive for the test's lifetime.
     *
     * @return Socket Unconnected socket closed on teardown
     */
    private function newSocket(): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->sockets[] = $socket;

        return $socket;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Unit tests for the per-link keepalive on {@see PeerLink} (HIL-183).
 *
 * The keepalive detects a hung-but-connected peer that the ordinary socket close never
 * catches: a link silent past the timeout closes (reusing the offline/failover path), a
 * quiet handshaked link pings to draw proof of life, and a busy link never pings. The
 * timeout also bounds a stalled half-open handshake. Outbound frames are inspected by
 * flushing the link and reading the far end of a socket pair; env thresholds are set per
 * test before the link is built, since the link reads them at construction.
 */
final class PeerLinkKeepaliveTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Sockets kept alive for the links under test */
    private array $sockets = [];

    /** @var ?Socket Far end of the current link's socket pair, where the test reads flushed output */
    private ?\Socket $far = null;

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
            @socket_close($socket);
        }
        $this->sockets = [];
        $this->far = null;

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

    public function testAStalledHalfOpenHandshakeTimesOut(): void
    {
        putenv('CLUSTER_LINK_TIMEOUT_MS=0');
        $link = new PeerLink($this->makeSocket(), $this->makeServer(), $this->localIdentity(), dialer: false);

        // Nothing was ever heard on this accepting link, so the timeout closes it.
        $link->onTick();

        $this->assertTrue($link->shouldClose(), 'A half-open link that never handshakes is closed by the timeout');
    }

    public function testAQuietHandshakedLinkPingsAfterTheKeepaliveInterval(): void
    {
        putenv('CLUSTER_LINK_KEEPALIVE_INTERVAL_MS=0');
        putenv('CLUSTER_LINK_TIMEOUT_MS=60000');
        $link = $this->makeHandshakedDialerLink();

        $link->onTick();

        $this->assertStringContainsString('peer_ping', $this->flushAndReadFar($link), 'A quiet handshaked link emits a keepalive ping');
    }

    public function testABusyLinkNeverPings(): void
    {
        putenv('CLUSTER_LINK_KEEPALIVE_INTERVAL_MS=100000');
        putenv('CLUSTER_LINK_TIMEOUT_MS=200000');
        $link = $this->makeHandshakedDialerLink();

        // The handshake frame arrived a moment ago, so the link is not silent yet.
        $link->onTick();

        $this->assertStringNotContainsString('peer_ping', $this->flushAndReadFar($link), 'A recently-heard link does not ping');
    }

    /**
     * Builds a dialer link and drives a welcome frame through it so it is handshaked.
     *
     * @return PeerLink Handshaked dialer link
     */
    private function makeHandshakedDialerLink(): PeerLink
    {
        [$near, $far] = $this->makeSocketPair();
        $this->far = $far;
        $link = new PeerLink($near, $this->makeServer(), $this->localIdentity(), dialer: true);

        $welcome = new PeerWelcomeDTO(PeerProtocol::VERSION, 'node-b', NodeRole::Master, []);
        socket_write($far, $welcome->toJson() . "\n");
        $link->read();

        $this->assertNotNull($link->remoteIdentity(), 'The welcome must complete the handshake');

        return $link;
    }

    /**
     * Flushes the link's outbound buffer and returns everything readable on the far end.
     *
     * @param PeerLink $link Link to flush
     * @return string Bytes the far end received
     */
    private function flushAndReadFar(PeerLink $link): string
    {
        $link->write();

        $received = socket_read($this->far, 65536, PHP_BINARY_READ);

        return $received === false ? '' : $received;
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
     * Creates a bare socket that satisfies the link constructor without any I/O.
     *
     * @return Socket Unconnected socket kept alive for the test's lifetime
     */
    private function makeSocket(): \Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->sockets[] = $socket;

        return $socket;
    }

    /**
     * Creates a connected socket pair so a frame can be fed into a link's read path.
     *
     * @return array{0: Socket, 1: Socket} Near end (wrapped by the link) and far end (test writes here)
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

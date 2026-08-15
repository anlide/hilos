<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtSyncSink;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
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
 */
final class PeerServerRtHandOverTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
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
            public function applyRemoteRtSync(string $originNodeId, string $signalType, SignalDTO $signal): void
            {
            }

            /**
             * @param string $originNodeId Id of the node that owns the collection
             * @param string $collectionKey RT collection being replaced
             * @param array<string, array<string, mixed>> $rows Rows by state id
             */
            public function applyRemoteRtSnapshot(string $originNodeId, string $collectionKey, array $rows): void
            {
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

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Socket;

/** Real authenticated peer links over socket pairs, with no additional processes. */
trait ProtectedModePeerFixture
{
    private ?EnvAccessor $previousPeerEnv = null;

    private ?ClusterContext $previousPeerCluster = null;

    /** @var array<string, string|false> Environment values restored after each case */
    private array $previousPeerSettings = [];

    /** @var list<Socket> Both ends of every pair owned by this fixture */
    private array $peerSockets = [];

    private NodeIdentity $peerLocalIdentity;

    /** Mounts only the environment and membership needed for real peer handshakes. */
    private function mountProtectedModePeers(): void
    {
        $this->previousPeerEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousPeerCluster = Hilos::$cluster;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE' => '65536',
            'CLUSTER_ENABLED' => 'true',
            'CLUSTER_NODE_ID' => 'node-a',
            'CLUSTER_NODE_NAME' => 'Local node',
            'CLUSTER_NODE_ROLE' => NodeRole::Master->value,
        ] as $key => $value) {
            $this->previousPeerSettings[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    /** Releases the fixture's sockets and restores the previous facade and environment. */
    private function restoreProtectedModePeers(): void
    {
        foreach ($this->peerSockets as $socket) {
            socket_close($socket);
        }
        $this->peerSockets = [];
        Hilos::$env = $this->previousPeerEnv;
        Hilos::$cluster = $this->previousPeerCluster;
        foreach ($this->previousPeerSettings as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /**
     * @param NodeRole $role This node's cluster role
     * @return PeerServer Server without a listener or outbound dials
     */
    private function protectedModePeerServer(NodeRole $role = NodeRole::Master): PeerServer
    {
        putenv('CLUSTER_NODE_ROLE=' . $role->value);
        $this->peerLocalIdentity = NodeIdentity::of('node-a', 'Local node', $role, []);

        return new PeerServer('127.0.0.1', 0, $this->peerLocalIdentity, [], PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param PeerServer $server Server accepting the peer
     * @param string $nodeId Remote authenticated identity
     * @param NodeRole $role Remote cluster role
     * @param bool $ready Whether to finish authentication or leave a candidate
     * @return array{PeerLink, Socket} Peer link and its far socket
     */
    private function protectedModePeerLink(PeerServer $server, string $nodeId, NodeRole $role, bool $ready = true): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->peerSockets = [...$this->peerSockets, $near, $far];
        $link = new PeerLink(
            $near,
            $server,
            $this->peerLocalIdentity,
            dialer: false,
            transport: new NamedPeerTestTransport($near, $nodeId),
        );
        Closure::bind(static function () use ($server, $link): void {
            $server->clients[] = $link;
        }, null, PeerServer::class)();
        socket_write($far, new PeerHelloDTO(
            PeerProtocol::VERSION, $nodeId, 'Node ' . $nodeId, $role, [], PeerTestMarkers::onWire(), null,
        )->toJson() . "\n");
        $link->read();
        if (!$ready) {
            $this->assertNull($link->remoteIdentity());
            return [$link, $far];
        }
        socket_write($far, new PeerReadyDTO(false)->toJson() . "\n");
        $link->read();
        $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId);
        $this->assertSame($role, $link->remoteIdentity()?->role);

        return [$link, $far];
    }

    /**
     * @param array{PeerLink, Socket} $pair Link to flush and its far socket
     * @return list<PeerDTO> Complete frames delivered since the previous flush
     */
    private function protectedModePeerFrames(array $pair): array
    {
        [$link, $far] = $pair;
        $link->write();
        $wire = socket_read($far, 65536);
        if ($wire === false || $wire === '') {
            return [];
        }

        return array_map(static fn(string $line): PeerDTO => PeerDTO::fromWire($line), explode("\n", trim($wire)));
    }
}

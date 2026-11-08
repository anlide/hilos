<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Error;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementDuty;
use Hilos\Cluster\Placement\PlacementTimer;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Tests\Unit\Cluster\Placement\NativePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\NativePlacementMesh;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Placement shares the actual peer base and keeps its old duties throughout shutdown drain. */
final class PeerServerPlacementEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private PeerServer $server;

    private ?ClusterContext $previousCluster;

    private ?EnvAccessor $previousEnv;

    /** @var list<ClusterPlacement> Facade-only fixtures released separately from the peer owner */
    private array $owners = [];

    /** @var list<Socket> Socket pairs closed after the native watches */
    private array $sockets = [];

    public function testLateSameBaseAndReplacementBindingOwnOnlyTheExactPrivatePlacement(): void
    {
        $this->server->attachEventLoop($this->loop);
        $first = $this->host();
        $first->noteQuorumLost(hrtime(true) / 1e9);
        new ReflectionProperty(PeerServer::class, 'placement')->setValue($this->server, $first);
        $this->server->attachEventLoop($this->loop);
        $timer = $this->fence($first);
        $firstHandle = $timer->handle;
        $this->assertNotNull($firstHandle);
        $this->server->attachEventLoop($this->loop);
        $this->assertSame($firstHandle, $timer->handle);
        $facadeOnly = $this->host();
        $facadeOnly->noteQuorumLost(hrtime(true) / 1e9);
        $facadeOnly->attachEventLoop($this->loop);
        $facadeTimer = $this->fence($facadeOnly);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerPlacement($facadeOnly);
        $this->server->reconcilePlacementEventLoop();
        $this->assertSame($firstHandle, $timer->handle, 'A facade replacement is not the actual bound peer owner');
        $second = $this->host();
        new ReflectionProperty(PeerServer::class, 'placement')->setValue($this->server, $second);
        $this->server->attachEventLoop($this->loop);
        $this->assertNull($timer->handle);
        $this->assertNotNull($facadeTimer->handle, 'Retiring the old peer owner cannot detach a facade-only owner');
        $this->assertTrue($this->server->hasPlacementReadyWork());
        $this->assertSame($second, new ReflectionProperty(PeerServer::class, 'boundPlacement')->getValue($this->server));
    }

    public function testPreparingShutdownKeepsAFenceAliveWhileRealOutputDrainsThenFinalStopDetaches(): void
    {
        $executor = new NativePlacementExecutor();
        $placement = $this->host($executor);
        new ReflectionProperty(PeerServer::class, 'placement')->setValue($this->server, $placement);
        $this->server->attachEventLoop($this->loop);
        $placement->noteQuorumLost(hrtime(true) / 1e9 - 100.0);
        $timer = $this->fence($placement);
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->sockets = $pair;
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $link = new PeerLink(
            $pair[0],
            $this->server,
            NodeIdentity::of('host', 'Host', NodeRole::Master, []),
            false,
            new PlainSocketTransport($pair[0]),
        );
        $link->attachEventLoop($this->loop, static function (): void {});
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($this->server, $link);
        $link->sendEncodedFrame(str_repeat('x', 1024 * 1024));
        $this->server->prepareShutdown();
        $this->server->attachEventLoop($this->loop);
        $this->assertNotNull($timer->handle, 'Preparation cannot detach a deadline the old placement pass still owed');
        $this->assertFalse($this->server->isReadyToShutdown());
        $this->loop->tick();
        $this->assertSame([], $executor->revoked);
        $this->server->onTick();
        $this->assertSame([['render', '1']], $executor->revoked, 'The actual guarded peer phase still applies the fence');
        $this->assertFalse($this->server->isReadyToShutdown(), 'A logical revoke does not stand in for physical output drain');
        for ($portion = 0; $portion < 40 && $link->hasPendingWrite(); ++$portion) {
            socket_read($pair[1], 65536);
            $this->loop->tick();
            $this->server->onTick();
        }
        $this->assertTrue($this->server->isReadyToShutdown());
        $this->server->stop();
        $this->assertNull(new ReflectionProperty(PeerServer::class, 'boundPlacement')->getValue($this->server));
        $this->assertNull(new ReflectionProperty(ClusterPlacement::class, 'eventLoop')->getValue($placement));
        $this->assertFalse($placement->hasReadyWork());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        $this->loop = new EventLoop();
        $this->server = new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('host', 'Host', NodeRole::Master, []),
            [],
            PeerTestTls::unread(),
            PeerTestMarkers::shared(),
        );
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        foreach ($this->owners as $owner) {
            $owner->detachEventLoop();
        }
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            try {
                socket_close($socket);
            } catch (Error) {
                // Final server stop already closed its exact socket.
            }
        }
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /**
     * @param ?NativePlacementExecutor $executor Optional observed logical executor
     * @return ClusterPlacement Real hosted work, without any peer or clock substitution
     */
    private function host(?NativePlacementExecutor $executor = null): ClusterPlacement
    {
        $placement = new ClusterPlacement('host', new NativePlacementMesh(), $executor ?? new NativePlacementExecutor());
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1'));
        $this->owners[] = $placement;

        return $placement;
    }

    /**
     * @param ClusterPlacement $placement Owner whose logical fence is inspected
     * @return PlacementTimer Exact native incarnation
     */
    private function fence(ClusterPlacement $placement): PlacementTimer
    {
        return new ReflectionProperty(ClusterPlacement::class, 'timers')->getValue($placement)[PlacementDuty::SelfFence->value]['host'];
    }
}

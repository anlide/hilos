<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Error;
use Event;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\ConnectionPolicy;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerDial;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Tls\ClusterTlsConfig;
use Hilos\Constants\TimeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Real TCP readiness and due-now target deadlines, without a server tick or a timed wait. */
final class PeerServerDialEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private MutualTlsFixture $fixture;

    private ?PeerServer $server = null;

    private ?EnvAccessor $previousEnv;

    private ?ClusterContext $previousCluster;

    /** @var array<string, string|false> Environment restored after each case */
    private array $previousValues = [];

    /** @var list<Socket> Bare sockets outside the TLS fixture's ownership */
    private array $sockets = [];

    /** TCP completion releases the raw watch before binding TLS and queuing hello. */
    public function testWriteCompletionPromotesWithoutTickAndDetachesBeforeTlsBinding(): void
    {
        $listener = $this->fixture->listen();
        $server = $this->makeServer([$this->address($listener)]);
        $bound = 0;
        $server->setClientSocketAttacher(function (ClientInterface $client) use (&$bound): void {
            ++$bound;
            $this->assertSame(0, $this->loop->registeredCount(), 'raw connect WRITE must leave before native TLS owns the fd');
            $this->assertFalse($client->hasPendingWrite(), 'binding precedes the first peer hello');
            $client->attachEventLoop($this->loop, static function (): void {});
        });
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $dial = $this->seedDial();
        $this->assertTrue($dial->connecting);
        $this->assertSame($dial->connectStartedAt + 5.0, $dial->timer->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork(), 'a pending connect waits for its native watch');

        $this->loop->tick();
        $this->assertTrue($dial->connectReady);
        $this->assertNull($dial->link, 'the WRITE notification only releases the guarded owner');
        $this->loop->tick();
        $this->assertSame(1, $bound);
        $this->assertNotNull($dial->link);
        $this->assertFalse($dial->connecting);
        $this->assertNull($dial->socket);
        $this->assertNull($dial->timer);
        $this->assertFalse($dial->connectReady);
        $this->assertSame(0, $this->loop->registeredCount());
    }

    /** A refused TCP endpoint sleeps on exactly one unchanged retry deadline. */
    public function testRefusedSoErrorArmsOnlyTheTargetRetryAndIdlePassesDoNothing(): void
    {
        $this->startRefusedDial();
        $dial = $this->seedDial();
        $timer = $dial->timer;
        $deadline = $dial->nextAttemptAt;
        $generation = $dial->generation;
        $this->assertFalse($dial->connecting);
        $this->assertNull($dial->socket);
        $this->assertNull($dial->link);
        $this->assertGreaterThan(self::now(), $deadline);
        $this->assertLessThanOrEqual(self::now() + 5.0, $deadline);
        $this->assertSame($deadline, $timer->getDeadline());
        $this->assertSame(0, $this->loop->registeredCount());
        for ($pass = 0; $pass < 8; ++$pass) {
            $this->server->onTick();
            $this->loop->tick();
        }
        $this->assertSame($timer, $dial->timer);
        $this->assertSame($deadline, $timer->getDeadline());
        $this->assertSame($generation, $dial->generation);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Bringing this target's existing retry to now releases one attempt. */
    public function testRetryDeadlineStartsOneNewAttemptWithoutServerTick(): void
    {
        $this->startRefusedDial();
        $dial = $this->seedDial();
        $timer = $dial->timer;
        $generation = $dial->generation;
        $dial->nextAttemptAt = self::now();
        $timer->armAt($dial->nextAttemptAt);
        $this->loop->tick();
        $this->assertNull($timer->getDeadline(), 'the due deadline is consumed before its owner runs');
        $this->loop->tick();
        $this->assertGreaterThan($generation, $dial->generation);
        $this->assertTrue($dial->connecting);
        $this->assertNotSame($timer, $dial->timer);
        $this->assertSame($dial->connectStartedAt + 5.0, $dial->timer->getDeadline());
    }

    /** A due connect ceiling wins over a simultaneously writable socket. */
    public function testConnectDeadlineAbortsEvenWhenWriteIsAlsoReady(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $dial = $this->seedDial();
        $timer = $dial->timer;
        $dial->connectStartedAt = self::now() - 5.0;
        $timer->armAt($dial->connectStartedAt + 5.0);
        $this->loop->tick();
        $this->loop->tick();
        $this->assertNull($dial->link);
        $this->assertNull($dial->socket);
        $this->assertFalse($dial->connecting);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertNotSame($timer, $dial->timer);
        $this->assertSame($dial->nextAttemptAt, $dial->timer->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A changed endpoint discards the old fd's queued completion before replacing it. */
    public function testChangedAddressCancelsQueuedOldWriteAndDialBeforeStartingNewEndpoint(): void
    {
        $oldAddress = $this->address($this->fixture->listen());
        $newAddress = $this->address($this->fixture->listen());
        $identity = NodeIdentity::of('node-b', 'Remote', NodeRole::Master, [], $oldAddress);
        Hilos::$cluster->registry()->mergeMembership($identity, microtime(true));
        $server = $this->makeServer([]);
        $source = $this->plainLink();
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->loop->tick();
        $this->loop->tick();
        $dial = new ReflectionProperty($server, 'peerDials')->getValue($server)['node-b'];
        $oldReady = $this->writeReady($dial);
        $oldTimer = $dial->timer;
        $oldReady->markReady();
        $server->onAnnounceReceived($source, new PeerAnnounceDTO(new PeerNodeEntry(
            'node-b', 'Remote', NodeRole::Master, [], $newAddress,
        )));
        $this->assertTrue($oldReady->isClosed());
        $this->assertNull($oldTimer->getDeadline());
        $this->assertNull($dial->socket);
        $this->assertSame(0.0, $dial->nextAttemptAt);
        $this->assertSame($newAddress->toString(), $dial->address->toString());
        $oldReady->markReady();
        $this->loop->tick();
        $this->assertTrue($dial->connecting);
        $this->assertFalse($dial->connectReady, 'a canceled WRITE cannot complete the replacement socket');
    }

    /** A certified inbound route cancels pending duplicates and its close wakes the seed. */
    public function testInboundRouteSuppressesPendingKnownDialAndCloseReleasesIt(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $dial = $this->seedDial();
        $dial->remoteNodeId = 'node-b';
        $inbound = $this->plainLink();
        $remote = NodeIdentity::of('node-b', 'Remote', NodeRole::Master, []);
        $inbound->activate($remote);
        new ReflectionMethod($server, 'addClient')->invoke($server, $inbound);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($server, $inbound);
        $server->onHandshakeComplete($inbound, $remote);
        $this->loop->tick();
        $this->assertFalse($dial->connecting);
        $this->assertNull($dial->socket);
        $this->assertNull($dial->timer);
        $this->assertSame(1, $this->loop->registeredCount(), 'Only the attached inbound link remains watched');
        $this->assertFalse($this->loop->hasReadyWork());

        $server->dropClient($inbound);
        $this->loop->tick();
        $this->assertTrue($dial->connecting, 'a lost inbound route releases its suppressed seed without a tick');
    }

    /** Shutdown owns cancellation even if a completion was already queued. */
    public function testShutdownCancelsPendingAndQueuedCompletionAndForbidsRebind(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $dial = $this->seedDial();
        $ready = $this->writeReady($dial);
        $timer = $dial->timer;
        $ready->markReady();
        $server->prepareShutdown();
        $ready->markReady();
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->assertTrue($ready->isClosed());
        $this->assertNull($timer->getDeadline());
        $this->assertNull($dial->ready);
        $this->assertNull($dial->socket);
        $this->assertNull($dial->link);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** An old due notification cannot release a detached owner or its later binding. */
    public function testDetachedRetryCannotWakeItsFormerOwner(): void
    {
        $this->startRefusedDial();
        $dial = $this->seedDial();
        $timer = $dial->timer;
        $due = new ReflectionProperty($timer, 'ready')->getValue($timer);
        $dial->nextAttemptAt = self::now();
        $timer->armAt($dial->nextAttemptAt);
        $this->server->detachEventLoop();
        $due->markReady();
        $this->loop->tick();
        $this->assertTrue($due->isClosed());
        $this->assertNull($dial->socket);
        $this->assertNull($dial->ready);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->server->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->assertTrue($dial->connecting, 'rebind restores the target on the same real base');
    }

    /** Initial and reload walks make one policy decision per portion; newer direct facts supersede queued facts. */
    public function testPolicyReconcileIsBoundedAndNeverRunsFromUnrelatedTicks(): void
    {
        $oldAddress = $this->address($this->fixture->listen());
        $newAddress = $this->address($this->fixture->listen());
        $registry = Hilos::$cluster->registry();
        foreach (['node-b', 'node-c', 'node-d'] as $nodeId) {
            $registry->mergeMembership(NodeIdentity::of($nodeId, 'Remote', NodeRole::Master, [], $oldAddress), microtime(true));
        }
        $policy = new PeerServerDialCountingPolicy();
        $server = $this->makeServer([], $policy);
        $source = $this->plainLink();
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->assertCount(1, $policy->weighed);
        $this->assertTrue($this->loop->hasReadyWork());
        $server->onAnnounceReceived($source, new PeerAnnounceDTO(new PeerNodeEntry(
            'node-c', 'Remote', NodeRole::Master, [], $newAddress,
        )));
        $this->assertSame(['node-a', 'node-c'], array_keys($policy->weighed));
        $this->assertSame($newAddress->toString(), $policy->weighed['node-c']);
        $this->loop->tick();
        $this->loop->tick();
        $this->assertCount(4, $policy->weighed);
        $this->assertSame(4, $policy->calls, 'the queued older node-c fact was removed');
        for ($pass = 0; $pass < 4; ++$pass) {
            $server->onTick();
            $this->loop->tick();
        }
        $this->assertSame(4, $policy->calls);
        $this->assertFalse($this->loop->hasReadyWork());
        $server->announceLocalNode();
        for ($pass = 0; $pass < 4; ++$pass) {
            $before = $policy->calls;
            $this->loop->tick();
            $this->assertSame($before + 1, $policy->calls);
        }
        $this->assertSame(8, $policy->calls, 'a local configuration event releases a new bounded policy walk');
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** The outgoing link's close schedules its retry even when no peer fact follows. */
    public function testDroppedOutgoingLinkArmsRetryWithoutServerTick(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $server->attachEventLoop($this->loop);
        for ($pass = 0; $pass < 3; ++$pass) {
            $this->loop->tick();
        }
        $dial = $this->seedDial();
        $this->assertNotNull($dial->link);
        $server->dropClient($dial->link);
        $this->loop->tick();
        $this->assertNull($dial->link);
        $this->assertNull($dial->socket);
        $this->assertGreaterThan(self::now(), $dial->nextAttemptAt);
        $this->assertSame($dial->nextAttemptAt, $dial->timer->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A refused client constructor has one pending fd owner and retains ordinary retry containment. */
    public function testConstructorEnvironmentRefusalClosesOnceAndRetries(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        putenv('SOCKET_READ_BUFFER_SIZE=invalid');
        Hilos::$env = new EnvAccessor();
        $this->loop->tick();
        $this->loop->tick();
        $dial = $this->seedDial();
        $this->assertNull($dial->socket);
        $this->assertNull($dial->link);
        $this->assertSame([], $server->getClients());
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertSame($dial->nextAttemptAt, $dial->timer->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Entropy refusal escapes the target guard only after the promoted client's single exit. */
    public function testEntropyRefusalDiscardsPromotedClientAndEscapesTargetGuard(): void
    {
        $server = $this->makeServer([$this->address($this->fixture->listen())]);
        $refusal = new RandomException('Secure source refused');
        $server->setClientSocketAttacher(static function () use ($refusal): void {
            throw $refusal;
        });
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->loop->tick();
        $caught = null;
        try {
            $this->loop->tick();
        } catch (RandomException $exception) {
            $caught = $exception;
        }
        $this->assertSame($refusal, $caught);
        $this->assertSame([], $server->getClients());
        $this->assertNull($this->seedDial()->socket);
        $this->assertSame(0, $this->loop->registeredCount());
    }

    /** Isolates membership, configuration and the one native loop for each case. */
    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE' => '65536',
            'CLUSTER_ENABLED' => 'true',
            'CLUSTER_NODE_ID' => 'node-a',
            'CLUSTER_NODE_NAME' => 'Local',
            'CLUSTER_NODE_ROLE' => 'master',
        ] as $key => $value) {
            $this->previousValues[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $this->fixture = new MutualTlsFixture();
        $this->loop = new EventLoop();
    }

    /** Releases owner handles before closing sockets and restoring the prior facade. */
    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->loop->close();
        $this->fixture->close();
        foreach ($this->sockets as $socket) {
            try {
                socket_close($socket);
            } catch (Error) {
                // A link may already have closed this socket.
            }
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach ($this->previousValues as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /**
     * @param list<PeerAddress> $seeds Initial targets
     * @param ?ConnectionPolicy $policy Selection override for bounded reconciliation cases
     * @return PeerServer Server with startup-prepared mutual TLS and the shared client binding
     */
    private function makeServer(array $seeds, ?ConnectionPolicy $policy = null): PeerServer
    {
        $authority = $this->fixture->issueAuthority('Dial test');
        $tls = new ClusterTlsConfig($this->fixture->issueNode('node-a', $authority), $this->fixture->trustFile($authority));
        $this->server = new PeerServer(
            '127.0.0.1', 0, NodeIdentity::of('node-a', 'Local', NodeRole::Master, []), $seeds, $tls, PeerTestMarkers::shared(),
            $policy,
        );
        $this->server->prepareTlsContexts();
        $this->server->setClientSocketAttacher(function (ClientInterface $client): void {
            $client->attachEventLoop($this->loop, static function (): void {});
        });
        return $this->server;
    }

    /** Holds a bound, non-listening TCP port so the kernel supplies a real ECONNREFUSED. */
    private function startRefusedDial(): void
    {
        $reserved = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->sockets[] = $reserved;
        $this->assertTrue(socket_bind($reserved, '127.0.0.1', 0));
        $this->makeServer([$this->address($reserved)])->attachEventLoop($this->loop);
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
        }
    }

    /** @return PeerDial First configured target */
    private function seedDial(): PeerDial
    {
        return new ReflectionProperty($this->server, 'seedDials')->getValue($this->server)[0];
    }

    /** @return PeerLink Bare source for membership facts; authentication is covered by the peer TLS cases */
    private function plainLink(): PeerLink
    {
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        return new PeerLink(
            $pair[0], $this->server, NodeIdentity::of('node-a', 'Local', NodeRole::Master, []),
            dialer: false, transport: new PlainSocketTransport($pair[0]),
        );
    }

    /**
     * @param PeerDial $dial Pending connect
     * @return ReadyWork Real deferred WRITE notification, retained to probe cancellation
     */
    private function writeReady(PeerDial $dial): ReadyWork
    {
        return new ReflectionProperty($this->loop, 'ioWork')
            ->getValue($this->loop)['object:' . spl_object_id($dial->socket)][Event::WRITE];
    }

    /**
     * @param Socket $socket Bound loopback socket
     * @return PeerAddress Reserved endpoint
     */
    private function address(Socket $socket): PeerAddress
    {
        $this->assertTrue(socket_getsockname($socket, $host, $port));
        return new PeerAddress($host, $port);
    }

    /** @return float Monotonic seconds for bringing this target's existing deadline to now */
    private static function now(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }
}

/** Records decisions without opening sockets, so policy portions can be counted independently of readiness. */
final class PeerServerDialCountingPolicy implements ConnectionPolicy
{
    /** @var array<string, string|null> Last endpoint weighed for each node */
    public array $weighed = [];

    public int $calls = 0;

    /**
     * @param NodeIdentity $local Local node supplied by the server
     * @param ClusterNode $candidate Known node under consideration
     * @return bool False to isolate reconciliation from actual connect work
     */
    public function shouldDial(NodeIdentity $local, ClusterNode $candidate): bool
    {
        ++$this->calls;
        $this->weighed[$candidate->nodeId] = $candidate->address?->toString();
        return false;
    }
}

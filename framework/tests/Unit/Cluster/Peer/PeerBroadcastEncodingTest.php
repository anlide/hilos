<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Tests\Unit\DaemonManagerWorkerBroadcastTest;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use SplQueue;
use WeakReference;

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
        putenv('CLUSTER_NODE_NAME=Local node');
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
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE'] as $key) {
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
        $entry = new PeerNodeEntry('node-d', 'Node node-d', NodeRole::Slave, ['storage'], null);

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

        $toStanding = $this->sentTo($standing);
        $this->assertSame(
            [PeerAnnounceDTO::MESSAGE_TYPE],
            $this->frameTypesOf($toStanding),
            'The peer that was already there hears who joined',
        );
        $announced = json_decode(trim($toStanding), true);
        $this->assertIsArray($announced);
        $this->assertIsArray($announced[PeerAnnounceDTO::FIELD_NODE] ?? null);
        $this->assertArrayNotHasKey(
            'online',
            $announced[PeerAnnounceDTO::FIELD_NODE],
            'It hears who joined and what that node is made of - whether it is alive, it sees for itself (HIL-1059)',
        );
        $this->assertNotContains(
            PeerAnnounceDTO::MESSAGE_TYPE,
            $this->frameTypesOf($this->sentTo($joining)),
            'And the joiner is not told about itself',
        );
    }

    public function testPortionsSpendUnhandshakedVisitsAndPackOnlyOnceAcrossYields(): void
    {
        $server = $this->makeServer();
        for ($index = 0; $index < 65; $index++) {
            $pair = [];
            self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
            socket_set_nonblock($pair[0]);
            socket_set_nonblock($pair[1]);
            array_push($this->sockets, ...$pair);
            $this->attach($server, new PeerLink($pair[0], $server,
                NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []), dialer: false,
                transport: new NamedPeerTestTransport($pair[0], 'unhandshaked-' . $index)));
        }
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $last = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $last);
        $frame = new PeerBroadcastEncodingTestFrame();
        $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('marker'), new SignalData());
        $dispatch = new SignalDispatch($signal, fn() => new ReflectionMethod(PeerServer::class, 'walkFanoutFrame')->invoke(
            $server, $server->clientCandidates(), $frame, static fn(PeerLink $link): bool => true,
        ));
        $dispatch->runPortion(64);
        self::assertSame(0, $frame->encodes);
        self::assertSame('', $this->sentTo($first));
        self::assertSame('', $this->sentTo($last));
        $dispatch->runPortion(2);
        self::assertSame(1, $frame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($first)));
        self::assertSame('', $this->sentTo($last));
        $dispatch->runPortion(1);
        self::assertSame(1, $frame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($last)));
        $dispatch->runPortion(64);
        self::assertFalse($dispatch->hasWork());
    }

    public function testAddressedLookupRejectsAReplacementAndRestoresTheOrdinarySendDoor(): void
    {
        $server = $this->makeServer();
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $target = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $target);
        $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('marker'), new SignalData());
        $attempts = 0;
        $delivered = null;
        $walk = $server->addressedDelivery('node-c', function () use ($server, $signal, &$attempts, &$delivered): void {
            $attempts++;
            $delivered = $server->sendSignalToClientNode('node-c', 'browser', $signal);
        });
        $dispatch = new SignalDispatch($signal, static fn() => $walk);
        $dispatch->runPortion(1);
        self::assertSame(0, $attempts);
        $server->removeClient($target[0]);
        $replacement = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $replacement);
        $dispatch->runPortion(64);
        self::assertSame(1, $attempts);
        self::assertFalse($delivered);
        self::assertSame('', $this->sentTo($replacement));
        self::assertTrue($server->sendSignalToClientNode('node-c', 'browser', $signal));
        self::assertSame([PeerClientSignalDTO::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($replacement)));
    }

    public function testSourceRouteGuardsRejectAbsentReplacedAndReattachedTransports(): void
    {
        $server = $this->makeServer();
        $absent = $server->nodeRouteGuard('node-b');
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $original = $server->nodeRouteGuard('node-b');
        self::assertFalse($absent());
        self::assertTrue($original());
        $server->removeClient($first[0]);
        self::assertFalse($original());
        $this->linkTo($server, 'node-b', NodeRole::Master);
        self::assertFalse($original());
        self::assertFalse($absent());
        self::assertTrue($server->nodeRouteGuard('node-b')());
    }

    public function testAnUnadvancedSourceRouteGuardDoesNotRetainItsServerWithoutGc(): void
    {
        $server = $this->makeServer();
        $guard = $server->nodeRouteGuard('node-b');
        $weak = WeakReference::create($server);
        unset($server);
        self::assertNull($weak->get());
        self::assertFalse($guard());
    }

    public function testNativeSourceJournalKeepsClaimsInterestAndWholeSnapshotsInTheSameFifo(): void
    {
        $server = $this->makeServer();
        $target = $this->linkTo($server, 'node-b', NodeRole::Master);
        $this->clearQueued($target);
        $previousRouter = Hilos::$sr;
        $previousRuntime = Hilos::$rt;
        Hilos::$rt = null;
        $manager = new PeerSourceOrderTestManager();
        $manager->registerServer($server);
        $names = [];
        for ($index = 0; $index < 130; $index++) {
            $name = 'source_' . $index;
            $names[] = $name;
            $manager->getAgentManagerDaemon()->rtNodeSourceMap()->note('owner_' . $index, [$name]);
        }
        $manager->getAgentManagerDaemon()->handleSourceInterest(new WorkerSourceInterestDTO($names, []), 0);
        $server->onSourceInterestReceived($target[0], new PeerSourceInterestDTO('node-b', $names, []));
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        try {
            $manager->claims($server);
            self::assertSame('', $this->sentTo($target), 'A partial claim fold is not a wire report');
            $manager->interest($server);
            $manager->owned($server, 'node-b');
            self::assertSame('', $this->sentTo($target), 'Reader interest cannot overtake the older claim report');
            $ready = 0;
            $due = $loop->createReadyWork(static function () use (&$ready): void { $ready++; });
            for ($portion = 0; $portion < 64; $portion++) {
                $due->markReady();
                $loop->tick();
                $manager->dispatch();
            }
            self::assertSame(64, $ready);
            $journal = $this->sentTo($target);
            self::assertSame([
                PeerRtClaimsDTO::MESSAGE_TYPE, PeerSourceInterestDTO::MESSAGE_TYPE,
                ...array_fill(0, 130, PeerRtSnapshotDTO::MESSAGE_TYPE),
            ], $this->frameTypesOf($journal));
            $frames = array_map(static fn(string $line): array => json_decode($line, true), explode("\n", rtrim($journal, "\n")));
            self::assertCount(130, $frames[0][PeerRtClaimsDTO::FIELD_CLAIMS]);
            self::assertSame($names, $frames[1][PeerSourceInterestDTO::FIELD_RT_COLLECTIONS]);
            self::assertSame('source_0', $frames[2][PeerRtSnapshotDTO::FIELD_COLLECTION_KEY]);
            self::assertSame('source_129', $frames[131][PeerRtSnapshotDTO::FIELD_COLLECTION_KEY]);
            self::assertSame([], $frames[131][PeerRtSnapshotDTO::FIELD_ROWS]);
            $due->close();
        } finally {
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $loop->close();
            Hilos::$sr = $previousRouter;
            Hilos::$rt = $previousRuntime;
        }
    }

    /** A stand cannot inspect private union progress; the real native loop and socket journal expose its delivery boundary. */
    public function testNativeSnapshotQueryRetainsItsOriginalUnionAndFiniteBrowserPrefix(): void
    {
        $server = $this->makeServer();
        $target = $this->linkTo($server, 'node-b', NodeRole::Master);
        $this->clearQueued($target);
        $previousRouter = Hilos::$sr;
        $previousRuntime = Hilos::$rt;
        Hilos::$rt = null;
        $manager = new PeerSourceOrderTestManager();
        $manager->registerServer($server);
        $names = [];
        for ($index = 0; $index < 8192; $index++) {
            $names[] = 'missing-' . $index;
        }
        $manager->getAgentManagerDaemon()->handleSourceInterest(new WorkerSourceInterestDTO($names, []), 0);
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $ready = 0;
        $due = $loop->createReadyWork(static function () use (&$ready): void { $ready++; });
        try {
            $manager->query($server, 'node-b');
            self::assertSame('', $this->sentTo($target), 'No partial query leaves the original effect');
            new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($manager, microtime(true));
            $manager->dispatch();
            $state = new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager);
            $prefix = $state->browsers;
            self::assertNotNull($prefix);
            self::assertFalse($prefix->complete);
            $manager->getAgentManagerDaemon()->handleSourceInterest(new WorkerSourceInterestDTO(['future'], []), 0);
            $manager->query($server, 'node-b');
            self::assertSame('', $this->sentTo($target));
            $journal = '';
            for ($portion = 0; $portion < 160; $portion++) {
                $due->markReady();
                $loop->tick();
                $manager->dispatch();
                $journal .= $this->sentTo($target);
            }
            self::assertSame(160, $ready, 'Native work runs between retained portions');
            self::assertTrue($prefix->complete, 'The finite prefix ends after its original complete query');
            self::assertSame([PeerRtSnapshotQueryDTO::MESSAGE_TYPE, PeerRtSnapshotQueryDTO::MESSAGE_TYPE], $this->frameTypesOf($journal));
            $frames = array_map(static fn(string $line): array => json_decode($line, true), explode("\n", rtrim($journal, "\n")));
            self::assertSame($names, $frames[0][PeerRtSnapshotQueryDTO::FIELD_COLLECTION_KEYS]);
            self::assertSame(['future'], $frames[1][PeerRtSnapshotQueryDTO::FIELD_COLLECTION_KEYS]);
        } finally {
            $due->close();
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $loop->close();
            Hilos::$sr = $previousRouter;
            Hilos::$rt = $previousRuntime;
        }
    }

    public function testNativeReaderWithdrawalCannotOvertakeAnOlderRetainedSnapshot(): void
    {
        $server = $this->makeServer();
        $target = $this->linkTo($server, 'node-b', NodeRole::Master);
        $server->onSourceInterestReceived($target[0], new PeerSourceInterestDTO('node-b', ['rows'], []));
        $this->clearQueued($target);
        $pending = new SplQueue();
        $binding = static function (Generator $delivery) use ($pending): void { $pending->enqueue($delivery); };
        $server->setSourceInterestDelivery($binding);
        $snapshot = new SignalDispatch(null, static function () use ($server): Generator {
            yield;
            yield;
            $server->sendRtSnapshotToNode('node-b', 'rows', ['a' => ['id' => 'a']]);
            yield;
        });
        $snapshot->runPortion(1);
        socket_write($target[1], new PeerSourceInterestDTO('node-b', [], [])->toJson() . "\n");
        $target[0]->read();
        self::assertCount(1, $pending);
        $snapshot->runPortion(64);
        self::assertSame([PeerRtSnapshotDTO::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($target)));
        $delivery = $pending->dequeue();
        $withdrawal = new SignalDispatch(null, static fn() => $delivery);
        $withdrawal->runPortion(64);
        self::assertFalse($withdrawal->hasWork());
        $server->sendRtSnapshotToNode('node-b', 'rows', ['b' => ['id' => 'b']]);
        self::assertSame('', $this->sentTo($target));
        $server->removeSourceInterestDelivery($binding);
    }

    public function testAReplacedPeerCannotInheritAnOlderQueuedReaderReport(): void
    {
        $server = $this->makeServer();
        $old = $this->linkTo($server, 'node-b', NodeRole::Master);
        $pending = new SplQueue();
        $binding = static function (Generator $delivery) use ($pending): void { $pending->enqueue($delivery); };
        $server->setSourceInterestDelivery($binding);
        socket_write($old[1], new PeerSourceInterestDTO('node-b', ['rows'], [])->toJson() . "\n");
        $old[0]->read();
        self::assertCount(1, $pending);
        $replacement = $this->linkTo($server, 'node-b', NodeRole::Master);
        $this->clearQueued($replacement);
        $delivery = $pending->dequeue();
        $dispatch = new SignalDispatch(null, static fn() => $delivery);
        $dispatch->runPortion(64);
        $server->sendRtSnapshotToNode('node-b', 'rows', ['a' => ['id' => 'a']]);
        self::assertSame('', $this->sentTo($replacement));
        $server->removeSourceInterestDelivery($binding);
    }

    /** The private map's partial preparation never leaves a process, so the unit drives the real native loop and socket journal. */
    public function testNativeLargeReaderReportPublishesBothKindsTogetherAfterPortionedPreparation(): void
    {
        $server = $this->makeServer();
        $target = $this->linkTo($server, 'node-b', NodeRole::Master);
        $server->onSourceInterestReceived($target[0], new PeerSourceInterestDTO('node-b', ['old-rt'], ['old-db']));
        $this->clearQueued($target);
        $previousRouter = Hilos::$sr;
        $manager = new PeerSourceOrderTestManager();
        $manager->registerServer($server);
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $map = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($server);
        $rt = [];
        $db = [];
        for ($index = 0; $index < 1024; $index++) {
            $rt[] = 'r-' . $index;
            $db[] = 'd-' . $index;
        }
        $ready = 0;
        $due = $loop->createReadyWork(static function () use (&$ready): void { $ready++; });
        try {
            $wire = new PeerSourceInterestDTO('node-b', $rt, $db)->toJson() . "\n";
            self::assertSame(strlen($wire), socket_write($target[1], $wire));
            $target[0]->read();
            self::assertTrue($map->holds('node-b', SourceChange::KIND_DB, 'old-db'));
            self::assertTrue($map->holds('node-b', SourceChange::KIND_RT, 'old-rt'));
            self::assertFalse($map->holds('node-b', SourceChange::KIND_DB, 'd-0'));
            self::assertFalse($map->holds('node-b', SourceChange::KIND_RT, 'r-0'));
            $manager->dispatch();
            self::assertTrue($map->holds('node-b', SourceChange::KIND_DB, 'old-db'));
            $server->sendRtSnapshotToNode('node-b', 'old-rt', ['old' => ['id' => 'old']]);
            $server->sendRtSnapshotToNode('node-b', 'r-0', ['early' => ['id' => 'early']]);
            $journal = $this->sentTo($target);
            self::assertSame([PeerRtSnapshotDTO::MESSAGE_TYPE], $this->frameTypesOf($journal));
            self::assertSame('old-rt', json_decode($journal, true)[PeerRtSnapshotDTO::FIELD_COLLECTION_KEY]);
            $portions = 0;
            while ($map->holds('node-b', SourceChange::KIND_DB, 'old-db') && $portions < 128) {
                $due->markReady();
                $loop->tick();
                $manager->dispatch();
                $portions++;
                self::assertSame($map->holds('node-b', SourceChange::KIND_DB, 'd-0'), $map->holds('node-b', SourceChange::KIND_RT, 'r-1023'));
                self::assertSame($map->holds('node-b', SourceChange::KIND_DB, 'old-db'), $map->holds('node-b', SourceChange::KIND_RT, 'old-rt'));
            }
            self::assertGreaterThan(1, $portions);
            self::assertLessThan(128, $portions);
            self::assertSame($portions, $ready, 'Native readiness must progress between source-preparation portions');
            self::assertSame($db, $map->collections(SourceChange::KIND_DB));
            self::assertSame($rt, $map->collections(SourceChange::KIND_RT));
            $server->sendRtSnapshotToNode('node-b', 'old-rt', ['late' => ['id' => 'late']]);
            $server->sendRtSnapshotToNode('node-b', 'r-0', ['new' => ['id' => 'new']]);
            $journal = $this->sentTo($target);
            self::assertSame([PeerRtSnapshotDTO::MESSAGE_TYPE], $this->frameTypesOf($journal));
            self::assertSame(['new' => ['id' => 'new']], json_decode($journal, true)[PeerRtSnapshotDTO::FIELD_ROWS]);
        } finally {
            $due->close();
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $loop->close();
            Hilos::$sr = $previousRouter;
        }
    }

    public function testAReportAlreadyPreparingCannotPublishForAReplacementPeerOrBinding(): void
    {
        foreach ([false, true] as $replaceBinding) {
            $server = $this->makeServer();
            $old = $this->linkTo($server, 'node-b', NodeRole::Master);
            $pending = new SplQueue();
            $binding = static function (Generator $delivery) use ($pending): void { $pending->enqueue($delivery); };
            $server->setSourceInterestDelivery($binding);
            $server->onSourceInterestReceived($old[0], new PeerSourceInterestDTO('node-b', ['r-0', 'r-1'], ['d-0', 'd-1']));
            $delivery = $pending->dequeue();
            $dispatch = new SignalDispatch(null, static fn() => $delivery);
            $dispatch->runPortion(6);
            self::assertTrue($dispatch->hasWork(), 'The pair must still be private after preparing its DB names');
            if ($replaceBinding) {
                $replacement = $old;
                $server->setSourceInterestDelivery(static function (Generator $unused): void {});
            } else {
                $replacement = $this->linkTo($server, 'node-b', NodeRole::Master);
            }
            $this->clearQueued($replacement);
            $dispatch->runPortion(64);
            self::assertFalse($dispatch->hasWork());
            $map = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($server);
            self::assertFalse($map->holds('node-b', SourceChange::KIND_DB, 'd-0'));
            self::assertFalse($map->holds('node-b', SourceChange::KIND_RT, 'r-0'));
            $server->sendRtSnapshotToNode('node-b', 'r-0', ['a' => ['id' => 'a']]);
            self::assertSame('', $this->sentTo($replacement));
        }
    }

    public function testAnOpenRemovedLinkCannotUseARosterSlotOccupiedByAnotherConnection(): void
    {
        $server = $this->makeServer();
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $removed = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $removed);
        $server->removeClient($removed[0]);
        Closure::bind(static function () use ($server, $first): void {
            $server->clients[1] = $first[0];
        }, null, PeerServer::class)();
        self::assertFalse($removed[0]->shouldClose());
        $frame = new PeerBroadcastEncodingTestFrame();
        self::assertFalse($server->sendToNode('node-c', $frame));
        self::assertSame(0, $frame->encodes);
        self::assertSame('', $this->sentTo($removed));
        self::assertTrue($server->sendToNode('node-b', $frame));
        self::assertSame(1, $frame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($first)));
    }

    public function testAddressedDeliveryHasTheSameBudgetBehindALargeUnhandshakedRoster(): void
    {
        $server = $this->makeServer();
        for ($index = 0; $index < 130; $index++) {
            $pair = [];
            self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
            socket_set_nonblock($pair[0]);
            socket_set_nonblock($pair[1]);
            array_push($this->sockets, ...$pair);
            $this->attach($server, new PeerLink($pair[0], $server,
                NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []), dialer: false,
                transport: new NamedPeerTestTransport($pair[0], 'unhandshaked-' . $index)));
        }
        $target = $this->linkTo($server, 'node-b', NodeRole::Master);
        $this->clearQueued($target);
        $frame = new PeerBroadcastEncodingTestFrame();
        $delivered = null;
        $walk = $server->addressedDelivery('node-b', function () use ($server, $frame, &$delivered): void {
            $delivered = $server->sendToNode('node-b', $frame);
        });
        $dispatch = new SignalDispatch(null, static fn() => $walk);
        $dispatch->runPortion(1);
        self::assertNull($delivered);
        self::assertSame(0, $frame->encodes);
        $dispatch->runPortion(1);
        self::assertTrue($delivered);
        self::assertFalse($dispatch->hasWork());
        self::assertSame(1, $frame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($target)));
        $dispatch->runPortion(64);
        self::assertSame('', $this->sentTo($target));
    }

    public function testCapturedAnnouncementRunsOnceAndRetainsItsPhysicalBroadcastOrder(): void
    {
        $server = $this->makeServer();
        $first = $this->linkTo($server, 'node-b', NodeRole::Master);
        $last = $this->linkTo($server, 'node-c', NodeRole::Master);
        $this->clearQueued($first, $last);
        $firstFrame = new PeerBroadcastEncodingTestFrame();
        $lastFrame = new PeerBroadcastEncodingTestFrame();
        $announcements = 0;
        $walk = $server->captureBroadcastDelivery(function () use ($server, $firstFrame, $lastFrame, &$announcements): void {
            $announcements++;
            $server->broadcastToNodes($firstFrame);
            $server->broadcastToNodes($lastFrame);
        });
        self::assertSame(1, $announcements);
        self::assertSame(0, $firstFrame->encodes);
        self::assertSame(0, $lastFrame->encodes);
        self::assertSame('', $this->sentTo($first));
        $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName('marker'), new SignalData());
        $dispatch = new SignalDispatch($signal, static fn() => $walk);
        $dispatch->runPortion(2);
        self::assertSame(1, $firstFrame->encodes);
        self::assertSame(0, $lastFrame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($first)));
        self::assertSame('', $this->sentTo($last));
        $dispatch->runPortion(64);
        self::assertSame(1, $announcements);
        self::assertSame(1, $firstFrame->encodes);
        self::assertSame(1, $lastFrame->encodes);
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE], $this->frameTypesOf($this->sentTo($first)));
        self::assertSame([PeerBroadcastEncodingTestFrame::MESSAGE_TYPE, PeerBroadcastEncodingTestFrame::MESSAGE_TYPE],
            $this->frameTypesOf($this->sentTo($last)));
    }

    /**
     * Builds the server under test, with no seeds to dial and no port to listen on.
     *
     * @return PeerServer Server the cases broadcast through
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []),
            [],
            PeerTestTls::unread(),
            PeerTestMarkers::shared(),
        );
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

        $link = new PeerLink(
            $near,
            $server,
            NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []),
            dialer: false,
            transport: new NamedPeerTestTransport($near, $nodeId),
        );
        $this->attach($server, $link);
        socket_write($far, (new PeerHelloDTO(
            PeerProtocol::VERSION,
            $nodeId,
            'Node ' . $nodeId,
            $role,
            [],
            PeerTestMarkers::onWire(),
            null,
        ))->toJson() . "\n");
        $link->read();
        socket_write($far, (new PeerReadyDTO(false))->toJson() . "\n");
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
                $server->addClient($link);
                $server->attachClient($link);
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

/** Real guarded source FIFO; no worker or agent is started by this protocol scenario. */
final class PeerSourceOrderTestManager extends DaemonManager
{
    /** @param PeerServer $server Actual peer transport carrying the claim report */
    public function claims(PeerServer $server): void
    {
        $this->sendRtClaims($server, null);
    }

    /** @param PeerServer $server Actual peer transport carrying reader interest */
    public function interest(PeerServer $server): void
    {
        $this->announceReaderInterest($server);
    }

    /**
     * @param PeerServer $server Actual peer transport carrying complete owned snapshots
     * @param string $nodeId Original recipient
     */
    public function owned(PeerServer $server, string $nodeId): void
    {
        $this->sendRtSnapshotsToNode($server, $nodeId);
    }

    /**
     * @param PeerServer $server Actual peer transport carrying the complete query
     * @param string $nodeId Original recipient
     */
    public function query(PeerServer $server, string $nodeId): void
    {
        $this->sendRtSnapshotQueryToNode($server, $nodeId);
    }

    /** Advance the existing guarded master phase, without a second source driver. */
    public function dispatch(): void
    {
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this);
    }

    /** @return SignalRouter Actual consuming router used by the guarded dispatch state */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Actual source maps without any started agent */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PeerSourceOrderTestAgentManager();
    }
}

/** These source-order scenarios declare reports and never instantiate an agent. */
final class PeerSourceOrderTestAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused requested agent type
     * @param ?string $agentIndex Unused requested instance
     * @return AgentDaemonInterface Never returned; the scenario starts no agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('The source-order scenario starts no agent');
    }
}

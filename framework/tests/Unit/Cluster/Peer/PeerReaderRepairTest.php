<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Tests\Unit\DaemonManagerRtSyncPeerTestRtContext;
use Hilos\Tests\Unit\ReaderNativeAgents;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use SplQueue;

/** The private preparation window is shorter than a stand tick; both real peer protocols run in this process. */
final class PeerReaderRepairTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;

    /** @var array<string, string|false> Original process environment */
    private array $previousValues = [];

    /** @var list<Socket> Sockets retained until every cursor has settled */
    private array $sockets = [];

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testOnlineReplacementRepairsBothDirectionsWithFreshCompleteReports(bool $empty, bool $preparing): void
    {
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        // L1 was dialed by B; the preferred L2 is dialed by A.
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $initial = $empty ? ['retired'] : [];
        $a->announceSourceInterest($initial, $initial);
        $b->announceSourceInterest($initial, $initial);
        $this->relay($oldA, $oldB);
        $this->relay($oldB, $oldA);
        $mapA = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($a);
        $mapB = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($b);
        $queues = [new SplQueue(), new SplQueue()];
        $bindings = [];
        foreach ([$a, $b] as $index => $server) {
            $queue = $queues[$index];
            $bindings[$index] = static function (Generator $delivery) use ($queue): void { $queue->enqueue($delivery); };
            $server->setSourceInterestDelivery($bindings[$index]);
        }
        $rt = $empty ? [] : ['rows', 'second'];
        $db = $empty ? [] : ['records', 'second-db'];
        $a->announceSourceInterest($rt, $db);
        $b->announceSourceInterest($rt, $db);
        self::assertEquals([new PeerSourceInterestDTO('node-a', $rt, $db)], $this->relay($oldA, $oldB));
        self::assertEquals([new PeerSourceInterestDTO('node-b', $rt, $db)], $this->relay($oldB, $oldA));
        $oldCursors = [];
        foreach ($queues as $queue) {
            self::assertCount(1, $queue);
            $delivery = $queue->dequeue();
            // An older finite source head, or private preparation of the report itself.
            $oldCursors[] = new SignalDispatch(null, static function () use ($delivery, $preparing): Generator {
                if (!$preparing) {
                    for ($visit = 0; $visit < 64; ++$visit) {
                        yield;
                    }
                }
                yield from $delivery;
            });
            $oldCursors[array_key_last($oldCursors)]->runPortion($preparing ? ($empty ? 3 : 6) : 1);
            self::assertTrue($oldCursors[array_key_last($oldCursors)]->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $initial, $initial);
        $this->assertReport($mapB, 'node-a', $initial, $initial);

        [$newA, $newB] = $this->connect($a, $b, true, $rt, $db);
        foreach ($oldCursors as $cursor) {
            $cursor->runPortion(256);
            self::assertFalse($cursor->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $initial, $initial);
        $this->assertReport($mapB, 'node-a', $initial, $initial);
        // Only actual new-link reports may publish the replacement's reader map.
        foreach ($queues as $queue) {
            self::assertCount(1, $queue, 'Each endpoint must receive one fresh replacement report without a union change');
            $delivery = $queue->dequeue();
            $cursor = new SignalDispatch(null, static fn(): Generator => $delivery);
            $cursor->runPortion(256);
            self::assertFalse($cursor->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $rt, $db);
        $this->assertReport($mapB, 'node-a', $rt, $db);
        foreach ([[$a, $newA], [$b, $newB]] as [$server, $route]) {
            $server->sendRtSnapshotToNode($server === $a ? 'node-b' : 'node-a', $empty ? 'retired' : 'rows', ['x' => ['id' => 'x']]);
            $frames = $this->take($route);
            self::assertEquals($empty ? [] : [new PeerRtSnapshotDTO($server === $a ? 'node-a' : 'node-b', 'rows', ['x' => ['id' => 'x']])],
                $frames, 'Interest filters must stop retired delivery and admit expanded delivery');
        }
        self::assertSame(['node-b'], $a->linkedNodeIds());
        self::assertSame(['node-a'], $b->linkedNodeIds());
        $a->removeSourceInterestDelivery($bindings[0]);
        $b->removeSourceInterestDelivery($bindings[1]);
    }

    /** Native completion and real peer filters reproduce the partial operation owner's lost-delta window. */
    public function testMissingQueryWaitsForItsOwnInterestBeforeAFullReplicaFromAPartialOwner(): void
    {
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        [$routeA, $routeB] = $this->connect($a, $b, false);
        $reader = new ReaderRepairManager();
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($reader, [$a]);
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$b]);
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        $remoteRuntime = new DaemonManagerRtSyncPeerTestRtContext();
        $remoteRuntime->configure();
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $owner->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(
            'update-owner', [$collection], [$collection],
        ));
        self::assertTrue($owner->getAgentManagerDaemon()->rtNodeSourceMap()->owns($collection));
        self::assertFalse($owner->getAgentManagerDaemon()->rtNodeSourceMap()->ownsFully($collection));
        Hilos::$rt = $remoteRuntime;
        RtSnapshot::replace($collection, ['x' => ['id' => 'x', 'name' => 'v0']]);
        Hilos::$rt = $runtime;
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($reader, $loop);
        $this->phase($reader, 'attachSignalDispatchEventLoop');
        $this->phase($reader, 'attachReaderInterestEventLoop');
        try {
            $this->nativeReport($loop, $reader, new WorkerSourceInterestDTO(array_fill(0, 2048, 'old'), ['old-db']));
            $this->phase($reader, 'dispatchSignals');
            $this->nativeReport($loop, $reader, new WorkerSourceInterestDTO(array_fill(0, 2048, $collection), ['future-db']));
            for ($portion = 0; $portion < 4096 && !$reader->getAgentManagerDaemon()->hasChangedReaderInterest(); ++$portion) {
                $this->phase($reader, 'dispatchSignals');
            }
            self::assertTrue($reader->getAgentManagerDaemon()->hasChangedReaderInterest());
            $this->phase($reader, 'dispatchReaderInterest');
            $this->phase($reader, 'dispatchMissingRtCollections');
            $this->drainManager($reader);
            Hilos::$rt = $remoteRuntime;
            Hilos::$cluster->registerRtSyncSink($owner);
            $first = $this->relay($routeA, $routeB);
            Hilos::$rt = $runtime;
            Hilos::$cluster->registerRtSyncSink($reader);
            $earlyOffers = $this->relay($routeB, $routeA);
            $this->drainManager($reader);
            // Between the two completed announcements, a lawful update owner changes its row.
            Hilos::$rt = $remoteRuntime;
            RtSnapshot::replace($collection, ['x' => ['id' => 'x', 'name' => 'v1']]);
            $b->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, new SignalDTO(
                new SignalSource(SignalSource::RT), new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
                new SignalName(SignalConstants::RT_SYNC_UPDATED), new RtSyncUpdatedSignalData($collection, 'x', ['name' => 'v1']),
            ), partialOwner: true);
            Hilos::$rt = $runtime;
            self::assertSame([], $this->relay($routeB, $routeA), 'The old interest still filters the delta');
            $this->phase($reader, 'dispatchReaderInterest');
            $this->phase($reader, 'dispatchMissingRtCollections');
            $this->drainManager($reader);
            Hilos::$rt = $remoteRuntime;
            Hilos::$cluster->registerRtSyncSink($owner);
            $second = $this->relay($routeA, $routeB);
            Hilos::$rt = $runtime;
            Hilos::$cluster->registerRtSyncSink($reader);
            $offers = $this->relay($routeB, $routeA);
            $this->drainManager($reader);
            self::assertSame(['x' => ['id' => 'x', 'name' => 'v1']], RtSnapshot::rows($collection),
                'The query after the fresh interest must obtain the update that the old interest filtered');
            self::assertEquals([
                new PeerSourceInterestDTO('node-a', ['old'], ['old-db']),
                new PeerRtSnapshotQueryDTO('node-a', ['old']),
            ], $first);
            self::assertEquals([new PeerRtClaimsDTO('node-b', [
                new PeerRtClaimEntry('update-owner', 'update-owner', null, [$collection], [$collection]),
            ])], $earlyOffers, 'The partial owner announces its claim without a future-scope row');
            self::assertEquals([
                new PeerSourceInterestDTO('node-a', [$collection], ['future-db']),
                new PeerRtSnapshotQueryDTO('node-a', [$collection]),
            ], $second);
            self::assertEquals([
                new PeerRtClaimsDTO('node-b', [new PeerRtClaimEntry('update-owner', 'update-owner', null, [$collection], [$collection])]),
                new PeerRtReplicaOfferDTO('node-b', $collection, ['x' => ['id' => 'x', 'name' => 'v1']]),
            ], $offers,
                'The partial owner answers the query; its automatic whole hand-over remains empty');
            $map = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($b);
            $this->assertReport($map, 'node-a', [$collection], ['future-db']);
            $update = new SignalDTO(
                new SignalSource(SignalSource::RT), new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
                new SignalName(SignalConstants::RT_SYNC_UPDATED), new RtSyncUpdatedSignalData($collection, 'x', ['name' => 'v2']),
            );
            $b->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $update, partialOwner: true);
            self::assertEquals([new PeerRtSyncDTO('node-b', SignalTypeConstants::RT_SYNC_UPDATED, $update, true)],
                $this->relay($routeB, $routeA));
            $this->drainManager($reader);
            self::assertSame(['x' => ['id' => 'x', 'name' => 'v2']], RtSnapshot::rows($collection));
            self::assertFalse($this->phase($reader, 'hasRunnableReaderInterest'));
        } finally {
            $this->phase($reader, 'detachReaderInterestEventLoop');
            $this->phase($reader, 'discardSignalDispatch');
            $loop->close();
        }
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        foreach (['CLUSTER_ENABLED' => 'true', 'CLUSTER_NODE_ID' => 'local', 'CLUSTER_NODE_NAME' => 'Local',
            'CLUSTER_NODE_ROLE' => 'master', 'SOCKET_READ_BUFFER_SIZE' => '65536'] as $key => $value) {
            $this->previousValues[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        foreach ($this->previousValues as $key => $value) {
            putenv($key . ($value === false ? '' : '=' . $value));
        }
        parent::tearDown();
    }

    /**
     * @param string $nodeId Local endpoint
     * @return PeerServer Real server with the usual marker and identity validation
     */
    private function server(string $nodeId): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of($nodeId, $nodeId, NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param PeerServer $a First endpoint
     * @param PeerServer $b Second endpoint
     * @param bool $replacement Whether the preferred A-dialed duplicate replaces B's stream
     * @param list<string> $rt Last complete writer RT values
     * @param list<string> $db Last complete writer DB values
     * @return array{array{PeerLink, Socket}, array{PeerLink, Socket}} Independent sockets, relayed byte for byte
     */
    private function connect(PeerServer $a, PeerServer $b, bool $replacement, array $rt = [], array $db = []): array
    {
        $first = $this->link($a, 'node-a', 'node-b', $replacement);
        $second = $this->link($b, 'node-b', 'node-a', !$replacement);
        [$dialer, $acceptor] = $replacement ? [$first, $second] : [$second, $first];
        $dialer[0]->startHandshake();
        $hello = $this->relay($dialer, $acceptor);
        self::assertCount(1, $hello);
        self::assertInstanceOf(PeerHelloDTO::class, $hello[0]);
        $welcome = $this->relay($acceptor, $dialer);
        self::assertCount(1, $welcome, 'No repair frame may precede ready');
        self::assertInstanceOf(PeerWelcomeDTO::class, $welcome[0]);
        self::assertEquals([new PeerReadyDTO($replacement)], $this->relay($dialer, $acceptor));
        $ack = $this->relay($acceptor, $dialer);
        self::assertEquals(new PeerReadyAckDTO(true), $ack[0]);
        if ($replacement) {
            self::assertEquals([new PeerReadyAckDTO(true), new PeerSourceInterestDTO('node-b', $rt, $db)], $ack,
                'Only the fresh complete repair may follow the ack, without roster or full hand-over');
            self::assertEquals([new PeerSourceInterestDTO('node-a', $rt, $db)], $this->relay($dialer, $acceptor),
                'The outgoing endpoint repairs only after receiving the ack');
        } else {
            $this->relay($dialer, $acceptor);
        }
        return [$first, $second];
    }

    /**
     * @param PeerServer $server Real owner
     * @param string $local Certified local identity
     * @param string $remote Certified remote identity
     * @param bool $dialer Protocol direction
     * @return array{PeerLink, Socket} Endpoint and relay socket
     */
    private function link(PeerServer $server, string $local, string $remote, bool $dialer): array
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            socket_set_nonblock($socket);
            $this->sockets[] = $socket;
        }
        $link = new PeerLink($pair[0], $server, NodeIdentity::of($local, $local, NodeRole::Master, []), dialer: $dialer,
            transport: new NamedPeerTestTransport($pair[0], $remote));
        new ReflectionMethod(PeerServer::class, 'addClient')->invoke($server, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($server, $link);
        return [$link, $pair[1]];
    }

    /**
     * @param array{PeerLink, Socket} $from Sending endpoint
     * @param array{PeerLink, Socket} $to Receiving endpoint
     * @return list<PeerDTO> Complete decoded frames, with the same bytes delivered to the actual parser
     */
    private function relay(array $from, array $to): array
    {
        $from[0]->write();
        $bytes = socket_read($from[1], 65536);
        if ($bytes === false) {
            return [];
        }
        self::assertSame(strlen($bytes), socket_write($to[1], $bytes));
        $to[0]->read();
        return $this->decode($bytes);
    }

    /**
     * @param array{PeerLink, Socket} $from Sending endpoint
     * @return list<PeerDTO> Entire decoded output, without a consumer applying the snapshot
     */
    private function take(array $from): array
    {
        $from[0]->write();
        $bytes = socket_read($from[1], 65536);
        return $bytes === false ? [] : $this->decode($bytes);
    }

    /**
     * @param string $bytes Complete newline-delimited protocol bytes
     * @return list<PeerDTO> Every frame, in exact order
     */
    private function decode(string $bytes): array
    {
        return array_map(PeerDTO::fromWire(...), explode("\n", rtrim($bytes, "\n")));
    }

    /**
     * @param SourceReaderMap $map Actual endpoint reader ledger
     * @param string $holder Certified reporter
     * @param list<string> $rt Expected complete runtime half
     * @param list<string> $db Expected complete database half
     */
    private function assertReport(SourceReaderMap $map, string $holder, array $rt, array $db): void
    {
        self::assertSame($rt, $map->collections(SourceChange::KIND_RT));
        self::assertSame($db, $map->collections(SourceChange::KIND_DB));
        foreach ($rt as $key) {
            self::assertTrue($map->holds($holder, SourceChange::KIND_RT, $key));
        }
        foreach ($db as $key) {
            self::assertTrue($map->holds($holder, SourceChange::KIND_DB, $key));
        }
    }

    /**
     * @param EventLoop $loop Actual native callback owner
     * @param ReaderRepairManager $manager Actual source FIFO owner
     * @param WorkerSourceInterestDTO $report Complete worker report
     */
    private function nativeReport(EventLoop $loop, ReaderRepairManager $manager, WorkerSourceInterestDTO $report): void
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $loop->registerRead($pair[0], static function () use ($manager, $report, $pair): void {
            socket_read($pair[0], 1);
            $manager->getAgentManagerDaemon()->handleSourceInterest($report, 3);
        });
        try {
            socket_write($pair[1], 'x');
            $loop->runOnce();
        } finally {
            $loop->unregister($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
        }
    }

    /** @param ReaderRepairManager $manager Actual source FIFO owner */
    private function drainManager(ReaderRepairManager $manager): void
    {
        for ($portion = 0; $portion < 4096 && $this->phase($manager, 'hasRunnableSignalDispatch'); ++$portion) {
            $this->phase($manager, 'dispatchSignals');
        }
        self::assertFalse($this->phase($manager, 'hasRunnableSignalDispatch'));
    }

    /**
     * @param ReaderRepairManager $manager Actual guarded master
     * @param string $method Existing phase
     * @return mixed Phase outcome
     */
    private function phase(ReaderRepairManager $manager, string $method): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($manager);
    }
}

/** Uses the real transport and all inherited source/snapshot phases. */
final class ReaderRepairManager extends DaemonManager
{
    /** @return SignalRouter Ordinary source router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Existing inert worker-report fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ReaderNativeAgents();
    }
}

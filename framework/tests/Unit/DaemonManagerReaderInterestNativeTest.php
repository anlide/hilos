<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Cluster\SourceInterestMesh;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

/** Native intake and the original finite FIFO produce facts consumed only by guarded phases. */
final class DaemonManagerReaderInterestNativeTest extends TestCase
{
    private EventLoop $loop;
    private ReaderNativeManager $manager;
    private ReaderNativeRuntime $runtime;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;
    private string|false $previousEnabled;

    public function testLateCompletePairRemainsRunnableAfterTheLastFifoAndNativeTicketsDrain(): void
    {
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 512, ReaderNativeRuntime::EMPTY), ['7', '0', '7']), 3);
        self::assertFalse($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->phases();
        self::assertSame([], $this->manager->mesh->frames, 'No partial union reaches either business entry');
        self::assertSame(0, $this->manager->announcements);
        $this->drain();
        self::assertFalse($this->invoke('hasRunnableSignalDispatch'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->invoke('hasRunnableReaderInterest'), 'Completion itself requires the next nonblocking pass');
        self::assertTrue($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest(), 'Peek is nonconsuming');
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerClientConnections(new ClusterClientLocation());
        Hilos::$cluster->clientConnections()->attachLocal('phase-order');
        $this->invoke('attachConnectionChangesEventLoop');
        $this->invoke('runGuardedIteration', microtime(true));
        $this->drain();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['7', '0']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
        self::assertSame(['connections', 'interest', 'missing'], $this->manager->order);
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        self::assertSame(1, $this->runtime->empty->presenceReads);
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
        self::assertFalse($this->get('shouldExit'));
        $union = $this->manager->getAgentManagerDaemon()->workerReaderMap()->unionPlan();
        self::assertTrue($union->ready);
        for ($pass = 0; $pass < 12; ++$pass) {
            $this->native(static function (): void {});
            $this->invoke('runGuardedIteration', microtime(true));
        }
        self::assertSame($union, $this->manager->getAgentManagerDaemon()->workerReaderMap()->unionPlan());
        self::assertSame(1, $this->runtime->empty->presenceReads, 'Stable readable I/O never revisits candidates or presence');
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        self::assertCount(2, $this->manager->mesh->frames);
    }

    public function testSecondReaderRepeatedReportAndLastReleasePreserveExactEmptyPair(): void
    {
        $report = new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['db']);
        $this->nativeReport($report, 3);
        $this->settle();
        $this->nativeReport($report, 4);
        $this->settle();
        $this->nativeReport($report, 4);
        $this->settle();
        $this->native(fn() => $this->manager->getAgentManagerDaemon()->releaseReaderInterestOfWorker(3));
        $this->settle();
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        self::assertSame(1, $this->runtime->empty->presenceReads);
        $this->native(fn() => $this->manager->getAgentManagerDaemon()->releaseReaderInterestOfWorker(4));
        $this->drain();
        self::assertTrue($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
            new PeerSourceInterestDTO('local', [], []),
            new PeerRtSnapshotQueryDTO('local', []),
        ], $this->manager->mesh->frames, 'The port records even an empty query invocation without filtering');
        self::assertSame(2, $this->manager->announcements);
        self::assertSame(2, $this->manager->queries);
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
    }

    public function testLaterReportsKeepOriginalCompletePairAndFifoOrder(): void
    {
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 2048, 'old'), ['old-db']), 3);
        $this->phases();
        $this->invoke('dispatchSignals');
        self::assertSame([], $this->manager->mesh->frames);
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 2048, 'future'), ['future-db']), 3);
        for ($portion = 0; $portion < 1000 && !$this->manager->getAgentManagerDaemon()->hasChangedReaderInterest(); ++$portion) {
            $this->invoke('dispatchSignals');
        }
        self::assertTrue($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->phases();
        $this->drain();
        self::assertEquals(new PeerSourceInterestDTO('local', ['old'], ['old-db']), $this->manager->mesh->frames[0]);
        self::assertTrue($this->invoke('hasRunnableReaderInterest'), 'Later completion survives draining the first sends');
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', ['old'], ['old-db']),
            new PeerRtSnapshotQueryDTO('local', ['old']),
            new PeerSourceInterestDTO('local', ['future'], ['future-db']),
            new PeerRtSnapshotQueryDTO('local', ['future']),
        ], $this->manager->mesh->frames);
    }

    /** An announced pair still owns its query when a later complete pair becomes runnable. */
    public function testAnUnspentQueryKeepsItsOriginalPairUntilTheNextGuardedPhase(): void
    {
        $this->nativeReport(new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['old-db']), 3);
        $this->drain();
        $this->invoke('dispatchReaderInterest');
        $this->drain();
        $this->nativeReport(new WorkerSourceInterestDTO([], ['future-db']), 3);
        $this->drain();
        self::assertTrue($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->invoke('dispatchReaderInterest');
        self::assertSame(1, $this->manager->announcements, 'A complete later pair cannot overwrite the first query debt');
        $this->invoke('dispatchMissingRtCollections');
        $this->drain();
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['old-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
            new PeerSourceInterestDTO('local', [], ['future-db']),
            new PeerRtSnapshotQueryDTO('local', []),
        ], $this->manager->mesh->frames);
        self::assertSame(2, $this->manager->announcements);
        self::assertSame(2, $this->manager->queries);
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
    }

    public function testOffclusterConsumesBothCuesAndMeshRuntimeAndOwnershipChangesInventNoMove(): void
    {
        $servers = $this->get('servers');
        $this->set('servers', []);
        $this->nativeReport(new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['db']), 3);
        $this->settle();
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        self::assertSame([], $this->manager->mesh->frames);
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
        $this->set('servers', $servers);
        Hilos::$rt = null;
        $this->manager->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(
            'owner', [ReaderNativeRuntime::EMPTY], [], [ReaderNativeRuntime::EMPTY => ['a']],
        ));
        $this->manager->getAgentManagerDaemon()->handleRtSourceReleased(new WorkerRtSourceReleasedDTO('owner'));
        $this->settle();
        Hilos::$rt = $this->runtime;
        $this->settle();
        self::assertSame([], $this->manager->mesh->frames);
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        self::assertSame(0, $this->runtime->empty->presenceReads);
    }

    public function testLargeOrderedComparisonRemainsFiniteAndPublishesOnlyBothCompleteHalves(): void
    {
        $runtime = [];
        $database = [];
        for ($position = 0; $position < 512; ++$position) {
            $runtime[] = 'runtime-' . $position;
            $database[] = 'database-' . $position;
        }
        $this->nativeReport(new WorkerSourceInterestDTO($runtime, $database), 3);
        $this->settle();
        self::assertCount(2, $this->manager->mesh->frames);
        $database[511] = 'last-database';
        $this->nativeReport(new WorkerSourceInterestDTO($runtime, $database), 3);
        $comparison = $this->manager->getAgentManagerDaemon()->workerReaderMap()->unionPlan();
        for ($portion = 0; $portion < 1000 && !$comparison->ready; ++$portion) {
            $this->invoke('dispatchSignals');
        }
        self::assertTrue($comparison->ready, 'The complete cache can publish before the ordered comparison finishes');
        self::assertFalse($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->invoke('dispatchSignals');
        $this->phases();
        self::assertCount(2, $this->manager->mesh->frames, 'One bounded comparison portion cannot publish a prefix');
        self::assertSame(1, $this->manager->announcements);
        $this->drain();
        self::assertTrue($this->invoke('hasRunnableReaderInterest'));
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', $runtime, array_map(static fn(int $position): string => 'database-' . $position, range(0, 511))),
            new PeerRtSnapshotQueryDTO('local', $runtime),
            new PeerSourceInterestDTO('local', $runtime, $database),
            new PeerRtSnapshotQueryDTO('local', $runtime),
        ], $this->manager->mesh->frames);
        self::assertSame(2, $this->manager->announcements);
        self::assertSame(2, $this->manager->queries);
    }

    public function testSameSourceRebindPreservesCompletedPairAndMovedQueryWithoutTakingTheDeliverySlot(): void
    {
        $delivery = $this->get('sourceInterestDeliveryBinding');
        $this->nativeReport(new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['db']), 3);
        $this->drain();
        $this->invoke('attachReaderInterestEventLoop');
        self::assertSame($delivery, $this->get('sourceInterestDeliveryBinding'));
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('dispatchReaderInterest');
        self::assertTrue($this->invoke('hasRunnableReaderInterest'), 'Announcement has left its original moved-query debt');
        $this->invoke('attachReaderInterestEventLoop');
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('dispatchReaderInterest');
        $this->invoke('dispatchMissingRtCollections');
        $this->drain();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
    }

    public function testMapReplacementRejectsPreparingAndCompletedOldPairsAndRetainsCurrentObligation(): void
    {
        $agents = $this->manager->getAgentManagerDaemon();
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 2048, 'retired'), ['retired-db']), 3);
        $old = WeakReference::create($agents->workerReaderMap());
        new ReflectionProperty(AgentManagerDaemon::class, 'workerReaderMap')->setValue($agents, new SourceReaderMap());
        self::assertNull($old->get(), 'The original immutable roots do not retain their mutable map');
        $this->drain();
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
        $this->nativeReport(new WorkerSourceInterestDTO(['complete-old'], ['old-db']), 3);
        $this->drain();
        self::assertTrue($agents->hasChangedReaderInterest());
        new ReflectionProperty(AgentManagerDaemon::class, 'workerReaderMap')->setValue($agents, null);
        self::assertFalse($agents->hasChangedReaderInterest());
        $this->nativeReport(new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['current-db']), 4);
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['current-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
    }

    public function testReceiverReplacementKeepsCurrentCompleteDebtAndDropsOldMovedQuery(): void
    {
        $old = $this->manager->getAgentManagerDaemon();
        $this->nativeReport(new WorkerSourceInterestDTO(['old'], ['old-db']), 3);
        $this->drain();
        $this->invoke('dispatchReaderInterest');
        $this->drain();
        $current = new ReaderNativeAgents();
        $current->handleSourceInterest(new WorkerSourceInterestDTO([ReaderNativeRuntime::EMPTY], ['new-db']), 4);
        $this->set('agentManagerDaemon', $current);
        $this->invoke('reconcileReaderInterestSources');
        self::assertTrue($this->invoke('hasRunnableReaderInterest'));
        $old->handleSourceInterest(new WorkerSourceInterestDTO(array_fill(0, 2048, 'escaped'), []), 3);
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', ['old'], ['old-db']),
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['new-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
        self::assertSame(1, $this->manager->queries, 'The old announced receiver cannot query through its replacement');
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 512, ReaderNativeRuntime::EMPTY), ['native-db']), 4);
        self::assertFalse($current->hasChangedReaderInterest(), 'The replacement uses the original bounded FIFO, not inline fallback');
        self::assertTrue($this->invoke('hasRunnableSignalDispatch'));
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', ['old'], ['old-db']),
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['new-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['native-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
    }

    public function testPreparingReceiverReplacementCannotLeakOldCompletionAndRebindKeepsTheCurrentCohort(): void
    {
        $old = $this->manager->getAgentManagerDaemon();
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 2048, 'old'), ['old-db']), 3);
        $this->set('agentManagerDaemon', new ReaderNativeAgents());
        $this->invoke('reconcileReaderInterestSources');
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 2048, ReaderNativeRuntime::EMPTY), ['current-db']), 4);
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('attachReaderInterestEventLoop');
        $this->settle();
        self::assertTrue($old->hasChangedReaderInterest(), 'An older FIFO cohort still belongs to its original live owner');
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['current-db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
    }

    public function testTransportReplacementPreservesCurrentPendingPairAndRemovesOnlyItsOwnOldSlot(): void
    {
        $oldPeer = $this->get('servers')[0];
        $foreign = static function (Generator $delivery): void {};
        $oldPeer->setSourceInterestDelivery($foreign);
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 512, ReaderNativeRuntime::EMPTY), ['db']), 3);
        $replacement = new PeerServer('127.0.0.1', 0, NodeIdentity::of('local', 'Local', NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared());
        $this->set('servers', [$replacement]);
        $this->invoke('reconcileReaderInterestSources');
        self::assertSame($foreign, new ReflectionProperty(PeerServer::class, 'sourceInterestDelivery')->getValue($oldPeer));
        self::assertSame($this->get('sourceInterestDeliveryBinding'),
            new ReflectionProperty(PeerServer::class, 'sourceInterestDelivery')->getValue($replacement));
        $this->settle();
        $this->invoke('attachReaderInterestEventLoop');
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
        self::assertSame(1, $this->manager->announcements);
        self::assertSame(1, $this->manager->queries);
        $oldPeer->removeSourceInterestDelivery($foreign);
    }

    public function testEscapedOldDeliveryAndExactRemovalCannotAffectTheReplacementBinding(): void
    {
        $escaped = $this->get('sourceInterestDeliveryBinding');
        $this->invoke('attachSignalDispatchEventLoop');
        $current = $this->get('sourceInterestDeliveryBinding');
        $visits = 0;
        $escaped((static function () use (&$visits): Generator { ++$visits; yield; })());
        $this->drain();
        self::assertSame(0, $visits, 'An old wake incarnation cannot enqueue work after replacement');
        $this->manager->getAgentManagerDaemon()->removeReaderInterestDelivery($escaped);
        self::assertSame($current, $this->get('sourceInterestDeliveryBinding'));
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 512, ReaderNativeRuntime::EMPTY), ['db']), 3);
        self::assertFalse($this->manager->getAgentManagerDaemon()->hasChangedReaderInterest());
        $this->settle();
        self::assertEquals([
            new PeerSourceInterestDTO('local', [ReaderNativeRuntime::EMPTY], ['db']),
            new PeerRtSnapshotQueryDTO('local', [ReaderNativeRuntime::EMPTY]),
        ], $this->manager->mesh->frames);
    }

    public function testTerminalDetachAndExitCannotReviveGuardedWorkThroughAnEscapedDelivery(): void
    {
        $delivery = $this->get('sourceInterestDeliveryBinding');
        $this->invoke('detachReaderInterestEventLoop');
        $this->nativeReport(new WorkerSourceInterestDTO(array_fill(0, 512, 'late'), []), 3);
        $delivery((static function (): Generator { yield; })());
        $this->drain();
        $this->phases();
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
        self::assertSame([], $this->manager->mesh->frames);
        $this->set('shouldExit', true);
        $this->invoke('attachReaderInterestEventLoop');
        $this->phases();
        self::assertFalse($this->invoke('hasRunnableReaderInterest'));
        self::assertSame(0, $this->manager->announcements);
        self::assertSame(0, $this->manager->queries);
    }

    public function testQueryOriginMetadataRetainsNeitherAReplacedReceiverNorItsManager(): void
    {
        // Isolate this owner from the ordinary constructor's independent request/dispatch graph.
        $manager = new ReflectionClass(ReaderNativeManager::class)->newInstanceWithoutConstructor();
        $agents = new ReaderNativeAgents();
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($manager, $agents);
        new ReflectionMethod(DaemonManager::class, 'attachReaderInterestEventLoop')->invoke($manager);
        $agents->handleSourceInterest(new WorkerSourceInterestDTO(['old'], ['old-db']), 3);
        new ReflectionMethod(DaemonManager::class, 'announceReaderInterest')->invoke($manager, null);
        $receiver = WeakReference::create($agents);
        $map = WeakReference::create($agents->workerReaderMap());
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($manager, new ReaderNativeAgents());
        unset($agents);
        self::assertNull($receiver->get());
        self::assertNull($map->get());
        new ReflectionMethod(DaemonManager::class, 'reconcileReaderInterestSources')->invoke($manager);
        self::assertFalse(new ReflectionMethod(DaemonManager::class, 'hasRunnableReaderInterest')->invoke($manager));
        $owner = WeakReference::create($manager);
        unset($manager);
        self::assertNull($owner->get());
    }

    protected function setUp(): void
    {
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        $this->runtime = new ReaderNativeRuntime();
        Hilos::$rt = $this->runtime;
        $this->runtime->configure();
        $this->loop = new EventLoop();
        $this->manager = new ReaderNativeManager();
        $this->set('eventLoop', $this->loop);
        $this->set('servers', [new PeerServer('127.0.0.1', 0, NodeIdentity::of('local', 'Local', NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared())]);
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('attachReaderInterestEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachReaderInterestEventLoop');
        $this->invoke('detachConnectionChangesEventLoop');
        $this->invoke('discardSignalDispatch');
        $this->loop->cleanup();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /**
     * @param WorkerSourceInterestDTO $report Actual paired worker report
     * @param int $worker Actual reporting worker
     */
    private function nativeReport(WorkerSourceInterestDTO $report, int $worker): void
    {
        $this->native(fn() => $this->manager->getAgentManagerDaemon()->handleSourceInterest($report, $worker));
    }

    /** @param Closure $intake Actual report, release or unrelated readable input */
    private function native(Closure $intake): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->loop->registerRead($pair[0], static function () use ($pair, $intake): void {
            socket_read($pair[0], 1);
            $intake();
        });
        try {
            socket_write($pair[1], 'x');
            $this->loop->runOnce();
        } finally {
            $this->loop->unregister($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
        }
    }

    /** Advances only the existing bounded source/effect FIFO, without inventing an announcement tick. */
    private function drain(): void
    {
        for ($portion = 0; $portion < 4096 && $this->invoke('hasRunnableSignalDispatch'); ++$portion) {
            $this->loop->tick();
            $this->invoke('dispatchSignals');
        }
        $this->loop->tick();
        self::assertFalse($this->invoke('hasRunnableSignalDispatch'), 'The original finite source prefix must finish');
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Actual guarded phases, in their ordinary order. */
    private function phases(): void
    {
        $this->invoke('reconcileReaderInterestSources');
        $this->invoke('dispatchReaderInterest');
        $this->invoke('dispatchMissingRtCollections');
    }

    /** Completes original preparation, then spends the resulting whole obligations. */
    private function settle(): void
    {
        $this->drain();
        $this->phases();
        $this->drain();
    }

    /**
     * @param string $method Ordinary manager phase
     * @param mixed ...$arguments Phase input
     * @return mixed Actual phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $field Binding to inspect
     * @return mixed Current binding
     */
    private function get(string $field): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $field)->getValue($this->manager);
    }

    /**
     * @param string $field Source binding
     * @param mixed $value Replacement source
     */
    private function set(string $field, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $field)->setValue($this->manager, $value);
    }
}

final class ReaderNativeManager extends DaemonManager
{
    public readonly ReaderNativeMesh $mesh;
    public int $announcements = 0;
    public int $queries = 0;
    /** @var list<string> Every relevant main-phase entry */
    public array $order = [];

    /** Retains ordinary source dispatch beside a typed, unfiltered outbound port. */
    public function __construct()
    {
        parent::__construct();
        $this->mesh = new ReaderNativeMesh();
    }

    /** @param ?SourceInterestMesh $mesh Actual registered transport */
    protected function announceReaderInterest(?SourceInterestMesh $mesh): void
    {
        ++$this->announcements;
        $this->order[] = 'interest';
        parent::announceReaderInterest($mesh === null ? null : $this->mesh);
    }

    /** @param ?RtSyncMesh $mesh Actual registered transport */
    protected function askRtSnapshotsForMissingCollections(?RtSyncMesh $mesh): void
    {
        ++$this->queries;
        $this->order[] = 'missing';
        parent::askRtSnapshotsForMissingCollections($mesh === null ? null : $this->mesh);
    }

    /** @param ?ClientMesh $mesh Actual connection phase, entered only through its source guard */
    protected function announceConnectionChanges(?ClientMesh $mesh): void
    {
        $this->order[] = 'connections';
        parent::announceConnectionChanges($mesh);
    }

    /** @return SignalRouter Actual ordinary router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Actual mutable reader report owner */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ReaderNativeAgents();
    }

    /** Polls the actual native base without opening the inert fixture listener. */
    protected function processEventLoop(): void
    {
        $this->eventLoop->tick();
    }
}

final class ReaderNativeAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused agent index
     * @return AgentDaemonInterface No fixture agents are started
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new RuntimeException('Reader fixture starts no agents');
    }
}

final class ReaderNativeRuntime extends RtContext
{
    public const string EMPTY = 'readerNativeEmpty';
    public ReaderNativeStates $empty;

    /** A genuine mounted empty collection, counted at the snapshot presence boundary. */
    public function configure(): void
    {
        $this->empty = ReaderNativeStates::init();
        $this->_stateCollections[self::EMPTY] = $this->empty;
        $this->setRepresent(self::EMPTY, ReaderNativeRows::class, ReaderNativeActions::class);
    }
}

final class ReaderNativeRows extends RtCollection
{
    /**
     * @param RtState $state Unused row of an always-empty fixture collection
     * @return RtItem No fixture row is constructed
     */
    protected function createRtItem(RtState $state): RtItem
    {
        throw new RuntimeException('Reader fixture has no rows');
    }
}

/** @property-read ReaderNativeStates $stateCollection Actual mounted empty state */
final class ReaderNativeActions extends RtActions
{
}

final class ReaderNativeStates extends RtStates
{
    public int $presenceReads = 0;

    /** @return int Actual empty collection size */
    public function count(): int
    {
        ++$this->presenceReads;
        return parent::count();
    }
}

/** Records all typed calls, including empty queries, without filtering extra frames. */
final class ReaderNativeMesh implements SourceInterestMesh, RtSyncMesh
{
    /** @var list<object> Every physical output in order */
    public array $frames = [];

    /**
     * @param list<string> $rtCollections Complete ordered runtime union
     * @param list<string> $dbCollections Complete ordered database union
     */
    public function announceSourceInterest(array $rtCollections, array $dbCollections): void
    {
        $this->frames[] = new PeerSourceInterestDTO('local', $rtCollections, $dbCollections);
    }

    /** @param list<string> $collectionKeys Actual missing collection query */
    public function broadcastRtSnapshotQuery(array $collectionKeys): void
    {
        $this->frames[] = new PeerRtSnapshotQueryDTO('local', $collectionKeys);
    }

    /** @return list<string> One live peer */
    public function linkedNodeIds(): array
    {
        return ['remote'];
    }

    /**
     * @param string $signalType Actual sync kind
     * @param SignalDTO $signal Actual sync payload
     * @param bool $partialOwner Original ownership width
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal, bool $partialOwner = false): void
    {
        $this->frames[] = $signal;
    }

    /**
     * @param string $nodeId Original addressee
     * @param string $collectionKey Original collection
     * @param array $rows Original rows
     * @param array $scopeKeys Original named scope
     * @param array $scopeSetKeys Original set scope
     */
    public function sendRtSnapshotToNode(string $nodeId, string $collectionKey, array $rows,
        array $scopeKeys = [], array $scopeSetKeys = []): void
    {
        throw new RuntimeException('Unexpected snapshot');
    }

    /**
     * @param string $nodeId Original peer
     * @param array $collectionKeys Actual requested keys
     */
    public function sendRtSnapshotQueryToNode(string $nodeId, array $collectionKeys): void
    {
        $this->frames[] = new PeerRtSnapshotQueryDTO($nodeId, $collectionKeys);
    }

    /**
     * @param string $nodeId Original peer
     * @param ?string $originNodeId Original writer
     * @param string $collectionKey Original collection
     * @param array $rows Actual offered rows
     */
    public function sendRtReplicaOfferToNode(string $nodeId, ?string $originNodeId, string $collectionKey, array $rows): void
    {
        throw new RuntimeException('Unexpected replica offer');
    }
}

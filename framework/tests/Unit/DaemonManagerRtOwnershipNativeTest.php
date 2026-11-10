<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtClaimMesh;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Cluster\SourceInterestMesh;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtMembershipVersion;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

/** Native facts drive the real ownership business and its real scoped snapshot builder. */
final class DaemonManagerRtOwnershipNativeTest extends TestCase
{
    private EventLoop $loop;
    private OwnershipNativeManager $manager;
    private OwnershipNativeRuntime $runtime;
    private OwnershipNativePeer $peer;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;
    private string|false $previousEnabled;
    /** @var list<Socket> Closed after the native base releases its watches */
    private array $sockets = [];

    public function testOnlyFullNamedPresenceRunsTheSignatureAndKeyWalkOnReadableIo(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->claim('set', OwnershipNativeRuntime::SETS, [], 'owner');
        $this->claim('partial', OwnershipNativeRuntime::OTHER, ['partial'], partial: true);
        $this->dispatch();
        self::assertSame(1, $this->manager->offers);
        self::assertSame(1, $this->runtime->named->presenceReads);
        self::assertEquals([
            new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED, [], ['a']),
            new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::SETS, [], [], ['owner']),
        ], $this->peer->snapshots);

        $this->runtime->view(OwnershipNativeRuntime::SETS)->actions->put('set-a', 'owner', 'first');
        $this->runtime->view(OwnershipNativeRuntime::SETS)['set-a']->actions->rewrite('other', 'moved');
        $this->runtime->view(OwnershipNativeRuntime::SETS)['set-a']->actions->erase();
        $this->runtime->view(OwnershipNativeRuntime::OTHER)->actions->put('partial', 'owner', 'first');
        $this->runtime->view(OwnershipNativeRuntime::OTHER)->actions->put('unclaimed', 'owner', 'first');
        $this->runtime->view(OwnershipNativeRuntime::OTHER)->actions->wipe();
        for ($pass = 0; $pass < 12; ++$pass) {
            $this->readableWake();
            $this->dispatch();
        }
        self::assertSame(1, $this->manager->offers, 'No business entry means no key, aggregate or JSON work');
        self::assertSame(1, $this->runtime->named->presenceReads);
        self::assertCount(2, $this->peer->snapshots);

        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        self::assertSame(1, $this->manager->offers, 'The pre-mutation callback only cues');
        $this->loop->tick();
        self::assertSame(1, $this->runtime->named->presenceReads, 'Native callbacks do not read presence');
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        $this->dispatch();
        self::assertSame(2, $this->manager->offers);
        self::assertSame(2, $this->runtime->named->presenceReads);
        self::assertEquals(new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED,
            ['a' => ['id' => 'a', 'owner' => 'owner', 'text' => 'Ada']], ['a']), $this->peer->snapshots[2]);
        self::assertCount(4, $this->peer->snapshots, 'Both declared scopes retain their original handover order');

        $this->runtime->view(OwnershipNativeRuntime::NAMED)['a']->actions->rewrite('owner', 'Grace');
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'replacement');
        $this->readableWake();
        $this->dispatch();
        self::assertSame(2, $this->manager->offers);
        self::assertSame(2, $this->runtime->named->presenceReads);
        $this->runtime->view(OwnershipNativeRuntime::NAMED)['a']->actions->erase();
        $this->dispatch();
        self::assertSame(3, $this->manager->offers);
        self::assertSame(3, $this->runtime->named->presenceReads);
        self::assertEquals(new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED, [], ['a']), $this->peer->snapshots[4]);
        self::assertCount(6, $this->peer->snapshots);
    }

    public function testReportsReleaseWholeOverlapAndWorkerDeathUseTheOriginalLedger(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->invoke('reconcileRtOwnershipSources');
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        $this->dispatch();
        self::assertSame(2, $this->manager->offers);
        self::assertCount(1, $this->peer->snapshots, 'Equal report compares but never duplicates an offer');
        $this->manager->getAgentManagerDaemon()->handleRtSourceReleased(new WorkerRtSourceReleasedDTO('named'));
        $this->dispatch();
        self::assertSame([], $this->peer->claims[1]);

        $agents = $this->manager->getAgentManagerDaemon();
        $agents->addAgent('whole', $this->createStub(AgentDaemonInterface::class), 7, false);
        $this->claim('whole', OwnershipNativeRuntime::NAMED);
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        self::assertCount(2, $this->peer->snapshots);
        self::assertSame([], $this->peer->snapshots[1]->scopeKeys);
        $reads = $this->runtime->named->presenceReads;
        $offers = $this->manager->offers;
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        $this->dispatch();
        self::assertSame($offers, $this->manager->offers, 'A whole owner suppresses named presence invalidation');
        self::assertSame($reads, $this->runtime->named->presenceReads);
        $agents->releaseRtSourcesOfWorker(7, false);
        $this->dispatch();
        self::assertCount(3, $this->peer->snapshots);
        self::assertSame(['a'], $this->peer->snapshots[2]->scopeKeys);
        self::assertSame(['a' => ['id' => 'a', 'owner' => 'owner', 'text' => 'Ada']], $this->peer->snapshots[2]->rows);
        $agents->handleRtSourceReleased(new WorkerRtSourceReleasedDTO('named'));
        $this->dispatch();
        self::assertSame([], $this->peer->claims[4], 'The whole claim report also announces ownership becoming empty');
    }

    public function testClearWithoutSourceBusAndRealRollbackAdvanceWithoutAba(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        $this->dispatch();
        SourceChangeBus::reset();
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->wipe();
        $this->loop->tick();
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        $this->dispatch();
        self::assertSame([], $this->peer->snapshots[1]->rows);

        $before = RtMembershipVersion::current();
        Database::transactionStart();
        try {
            $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'temporary');
        } finally {
            Database::transactionRollback();
        }
        self::assertGreaterThan($before + 1, RtMembershipVersion::current());
        $this->dispatch();
        self::assertSame(3, $this->runtime->named->presenceReads);
        self::assertCount(2, $this->peer->snapshots, 'The restored absence leaves the completed signature unchanged');

        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'restored');
        $this->dispatch();
        $before = RtMembershipVersion::current();
        Database::transactionStart();
        try {
            $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->wipe();
        } finally {
            Database::transactionRollback();
        }
        self::assertGreaterThan($before + 1, RtMembershipVersion::current());
        $this->dispatch();
        self::assertSame(5, $this->runtime->named->presenceReads);
        self::assertCount(3, $this->peer->snapshots);
        self::assertSame('restored', $this->runtime->view(OwnershipNativeRuntime::NAMED)['a']->toArray()['text']);
    }

    public function testSameContextRebindAndExactSourceQualificationBeforeReconcile(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $oldWake = $this->get('rtOwnershipWake');
        $oldWork = $this->get('rtOwnershipWork');
        $oldNative = new ReflectionProperty(ReadyWork::class, 'callback')->getValue($oldWork);
        $this->invoke('attachRtOwnershipEventLoop');
        $oldWake(OwnershipNativeRuntime::NAMED, 'a');
        $oldNative();
        $this->dispatch();
        self::assertTrue($oldWork->isClosed());
        self::assertSame(1, $this->manager->offers);
        self::assertSame(1, $this->runtime->named->presenceReads);

        $oldStates = $this->runtime->named;
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        $this->dispatch();
        self::assertSame(2, $this->manager->offers);
        self::assertSame(1, $this->runtime->named->presenceReads);
        self::assertCount(1, $this->peer->snapshots);
        $oldStates->add(OwnershipNativeState::fromRow(['id' => 'a', 'owner' => 'owner', 'text' => 'old']));
        $unmounted = OwnershipNativeStates::init();
        $unmounted->setCollectionName(OwnershipNativeRuntime::NAMED);
        $unmounted->add(OwnershipNativeState::fromRow(['id' => 'a', 'owner' => 'owner', 'text' => 'unmounted']));
        $this->dispatch();
        self::assertSame(2, $this->manager->offers, 'A retained name cannot qualify a replaced or unmounted store');

        $oldWake = $this->get('rtOwnershipWake');
        $oldNative = new ReflectionProperty(ReadyWork::class, 'callback')->getValue($this->get('rtOwnershipWork'));
        Hilos::$rt = new OwnershipNativeRuntime();
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        $oldStates->remove('a');
        $oldWake(OwnershipNativeRuntime::NAMED, 'a');
        $oldNative();
        self::assertFalse($this->get('rtOwnershipDue'), 'Escaped callbacks reject replacement even before reconciliation');
        $this->dispatch();
        self::assertSame(3, $this->manager->offers);
        self::assertCount(1, $this->peer->snapshots);
        Hilos::$rt = null;
        $this->dispatch();
        self::assertSame(4, $this->manager->offers);
        self::assertCount(1, $this->peer->snapshots);
    }

    public function testActivatedQueuedAndSynchronousLateFactsRemainFactOnly(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        self::assertTrue($this->loop->hasReadyWork());
        $oldWork = $this->get('rtOwnershipWork');
        $oldNative = new ReflectionProperty(ReadyWork::class, 'callback')->getValue($oldWork);
        $this->invoke('attachRtOwnershipEventLoop');
        $oldNative();
        $this->loop->tick();
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        self::assertSame(1, $this->manager->offers);
        $this->dispatch();
        self::assertCount(2, $this->peer->snapshots);

        $late = $this->loop->createReadyWork(function (): void {
            $this->runtime->view(OwnershipNativeRuntime::NAMED)['a']->actions->erase();
        });
        $late->markReady();
        $this->loop->tick();
        $this->loop->tick();
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        self::assertCount(2, $this->peer->snapshots);
        $this->dispatch();
        self::assertCount(3, $this->peer->snapshots);
        self::assertSame([], $this->peer->snapshots[2]->rows);
        $late->close();
    }

    public function testActualSourceMutationSeesNewPresenceOnlyInTheGuardAndPreservesPhaseOrder(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $wake = $this->get('rtOwnershipWake');
        $observed = [];
        $wrapper = function (?string $collection, ?string $id) use ($wake, &$observed): void {
            $observed[] = $this->runtime->named->get('a') === null;
            $wake($collection, $id);
        };
        RtMembershipVersion::setOwnershipWake($wrapper);
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(OwnershipNativeRuntime::NAMED, 'a',
            ['id' => 'a', 'owner' => 'owner', 'text' => 'Ada']));
        RtMembershipVersion::removeOwnershipWake($wrapper);
        RtMembershipVersion::setOwnershipWake($wake);
        self::assertSame([true], $observed, 'The actual collection fact precedes its membership mutation');
        self::assertSame(2, $this->runtime->named->presenceReads, 'The applicator itself checks presence before creating');
        $this->manager->order = [];
        $this->peer->snapshotSeen = function (): void { $this->manager->order[] = 'offer'; };
        $this->set('servers', [...$this->get('servers'), new OwnershipNativeWorkers()]);
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('attachReaderInterestEventLoop');
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerClientConnections(new ClusterClientLocation('local'));
        Hilos::$cluster->clientConnections()->attachLocal('phase-order');
        $this->invoke('attachConnectionChangesEventLoop');
        $this->manager->getAgentManagerDaemon()->handleSourceInterest(new WorkerSourceInterestDTO([OwnershipNativeRuntime::NAMED], []), 7);
        Hilos::$sr->queueRtSyncSignal(SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(OwnershipNativeRuntime::NAMED, 'a', ['text' => 'delivered']));
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertSame(['connections', 'ownership', 'offer', 'interest', 'missing', 'dispatch'], $this->manager->order);
        self::assertSame(['a' => ['id' => 'a', 'owner' => 'owner', 'text' => 'Ada']], $this->peer->snapshots[1]->rows);
        self::assertFalse($this->get('shouldExit'), 'The actual manager guard completed');
    }

    public function testFailureDoesNotCompleteTheOwnershipPrefix(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->peer->refuseSnapshot = true;
        try {
            $this->dispatch();
            self::fail('The transport failure must escape to the manager guard');
        } catch (RuntimeException $failure) {
            self::assertSame('snapshot refused', $failure->getMessage());
        }
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        self::assertNull($this->get('rtOwnershipSignature'));
        $this->peer->refuseSnapshot = false;
        $this->dispatch();
        self::assertCount(1, $this->peer->snapshots);
        self::assertFalse($this->invoke('hasRunnableRtOwnership'));
    }

    public function testRuntimeNullAndReplacementRebuildActualHeldScopes(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        $this->dispatch();
        Hilos::$rt = null;
        $this->dispatch();
        self::assertEquals(new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED, [], ['a']), $this->peer->snapshots[1]);
        Hilos::$rt = new OwnershipNativeRuntime();
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        Hilos::$rt->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'replacement');
        $this->dispatch();
        self::assertSame(3, $this->manager->offers);
        self::assertSame(['a' => ['id' => 'a', 'owner' => 'owner', 'text' => 'replacement']], $this->peer->snapshots[2]->rows);
        self::assertSame(['a'], $this->peer->snapshots[2]->scopeKeys);
        self::assertCount(3, $this->peer->snapshots);
    }

    public function testAgentReceiverFeatureMountAndGenericResetUseOnlyTheirCurrentSources(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $oldWake = $this->get('rtOwnershipWake');
        $agents = new OwnershipNativeAgents();
        $agents->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('replacement', [OwnershipNativeRuntime::NAMED], [],
            [OwnershipNativeRuntime::NAMED => ['b']]));
        $this->set('agentManagerDaemon', $agents);
        $oldWake(OwnershipNativeRuntime::NAMED, 'a');
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('b', 'owner', 'new owner');
        self::assertFalse($this->get('rtOwnershipDue'), 'Old callbacks cannot publish through a replaced report receiver');
        $this->dispatch();
        self::assertSame(['b'], $this->peer->snapshots[1]->scopeKeys);
        self::assertSame(['b' => ['id' => 'b', 'owner' => 'owner', 'text' => 'new owner']], $this->peer->snapshots[1]->rows);

        $oldStates = $this->runtime->named;
        $replacement = OwnershipNativeStates::init();
        $this->runtime->mountFeatureCollection(OwnershipNativeRuntime::NAMED, $replacement);
        $this->runtime->setRepresent(OwnershipNativeRuntime::NAMED, OwnershipNativeRows::class,
            OwnershipNativeActions::class, OwnershipNativeItemActions::class);
        $this->runtime->bindStateCollectionNames();
        $this->dispatch();
        self::assertEquals(new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED, [], ['b']), $this->peer->snapshots[2]);
        $oldStates->remove('b');
        $this->dispatch();
        self::assertSame(3, $this->manager->offers);
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('b', 'owner', 'current mount');
        $this->dispatch();
        self::assertSame(4, $this->manager->offers);
        self::assertSame(2, $replacement->presenceReads);
        RtMembershipVersion::advance();
        $this->loop->tick();
        self::assertTrue($this->invoke('hasRunnableRtOwnership'));
        $this->dispatch();
        self::assertSame(5, $this->manager->offers, 'A generic basis reset explicitly rechecks the signature');
        self::assertSame(3, $replacement->presenceReads);
        self::assertCount(4, $this->peer->snapshots, 'An equal rebuilt signature remains silent');
    }

    public function testOffclusterOwnershipAndLateHandshakeHaveIndependentCues(): void
    {
        $servers = $this->get('servers');
        $this->set('servers', []);
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $this->runtime->view(OwnershipNativeRuntime::NAMED)->actions->put('a', 'owner', 'Ada');
        $this->dispatch();
        self::assertSame(2, $this->manager->offers);
        self::assertSame([], $this->peer->snapshots);
        self::assertSame([], $this->peer->claims);
        $this->set('servers', $servers);
        $this->dispatch();
        self::assertSame([], $this->peer->snapshots, 'A late peer does not invent a local ownership change');
        $this->manager->handOverRtSnapshots('remote');
        self::assertEquals([new PeerRtSnapshotDTO('local', OwnershipNativeRuntime::NAMED,
            ['a' => ['id' => 'a', 'owner' => 'owner', 'text' => 'Ada']], ['a'])], $this->peer->snapshots);
        self::assertCount(1, $this->peer->claims);
        $this->dispatch();
        self::assertCount(1, $this->peer->snapshots);
    }

    public function testWeakSourcesExactRemovalAndExitCancelPendingWork(): void
    {
        $this->claim('named', OwnershipNativeRuntime::NAMED, ['a']);
        $this->dispatch();
        $oldMap = WeakReference::create($this->manager->getAgentManagerDaemon()->rtNodeSourceMap());
        new ReflectionProperty(AgentManagerDaemon::class, 'rtNodeSourceMap')->setValue(
            $this->manager->getAgentManagerDaemon(), new RtNodeSourceMap(),
        );
        self::assertNull($oldMap->get());
        $this->dispatch();
        self::assertSame([], $this->peer->claims[1]);
        $departed = WeakReference::create($this->runtime);
        Hilos::$rt = null;
        unset($this->runtime);
        self::assertNull($departed->get());
        $this->dispatch();

        $wake = $this->get('rtOwnershipWake');
        $replacementCalls = 0;
        $replacement = static function () use (&$replacementCalls): void { ++$replacementCalls; };
        RtMembershipVersion::setOwnershipWake($replacement);
        RtMembershipVersion::advance();
        $this->invoke('detachRtOwnershipEventLoop');
        RtMembershipVersion::advance();
        self::assertSame(2, $replacementCalls, 'Exact removal leaves a replacement owner attached');
        RtMembershipVersion::removeOwnershipWake($replacement);
        $wake(OwnershipNativeRuntime::NAMED, 'a');
        self::assertFalse($this->invoke('hasRunnableRtOwnership'));
        self::assertFalse($this->loop->hasReadyWork());

        $this->invoke('attachRtOwnershipEventLoop');
        $work = $this->get('rtOwnershipWork');
        $this->set('shouldExit', true);
        $this->invoke('reconcileRtOwnershipSources');
        self::assertTrue($work->isClosed());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertFalse($this->invoke('hasRunnableRtOwnership'));
    }

    public function testTheNativeBindingDoesNotRetainItsManager(): void
    {
        // Isolate this binding from the ordinary constructor's independent request-owner graph.
        $manager = new ReflectionClass(OwnershipNativeManager::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $this->loop);
        $agents = new OwnershipNativeAgents();
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($manager, $agents);
        new ReflectionMethod(DaemonManager::class, 'attachRtOwnershipEventLoop')->invoke($manager);
        $work = new ReflectionProperty(DaemonManager::class, 'rtOwnershipWork')->getValue($manager);
        $wake = new ReflectionProperty(DaemonManager::class, 'rtOwnershipWake')->getValue($manager);
        $oldAgents = WeakReference::create($agents);
        $oldMap = WeakReference::create($agents->rtNodeSourceMap());
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($manager, new OwnershipNativeAgents());
        unset($agents);
        self::assertNull($oldAgents->get());
        self::assertNull($oldMap->get());
        $weak = WeakReference::create($manager);
        unset($manager);
        self::assertNull($weak->get());
        $wake(null, null);
        $this->loop->tick();
        $work->close();
        RtMembershipVersion::removeOwnershipWake($wake);
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
        $this->runtime = new OwnershipNativeRuntime();
        Hilos::$rt = $this->runtime;
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        foreach ([OwnershipNativeRuntime::NAMED, OwnershipNativeRuntime::SETS, OwnershipNativeRuntime::OTHER] as $collection) {
            RtTruthSourceRegistry::registerDaemon($collection);
        }
        $this->loop = new EventLoop();
        $this->manager = new OwnershipNativeManager();
        $this->peer = $this->manager->mesh;
        $this->set('eventLoop', $this->loop);
        $this->set('servers', [new PeerServer('127.0.0.1', 0, NodeIdentity::of('local', 'Local', NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared())]);
        $this->invoke('attachRtOwnershipEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachRtOwnershipEventLoop');
        $this->invoke('detachReaderInterestEventLoop');
        $this->invoke('detachConnectionChangesEventLoop');
        $this->invoke('detachSignalDispatchEventLoop');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        foreach ([OwnershipNativeRuntime::NAMED, OwnershipNativeRuntime::SETS, OwnershipNativeRuntime::OTHER] as $collection) {
            RtTruthSourceRegistry::unregisterDaemon($collection);
        }
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(OwnershipNativeRuntime::NAMED));
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /**
     * @param string $agent Original reporting agent
     * @param string $collection Claimed runtime source
     * @param ?list<string> $keys Named scope or null for the whole collection
     * @param ?string $set Set scope, with an empty named scope
     * @param bool $partial Whether the claim excludes an operation
     */
    private function claim(string $agent, string $collection, ?array $keys = null, ?string $set = null, bool $partial = false): void
    {
        $this->manager->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(
            $agent, [$collection], $partial ? [$collection] : [], $keys === null ? [] : [$collection => $keys],
            $set === null ? [] : [$collection => $set],
        ));
    }

    /** Advances the actual native queue, then the ordinary guarded ownership phase. */
    private function dispatch(): void
    {
        $this->invoke('reconcileRtOwnershipSources');
        $this->loop->tick();
        $this->invoke('dispatchRtOwnership');
    }

    /** One already-readable socket wakes the same native base; no clock or artificial timer. */
    private function readableWake(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        array_push($this->sockets, ...$pair);
        $this->loop->registerRead($pair[0], static function () use ($pair): void { socket_read($pair[0], 1); });
        socket_write($pair[1], 'x');
        $this->loop->runOnce();
        $this->loop->unregister($pair[0]);
    }

    /**
     * @param string $method Actual private ownership phase
     * @param mixed ...$arguments Phase inputs
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $name Manager field
     * @return mixed Current field value
     */
    private function get(string $name): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $name)->getValue($this->manager);
    }

    /**
     * @param string $name Manager field
     * @param mixed $value Fixture binding
     */
    private function set(string $name, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $name)->setValue($this->manager, $value);
    }
}

final class OwnershipNativeManager extends DaemonManager
{
    public int $offers = 0;
    /** @var list<string> Actual main-phase invocation order */
    public array $order = [];

    public readonly OwnershipNativePeer $mesh;

    /** Ordinary manager with an in-memory outbound port beside its actual registered source. */
    public function __construct()
    {
        parent::__construct();
        $this->mesh = new OwnershipNativePeer();
    }

    /** @param ?RtSyncMesh $mesh Actual registered peer */
    protected function offerRtSnapshotsOnOwnershipChange(?RtSyncMesh $mesh): void
    {
        ++$this->offers;
        $this->order[] = 'ownership';
        parent::offerRtSnapshotsOnOwnershipChange($mesh === null ? null : $this->mesh);
    }

    /**
     * @param ?RtClaimMesh $mesh Actual registered claim port
     * @param ?string $nodeId Original report addressee
     */
    protected function sendRtClaims(?RtClaimMesh $mesh, ?string $nodeId): void
    {
        parent::sendRtClaims($mesh === null ? null : $this->mesh, $nodeId);
    }

    /**
     * @param ?RtSyncMesh $mesh Actual registered snapshot port
     * @param string $nodeId Original handover addressee
     */
    protected function sendRtSnapshotsToNode(?RtSyncMesh $mesh, string $nodeId): void
    {
        parent::sendRtSnapshotsToNode($mesh === null ? null : $this->mesh, $nodeId);
    }

    /** @param ?ClientMesh $mesh Connection announcement port */
    protected function announceConnectionChanges(?ClientMesh $mesh): void
    {
        $this->order[] = 'connections';
        parent::announceConnectionChanges($mesh);
    }

    /** @param ?SourceInterestMesh $mesh Interest announcement port */
    protected function announceReaderInterest(?SourceInterestMesh $mesh): void
    {
        $this->order[] = 'interest';
        parent::announceReaderInterest(null);
    }

    /** @param ?RtSyncMesh $mesh Missing-snapshot port */
    protected function askRtSnapshotsForMissingCollections(?RtSyncMesh $mesh): void
    {
        $this->order[] = 'missing';
        parent::askRtSnapshotsForMissingCollections(null);
    }

    /** @return SignalRouter Actual empty router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Actual claim ledger and worker membership */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new OwnershipNativeAgents();
    }

    /** Polls the actual native base without opening the fixture's inert TLS listener. */
    protected function processEventLoop(): void
    {
        $this->eventLoop->tick();
    }

    /**
     * @param SignalDTO $signal Actual queued RT mutation
     * @param ?string $originNodeId Original peer origin
     */
    protected function handleDaemonSignal(SignalDTO $signal, ?string $originNodeId = null): void
    {
        $this->order[] = 'dispatch';
        parent::handleDaemonSignal($signal, $originNodeId);
    }
}

final class OwnershipNativeAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Fixture address
     * @param ?string $agentIndex Fixture index
     * @return AgentDaemonInterface Explicitly supplied agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new RuntimeException('The fixture supplies its agent');
    }
}

/** Socketless worker carrier lets the ordinary dispatcher consume its actual router input. */
final class OwnershipNativeWorkers extends WorkerServer
{
    /** No listener or child process is needed for this in-memory recipient cohort. */
    public function __construct()
    {
    }

    /** The empty fixture cohort has no socket/process maintenance. */
    public function onTick(): void
    {
    }

    /** The fixture never opens a worker listener. */
    protected function onStart(): void
    {
    }
}

final class OwnershipNativePeer implements RtSyncMesh, RtClaimMesh
{
    /** @var list<PeerRtSnapshotDTO> Every real snapshot send, without filtering */
    public array $snapshots = [];
    /** @var list<array> Whole original claim announcements */
    public array $claims = [];
    public bool $refuseSnapshot = false;
    /** @var ?Closure(): void Actual snapshot publication observation */
    public ?Closure $snapshotSeen = null;

    /** @return list<string> One established peer */
    public function linkedNodeIds(): array
    {
        return ['remote'];
    }

    /** @param array $claims Complete original report */
    public function announceRtClaims(array $claims): void
    {
        $this->claims[] = $claims;
    }

    /**
     * @param string $nodeId Addressed original peer
     * @param string $collectionKey Snapshot source
     * @param array<string, array<string, mixed>> $rows Actual built rows
     * @param list<string> $scopeKeys Named ownership scope
     * @param list<string> $scopeSetKeys Set ownership scope
     */
    public function sendRtSnapshotToNode(string $nodeId, string $collectionKey, array $rows,
        array $scopeKeys = [], array $scopeSetKeys = []): void
    {
        if ($this->refuseSnapshot) {
            throw new RuntimeException('snapshot refused');
        }
        $this->snapshots[] = new PeerRtSnapshotDTO('local', $collectionKey, $rows, $scopeKeys, $scopeSetKeys);
        $this->snapshotSeen?->__invoke();
    }

    /**
     * @param string $nodeId Original peer
     * @param array $claims Whole claim report
     */
    public function sendRtClaimsToNode(string $nodeId, array $claims): void
    {
        $this->claims[] = $claims;
    }

    /** This port records announcements; it never starts a peer query. */
    public function broadcastRtClaimsQuery(): void
    {
    }

    /**
     * @param string $nodeId Original peer
     * @param PeerRtClaimRefusedDTO $refusal Unused refusal
     */
    public function sendRtClaimRefused(string $nodeId, PeerRtClaimRefusedDTO $refusal): void
    {
    }

    /**
     * @param string $signalType Sync type
     * @param SignalDTO $signal Original delta
     * @param bool $partialOwner Claim width
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal, bool $partialOwner = false): void
    {
    }

    /**
     * @param string $nodeId Peer
     * @param list<string> $collectionKeys Requested sources
     */
    public function sendRtSnapshotQueryToNode(string $nodeId, array $collectionKeys): void
    {
    }

    /** @param list<string> $collectionKeys Requested sources */
    public function broadcastRtSnapshotQuery(array $collectionKeys): void
    {
    }

    /**
     * @param string $nodeId Peer
     * @param ?string $originNodeId Original writer
     * @param string $collectionKey Source
     * @param array<string, array<string, mixed>> $rows Actual offered copies
     */
    public function sendRtReplicaOfferToNode(string $nodeId, ?string $originNodeId, string $collectionKey, array $rows): void
    {
    }
}

final class OwnershipNativeRuntime extends RtContext
{
    public const string NAMED = 'ownershipNativeNamed';
    public const string SETS = 'ownershipNativeSets';
    public const string OTHER = 'ownershipNativeOther';
    public OwnershipNativeStates $named;

    /** Mounts actual state, views and actions with counted signature presence reads. */
    public function configure(): void
    {
        $this->named = OwnershipNativeStates::init();
        $this->_stateCollections[self::NAMED] = $this->named;
        $this->_stateCollections[self::SETS] = OwnershipNativeStates::init();
        $this->_stateCollections[self::OTHER] = OwnershipNativeStates::init();
        foreach ([self::NAMED, self::SETS, self::OTHER] as $key) {
            $this->setRepresent($key, OwnershipNativeRows::class, OwnershipNativeActions::class, OwnershipNativeItemActions::class);
        }
    }

    /**
     * @param string $key Mounted source
     * @return OwnershipNativeRows Actual caller-facing collection
     */
    public function view(string $key): OwnershipNativeRows
    {
        return $this->getRtCollection($key);
    }
}

/** @extends RtStates<OwnershipNativeState> */
final class OwnershipNativeStates extends RtStates
{
    public const string STATE_CLASS = OwnershipNativeState::class;
    public int $presenceReads = 0;

    /**
     * @param ?string $id Signature's declared key
     * @return bool Actual stored presence
     */
    public function has(?string $id): bool
    {
        ++$this->presenceReads;
        return parent::has($id);
    }
}

final class OwnershipNativeState extends RtState
{
    public const string SET_VIA = 'owner';
    public string $id;
    public string $owner;
    public string $text;

    /**
     * @param array<string, mixed> $row Fixture row
     * @return static Real hydrated runtime row
     */
    public static function fromRow(array $row): static
    {
        $state = new static();
        $state->id = self::requireString($row, 'id');
        $state->owner = self::requireString($row, 'owner');
        $state->text = self::requireString($row, 'text');
        $state->markRtSyncBaseline();
        return $state;
    }

    /** @return string Actual state key */
    public function getId(): string
    {
        return $this->id;
    }

    /** @param array<string, mixed> $diff Partial runtime mutation */
    public function applyDiff(array $diff): void
    {
        $this->owner = self::patchString($diff, 'owner', $this->owner);
        $this->text = self::patchString($diff, 'text', $this->text);
    }

    /** @return array<string, mixed> Actual snapshot row */
    public function toArray(): array
    {
        return ['id' => $this->id, 'owner' => $this->owner, 'text' => $this->text];
    }
}

/** @extends RtItem<OwnershipNativeState> */
final class OwnershipNativeItem extends RtItem
{
    /**
     * @param string $name Caller-facing item property
     * @return mixed Addressed item actions
     */
    public function __get(string $name): mixed
    {
        return $name === self::actions ? $this->getItemActions() : parent::__get($name);
    }

    /** @return array<string, mixed> Actual stored row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/** @extends RtCollection<OwnershipNativeItem, OwnershipNativeActions> */
final class OwnershipNativeRows extends RtCollection
{
    /**
     * @param RtState $state Actual stored row
     * @return OwnershipNativeItem Bound caller-facing item
     */
    protected function createRtItem(RtState $state): OwnershipNativeItem
    {
        return new OwnershipNativeItem($state);
    }
}

/**
 * @extends RtActions<OwnershipNativeItem, OwnershipNativeRows, OwnershipNativeStates>
 * @property-read OwnershipNativeStates $stateCollection Actual mounted store
 */
final class OwnershipNativeActions extends RtActions
{
    /**
     * @param string $id Row key
     * @param string $owner Row's set
     * @param string $text Row value
     */
    public function put(string $id, string $owner, string $text): void
    {
        $this->addStateToCollection(OwnershipNativeState::fromRow(['id' => $id, 'owner' => $owner, 'text' => $text]));
    }

    /** Whole-collection deletion through the canonical action boundary. */
    public function wipe(): void
    {
        $this->clearAllStates();
    }
}

/**
 * @extends RtItemActions<OwnershipNativeItem, OwnershipNativeState>
 * @property-read OwnershipNativeState $state Actual addressed row
 */
final class OwnershipNativeItemActions extends RtItemActions
{
    /**
     * @param string $owner New set
     * @param string $text New value
     */
    public function rewrite(string $owner, string $text): void
    {
        $this->ensureCanWrite();
        $this->state->owner = $owner;
        $this->state->text = $text;
        $this->sync();
    }

    /** Removes the addressed row through its own actions. */
    public function erase(): void
    {
        $this->remove();
    }
}

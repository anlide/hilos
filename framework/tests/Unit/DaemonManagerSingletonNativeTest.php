<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

require_once __DIR__ . '/StatusComponentBootstrapTestSupport.php';

/** Actual native intake, guarded manager passes and unchanged typed singleton bootstrap outcomes. */
final class DaemonManagerSingletonNativeTest extends TestCase
{
    private EventLoop $loop;
    private SingletonNativeManager $manager;
    private SingletonNativeServer $server;
    private StatusBootstrapTestWorkerClient $worker;
    private SingletonNativeRouter $router;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRuntime;
    private string $previousApp;
    private string|false $previousEnabled;
    private string $logFile;

    /** @var list<Socket> Native readable sources, closed after the base releases its watches */
    private array $sockets = [];

    public function testStandaloneReadyAfterEmptyNativeQueueOwesOneNextGuardedBootstrap(): void
    {
        $this->assertFalse($this->runnable());
        $this->native(function (): void { $this->queueReady(); });
        $this->assertSame(0, $this->server->singletonHostCalls, 'native callbacks never invoke business');
        $this->guard();
        $this->assertSame(0, $this->server->singletonHostCalls, 'WORKERS_READY settles after the singleton phase');
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertNull($this->router->getNextQueuedSignal());
        $this->assertTrue($this->runnable(), 'synchronous ready debt survives empty native and source queues');
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertSame(['role', 'role', 'bootstrap'], $this->manager->events);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertInitialSignals([SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START]);
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->loop->hasReadyWork());

        $this->worker->report(new WorkerAgentStartedDTO(SingletonNativeHilos::COMPONENT, SingletonNativeHilos::COMPONENT));
        $this->native(function (): void { $this->queueReady(); });
        $this->guard();
        $this->guard();
        $this->invoke('forceDaemonNodeFrames');
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls, 'I/O, ready, capacity and report facts cannot rearm a finished hook');
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertTrue($this->manager->getAgentManagerDaemon()->isAgentStarted(SingletonNativeHilos::COMPONENT));
        $this->assertInitialSignals([
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START, SignalConstants::WORKERS_READY,
        ]);
        $this->assertFalse($this->runnable());
    }

    public function testWorkersReadyBeforeRealPromotionStartsOnceWithoutAnotherPacket(): void
    {
        $coordinator = $this->coordinator();
        $this->ready();
        $this->assertFalse($this->runnable());
        $this->elect($coordinator);
        $this->assertSame(0, $this->server->singletonHostCalls, 'promotion observer does not run the bootstrap hook');
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertInitialSignals([SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START]);
        $this->assertFalse($this->runnable());
    }

    public function testRealPromotionBeforeWorkersReadyRemainsDormantUntilActualReady(): void
    {
        $coordinator = $this->coordinator();
        $this->elect($coordinator);
        $this->guard();
        $this->assertSame(0, $this->server->singletonHostCalls);
        $this->assertFalse($this->runnable());
        $this->ready();
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertInitialSignals([SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START]);
    }

    public function testRealWorkerLossRearmsBeforeRetainedProjectEffectsAndStartsOnReplacement(): void
    {
        $this->ready();
        $this->guard();
        $this->worker->report(new WorkerAgentStartedDTO(SingletonNativeHilos::COMPONENT, SingletonNativeHilos::COMPONENT));
        $this->server->disconnectWorker();
        // A real finite predecessor keeps the loss effects beyond the eager intake portion.
        $this->invoke('queueSignalEffect', static function (): Generator {
            for ($step = 0; $step < 256; ++$step) {
                yield;
            }
        });
        $this->assertSame(
            [SingletonNativeHilos::COMPONENT],
            $this->manager->getAgentManagerDaemon()->forgetAgentsOfWorker(17, false),
        );
        $this->assertTrue($this->runnable());
        $this->assertSame([], $this->manager->lost, 'rearm precedes the retained project callback');
        $replacement = $this->server->addWorker(18);
        $this->guard();
        $this->guard();
        $this->assertSame(2, $this->server->singletonHostCalls);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $replacement->startIds());
        $this->assertSame(18, $this->manager->getAgentManagerDaemon()->getAgentWorkerId(SingletonNativeHilos::COMPONENT));
        $this->assertSame([[17, false, [SingletonNativeHilos::COMPONENT]]], $this->manager->lost);
        $this->assertInitialSignals([
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START, SignalConstants::INITIAL_AGENTS_START,
        ]);
        $this->assertFalse($this->runnable());
    }

    public function testEmptyLossAlsoRearmsAndFollowerDebtWaitsForPromotion(): void
    {
        $this->ready();
        $this->guard();
        $this->context(new PendingLeadership());
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertNull($this->router->getNextQueuedSignal());
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->context(new StandaloneLeadership());
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->guard();
        $this->assertSame(2, $this->server->singletonHostCalls);
        $this->assertSame([[17, false, []]], $this->manager->lost);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds(), 'pending starts remain deduplicated');
        $this->assertInitialSignals([
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START, SignalConstants::INITIAL_AGENTS_START,
        ]);
    }

    public function testLateServerSameSourceRebindAndReplacementNeverResetTheOnceFlag(): void
    {
        $this->ready();
        $this->set('servers', []);
        $this->assertFalse($this->runnable(), 'no server means dormant, not a due-now retry');
        $this->guard();
        $this->manager->registerServer($this->server);
        $this->assertTrue($this->runnable());
        $this->invoke('attachSingletonStart');
        $this->guard();
        $this->invoke('attachSingletonStart');
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->set('servers', []);
        $replacement = $this->buildServer();
        $this->manager->registerServer($replacement);
        $this->guard();
        $this->assertSame(0, $replacement->singletonHostCalls, 'source identity alone is not a legitimate rearm');
        $this->assertFalse($this->runnable());
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->guard();
        $this->assertSame(1, $replacement->singletonHostCalls);
        $this->assertSame(1, $this->server->singletonHostCalls, 'only the current registered server receives new work');
        $this->assertInitialSignals([
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START, SignalConstants::INITIAL_AGENTS_START,
        ]);
    }

    public function testRealLossBeforeRoleSwapCancelsOldOpportunityAndPreservesOtherConsumers(): void
    {
        $coordinator = $this->coordinator();
        $this->invoke('attachRoleTickEventLoop');
        $this->invoke('attachCronEventLoop');
        $this->invoke('addCronRule', 'singleton-native', '* * * * *');
        $epoch = 0;
        $readiness = 0;
        $coordinator->setEpochWake(static function () use (&$epoch): void { ++$epoch; });
        $coordinator->setReadinessWake(static function () use (&$readiness): void { ++$readiness; });
        $this->elect($coordinator);
        $this->ready();
        $this->manager->arm(hrtime(true) / 1e9 + 60);
        $roleTimer = $this->get('roleTickTimer');
        $this->assertNotNull($roleTimer->getDeadline());
        $this->assertNotNull($this->get('cronMinuteTimer')->getDeadline());
        $this->assertTrue($this->runnable());
        $beforeEpoch = $epoch;
        $beforeReadiness = $readiness;
        $coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'b'));
        $this->assertTrue($this->manager->leaderDuringLoss, 'observer runs before the real coordinator swaps roles');
        $this->assertFalse($coordinator->amLeader());
        $this->assertFalse($this->runnable());
        $this->assertNull($roleTimer->getDeadline());
        $this->assertNull($this->get('cronMinuteTimer')->getDeadline());
        $this->assertGreaterThan($beforeEpoch, $epoch);
        $this->assertGreaterThan($beforeReadiness, $readiness);
        $this->guard();
        $this->assertSame(0, $this->server->singletonHostCalls, 'the old term never got a bootstrap opportunity');
        $this->assertSame(1, $this->server->lostHostCalls);
        $coordinator->tick(2.0);
        $coordinator->tick(3.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(3, true, 'b'));
        $this->assertTrue($coordinator->amLeader());
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertInitialSignals([SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START]);
    }

    public function testContainedPartialBootstrapIsDoneUntilLegitimateRearm(): void
    {
        $this->server->afterBootstrap = static function (): void { throw new RuntimeException('partial singleton hook'); };
        $this->ready();
        $this->guard(newFailures: 1);
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertCount(1, $this->manager->failures);
        $this->assertSame(MasterFailureUnit::AGENT_START, $this->manager->failures[0]->unit);
        $this->assertSame('cluster singletons', $this->manager->failures[0]->address);
        $this->assertSame('partial singleton hook', $this->manager->failures[0]->failure->getMessage());
        $this->assertTrue($this->get('singletonsStarted'));
        $this->assertFalse($this->runnable());
        $this->server->addWorker(18);
        $this->native(function (): void { $this->queueReady(); });
        $this->guard();
        $this->invoke('forceDaemonNodeFrames');
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->server->afterBootstrap = null;
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->assertSame(2, $this->server->singletonHostCalls);
        $this->assertCount(1, $this->manager->failures);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertInitialSignals([
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START,
            SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START,
        ]);
    }

    public function testExitBeforeFirstStartAndLossJustBeforeLeavingNeverRestart(): void
    {
        $this->ready();
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->manager->leave();
        // Transport shutdown is outside this in-memory shouldExit eligibility case.
        $this->set('shutdownStartTime', microtime(true));
        $this->assertFalse($this->runnable());
        $this->guard(leaving: true);
        $this->assertSame(0, $this->server->singletonHostCalls);
        $this->assertFalse($this->get('singletonsStarted'));
        $this->assertSame([], $this->worker->startIds());
        $this->assertInitialSignals([SignalConstants::WORKERS_READY]);
    }

    public function testCompletedStartIsNotRepeatedAfterWorkerLossJustBeforeLeaving(): void
    {
        $this->ready();
        $this->guard();
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->assertTrue($this->runnable());
        $this->manager->leave();
        // Keep this guard on the shouldExit verdict rather than starting fixture transport shutdown.
        $this->set('shutdownStartTime', microtime(true));
        $this->assertFalse($this->runnable());
        $this->guard(leaving: true);
        $this->assertSame(1, $this->server->singletonHostCalls);
        $this->assertSame([SingletonNativeHilos::COMPONENT], $this->worker->startIds());
        $this->assertInitialSignals([SignalConstants::WORKERS_READY, SignalConstants::INITIAL_AGENTS_START]);
    }

    public function testTerminalDetachAndCurrentSourcesDoNotRetainRetiredGraphs(): void
    {
        $this->ready();
        $this->invoke('detachSingletonStart');
        $this->manager->reportAgentsLostWithWorker(17, false, []);
        $this->context(new StandaloneLeadership());
        $this->guard();
        $this->assertFalse($this->runnable());
        $this->assertSame(0, $this->server->singletonHostCalls);
        $this->invoke('attachSingletonStart');
        $this->guard();
        $this->assertSame(1, $this->server->singletonHostCalls);
        // Bare identities isolate this guard from transport registration's self-bound callback.
        $old = new ReflectionClass(SingletonNativeServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(AbstractServer::class, 'isRunning')->setValue($old, true);
        $this->set('servers', [$old]);
        $weakServer = WeakReference::create($old);
        $weakContext = WeakReference::create(Hilos::$cluster);
        $this->invoke('dispatchSingletonStart');
        $this->set('servers', []);
        $this->server = $this->buildServer();
        $this->manager->registerServer($this->server);
        $this->context(new StandaloneLeadership());
        unset($old);
        $this->assertNull($weakServer->get(), 'the scalar guard holds no old server graph');
        $this->assertNull($weakContext->get(), 'the scalar guard holds no old cluster graph');
        $this->guard();
        $this->assertSame(0, $this->server->singletonHostCalls);
        $this->assertFalse($this->runnable());
    }

    /** Saves globals and reuses the real Status worker-buffer and daemon-roster fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousRuntime = Hilos::$rt;
        $this->previousApp = Hilos::appClass();
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, SingletonNativeHilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-singleton-native-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new SingletonNativeManager();
        $this->router = Hilos::$sr;
        $this->set('eventLoop', $this->loop);
        $this->invoke('attachSingletonStart');
        $this->server = $this->buildServer();
        $this->worker = $this->server->addWorker(17);
        $this->manager->registerServer($this->server);
    }

    /** Releases native watches before closing sockets and restores the process facade. */
    protected function tearDown(): void
    {
        $this->invoke('detachSingletonStart');
        $this->invoke('detachRoleTickEventLoop');
        $this->invoke('detachCronEventLoop');
        $this->invoke('detachReadinessEventLoop');
        $this->invoke('discardSignalDispatch');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = $this->previousRuntime;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /** @return SingletonNativeServer Worker transport retaining the production bootstrap hook */
    private function buildServer(): SingletonNativeServer
    {
        $server = new ReflectionClass(SingletonNativeServer::class)->newInstanceWithoutConstructor();
        // The in-memory worker link is already available; the native guard must not bind an OS listener.
        new ReflectionProperty(AbstractServer::class, 'isRunning')->setValue($server, true);
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $this->manager->getAgentManagerDaemon());
        $server->beforeBootstrap = function (): void { $this->manager->events[] = 'bootstrap'; };
        return $server;
    }

    /** @param Leadership $leadership Actual facade role source */
    private function context(Leadership $leadership): void
    {
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        new ReflectionProperty(ClusterContext::class, 'identity')->setValue(
            Hilos::$cluster, NodeIdentity::of('a', 'Node A', NodeRole::Master, []),
        );
        Hilos::$cluster->registerLeadership($leadership);
    }

    /** @return ClusterCoordinator Real coordinator using the existing explicit election-time seam */
    private function coordinator(): ClusterCoordinator
    {
        $mesh = $this->createMock(ConsensusMesh::class);
        $mesh->method('onlineMasterIds')->willReturn(['a', 'b', 'c']);
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('a', ['a', 'b', 'c'], 2, 1000, 1000, 100), $mesh, $this->manager,
        );
        $this->context($coordinator);
        return $coordinator;
    }

    /** @param ClusterCoordinator $coordinator Actual election owner */
    private function elect(ClusterCoordinator $coordinator): void
    {
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($coordinator->amLeader());
    }

    /** Queues and consumes the real readiness signal without invoking singleton business. */
    private function ready(): void
    {
        $this->native(function (): void { $this->queueReady(); });
        $this->invoke('dispatchSignals');
        $this->assertTrue($this->get('workersReady'));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Uses the production readiness frame, decoded by the real manager signal dispatcher. */
    private function queueReady(): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::WORKERS_READY), new SystemSignalDTO(systemName: SignalConstants::WORKERS_READY),
        );
    }

    /** @param Closure $mutation Finite source mutation executed by actual native readable I/O */
    private function native(Closure $mutation): void
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        $read = false;
        $this->loop->registerRead($pair[0], static function () use ($pair, $mutation, &$read): void {
            $read = socket_read($pair[0], 1) === 'x';
            $mutation();
        });
        socket_write($pair[1], 'x');
        $this->loop->runOnce();
        $this->assertTrue($read);
    }

    /**
     * Drives every production guarded phase, including actual native polling and late source delivery.
     *
     * @param int $newFailures Exact number of deliberately contained hook failures
     * @param bool $leaving Whether this case already requested shutdown
     */
    private function guard(int $newFailures = 0, bool $leaving = false): void
    {
        $before = count($this->manager->failures);
        $this->invoke('runGuardedIteration', microtime(true));
        foreach ($this->manager->failures as $failure) {
            $this->assertSame(MasterFailureUnit::AGENT_START, $failure->unit, $failure->failure->getMessage());
        }
        $this->assertCount($before + $newFailures, $this->manager->failures);
        $this->assertSame($leaving, $this->get('shouldExit'));
    }

    /** @param list<string> $names Exact complete routed sequence, with no frame filtering */
    private function assertInitialSignals(array $names): void
    {
        $this->assertSame($names, array_map(static fn(SignalDTO $signal): string => $signal->signalName->getName(), $this->router->seen));
        foreach ($this->router->seen as $index => $signal) {
            $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
            $this->assertSame(SignalTypeConstants::SYSTEM, $signal->signalType->getType());
            $this->assertInstanceOf(SystemSignalDTO::class, $signal->data);
            $this->assertSame($names[$index], $signal->data->systemName);
            $this->assertSame([], $this->router->getDestinations($signal));
        }
    }

    /** @return bool Current causal debt, independent of native queue membership */
    private function runnable(): bool
    {
        return $this->invoke('hasRunnableSingletonStart');
    }

    /**
     * @param string $property Existing manager fact
     * @return mixed Current value
     */
    private function get(string $property): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $property)->getValue($this->manager);
    }

    /**
     * @param string $property Existing fixture binding
     * @param mixed $value Fixture source
     */
    private function set(string $property, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $property)->setValue($this->manager, $value);
    }

    /**
     * @param string $method Actual private owner or guarded phase
     * @param mixed ...$arguments Phase inputs
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }
}

/** Keeps real guarded phases and the shared Status roster while observing the project hooks. */
final class SingletonNativeManager extends DaemonManager
{
    /** @var list<string> Actual role and bootstrap hook order */
    public array $events = [];
    /** @var list<ContainedFailure> Existing contained-failure cards */
    public array $failures = [];
    /** @var list<array{int, bool, list<string>}> Retained project loss notifications */
    public array $lost = [];
    public ?bool $leaderDuringLoss = null;

    /** @param ?float $at Existing monotonic role hook seam */
    public function arm(?float $at): void
    {
        $this->armRoleTickAt($at);
    }

    /** Simulates the same shouldExit fact as a shutdown signal, before shutdown work begins. */
    public function leave(): void
    {
        $this->shouldExit = true;
    }

    /** @param int $term Term lost before the coordinator changes its role */
    public function onLostLeadership(int $term): void
    {
        $this->leaderDuringLoss = Hilos::$cluster?->amLeader();
        parent::onLostLeadership($term);
    }

    /** @return SignalRouter Recording router that preserves production routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new SingletonNativeRouter();
    }

    /** @return AgentManagerDaemon Existing Status bootstrap factory and real agent roster */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new StatusBootstrapTestAgentManager();
    }

    /** Records one role entry without scheduling project work. */
    protected function onTickStandalone(): void
    {
        $this->events[] = 'role';
    }

    /** Records one leader entry without scheduling project work. */
    protected function onTickLeaderMaster(): void
    {
        $this->events[] = 'role';
    }

    /** Records one follower entry without scheduling project work. */
    protected function onTickNotLeaderMaster(): void
    {
        $this->events[] = 'role';
    }

    /** @param ContainedFailure $failure Actual contained failure */
    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }

    /**
     * @param int $workerIndex Lost worker
     * @param bool $isMonopolistic Lost pool
     * @param list<string> $agentIds Lost roster in original order
     */
    protected function onAgentsLostWithWorker(int $workerIndex, bool $isMonopolistic, array $agentIds): void
    {
        $this->lost[] = [$workerIndex, $isMonopolistic, $agentIds];
    }
}

/** Observes every routed envelope while keeping the original destination calculation. */
final class SingletonNativeRouter extends SignalRouter
{
    /** @var list<SignalDTO> Complete routed sequence */
    public array $seen = [];

    /**
     * @param SignalDTO $signal Original unfiltered envelope
     * @return Generator Actual production destination walk
     */
    public function destinationCandidates(SignalDTO $signal): Generator
    {
        $this->seen[] = $signal;
        yield from parent::destinationCandidates($signal);
    }
}

/** Adapts the shared in-memory worker buffer to the native manager guard, without OS workers. */
final class SingletonNativeServer extends WorkerServer
{
    public int $singletonHostCalls = 0;
    public int $lostHostCalls = 0;
    public ?Closure $beforeBootstrap = null;
    public ?Closure $afterBootstrap = null;

    /** Counts actual hook entries and retains the original initial signal and Status start. */
    public function onBecameSingletonHost(): void
    {
        ++$this->singletonHostCalls;
        $this->beforeBootstrap?->__invoke();
        parent::onBecameSingletonHost();
        $this->afterBootstrap?->__invoke();
    }

    /** Counts real role-loss teardown and retains the ordinary stop path. */
    public function onLostSingletonHost(): void
    {
        ++$this->lostHostCalls;
        parent::onLostSingletonHost();
    }

    /**
     * @param int $index Registered regular-worker index
     * @return StatusBootstrapTestWorkerClient Existing serialized-buffer and acknowledgement fixture
     */
    public function addWorker(int $index): StatusBootstrapTestWorkerClient
    {
        $client = new ReflectionClass(StatusBootstrapTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($index);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, true);
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client, new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this),
        );
        new ReflectionProperty(WorkerClient::class, 'workerServer')->setValue($client, $this);
        $this->addClient($client);
        return $client;
    }

    /** Removes the same in-memory link before the real daemon roster reports its lost agents. */
    public function disconnectWorker(): void
    {
        foreach ($this->clients as $client) {
            new ReflectionMethod(AbstractServer::class, 'removeClient')->invoke($this, $client);
        }
    }

    /** In-memory worker links expose their buffers to assertions instead of reading absent sockets. */
    public function onTick(): void
    {
    }

    /** No OS workers or server sockets are launched by this fixture. */
    protected function onStart(): void
    {
    }
}

/** One enabled permanent component exercises the unchanged catalog and worker start path. */
abstract class SingletonNativeHilos extends Hilos
{
    public const string COMPONENT = 'singleton_native_component';
    public const array AGENTS = [self::COMPONENT => []];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'app' => ['name' => 'Application', 'description' => null, 'agentType' => self::COMPONENT],
    ];
}

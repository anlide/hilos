<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/StatusComponentBootstrapTestSupport.php';

/**
 * Status components must start without being addressed by a browser or a bootstrap signal.
 * The worker's real write buffer and the daemon roster expose the ordinary start path.
 */
final class WorkerServerStatusComponentBootstrapTest extends TestCase
{
    private const int WORKER_INDEX = 17;

    /** @var class-string<Hilos> Project facade to restore */
    private string $boundAppClass;

    /** @var array{?SignalRouter, ?RtContext, ?ClusterContext} Facade globals saved before each case */
    private array $facadeGlobals;

    /** Saves the process globals that the isolated lifecycle fixtures replace. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->boundAppClass = Hilos::appClass();
        $this->facadeGlobals = [Hilos::$sr, Hilos::$rt, Hilos::$cluster];
        $this->bindApp(StatusBootstrapTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = null;
    }

    /** Restores the project and runtime boundaries for the next unit case. */
    protected function tearDown(): void
    {
        $this->bindApp($this->boundAppClass);
        [Hilos::$sr, Hilos::$rt, Hilos::$cluster] = $this->facadeGlobals;
        parent::tearDown();
    }

    /** An empty bootstrap list still starts the permanent non-index component. */
    public function testSingletonHostStartsAComponentThroughTheWorkerAndManagerWithoutAddressingIt(): void
    {
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $agent = $manager->getAgent(StatusBootstrapTestHilos::COMPONENT);
        $this->assertNotNull($agent);
        $this->assertSame($worker, $agent->getWorkerClient());
        $this->assertSame(self::WORKER_INDEX, $manager->getAgentWorkerId(StatusBootstrapTestHilos::COMPONENT));
        $this->assertFalse($manager->isAgentStarted(StatusBootstrapTestHilos::COMPONENT));
        $this->assertCount(1, $manager->getAgents());
        $this->assertCount(1, $worker->frames(), 'The component receives only agent_start, with no extra system signal.');
        $signal = $this->takeInitialSignal();
        $this->assertSame([], Hilos::$sr->getDestinations($signal));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** Repeated hooks are harmless both before and after the worker acknowledges its start. */
    public function testRepeatedBootstrapKeepsTheSamePendingAndRunningInstance(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $server->onBecameSingletonHost();
        $agent = $manager->getAgent(StatusBootstrapTestHilos::COMPONENT);

        $server->onBecameSingletonHost();
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $worker->report(new WorkerAgentStartedDTO(StatusBootstrapTestHilos::COMPONENT, StatusBootstrapTestHilos::COMPONENT));
        $server->onBecameSingletonHost();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertSame($agent, $manager->getAgent(StatusBootstrapTestHilos::COMPONENT));
        $this->assertTrue($manager->isAgentStarted(StatusBootstrapTestHilos::COMPONENT));
        $this->assertCount(1, $manager->getAgents());
    }

    /** The existing project bootstrap can address a component already started by the catalog. */
    public function testAnOverlappingBootstrapListDoesNotStartASecondInstance(): void
    {
        $daemon = new StatusBootstrapTestDaemonManager();
        Hilos::$sr = new StatusBootstrapTestRouter();
        [$server, $manager, $worker] = $this->buildServer($daemon->getAgentManagerDaemon());
        $daemon->registerServer($server);

        $server->onBecameSingletonHost();
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $worker->report(new WorkerAgentStartedDTO(StatusBootstrapTestHilos::COMPONENT, StatusBootstrapTestHilos::COMPONENT));
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($daemon);

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertCount(1, $manager->getAgents());
        $messages = array_values(array_filter($worker->frames(), static fn(WorkerDTO $frame): bool => $frame instanceof DaemonAgentMessageDTO));
        $this->assertCount(1, $messages, 'The project bootstrap signal still reaches its original addressee.');
    }

    /** An empty declaration uses the catalog's Service/index fallback. */
    public function testEmptyDeclarationStartsTheIndexFallback(): void
    {
        $this->bindApp(StatusBootstrapFallbackHilos::class);
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();

        $this->assertSame([HilosAgentType::HILOS_INDEX], $worker->startIds());
        $this->assertTrue($manager->hasAgent(HilosAgentType::HILOS_INDEX));
    }

    /** Reading this disabled project's fallback would throw because it declares no index. */
    public function testDisabledStatusNeverReadsOrStartsTheFallback(): void
    {
        $this->bindApp(StatusBootstrapDisabledHilos::class);
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->getAgents());
        $this->takeInitialSignal();
    }

    /** Only component types in the leader cell belong to this new pass. */
    public function testTheCatalogPassDeduplicatesTypesAndLeavesOtherStartPathsAlone(): void
    {
        $this->bindApp(StatusBootstrapMixedHilos::class);
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $manager->requestedTypes);
        $this->assertFalse($manager->hasAgent(StatusBootstrapMixedHilos::NODE));
        $this->assertFalse($manager->hasAgent(StatusBootstrapMixedHilos::POLICY));
        $this->assertFalse($manager->hasAgent(StatusBootstrapTestHilos::UNRELATED));
    }

    /** The lift keeps the original NODE start and adds only leader components beside it. */
    public function testLiftRetainsPerNodeBootstrapWithoutDirectlyStartingPolicyComponents(): void
    {
        $this->bindApp(StatusBootstrapMixedHilos::class);
        [$server, $manager, $worker] = $this->buildServer();

        $server->liftPublic();

        $this->assertSame([StatusBootstrapMixedHilos::NODE, StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertSame([StatusBootstrapMixedHilos::NODE, StatusBootstrapTestHilos::COMPONENT], $manager->requestedTypes);
        $this->assertFalse($manager->hasAgent(StatusBootstrapMixedHilos::POLICY));
    }

    /** Follower gating happens before the catalog read, including on lift. */
    public function testFollowerSkipsEvenAnInvalidComponentCatalog(): void
    {
        $this->bindApp(StatusBootstrapInvalidHilos::class);
        $this->installLeadership(new PendingLeadership());
        [$server, $manager, $worker] = $this->buildServer();

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->requestedTypes);
        $this->takeInitialSignal();
    }

    /** An invalid enabled declaration must remain a declaration failure. */
    public function testLeaderDoesNotHideAnInvalidCatalogBehindAnEmptyPass(): void
    {
        $this->bindApp(StatusBootstrapInvalidHilos::class);
        [$server] = $this->buildServer();

        try {
            $server->onBecameSingletonHost();
            $this->fail('An invalid catalog must be refused.');
        } catch (StatusComponentDeclarationException) {
            $this->takeInitialSignal();
        }
    }

    /** The ordinary loss hook stops the component and a later promotion starts it afresh. */
    public function testComponentFollowsLeadershipLossAndReturn(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->installLeadership(new StandaloneLeadership());
        $server->onBecameSingletonHost();
        $first = $manager->getAgent(StatusBootstrapTestHilos::COMPONENT);

        $this->installLeadership(new PendingLeadership());
        $server->onLostSingletonHost();
        $this->assertFalse($manager->hasAgent(StatusBootstrapTestHilos::COMPONENT));
        $stops = array_values(array_filter($worker->frames(), static fn(WorkerDTO $frame): bool => $frame instanceof AgentStopDTO));
        $this->assertCount(1, $stops);
        $this->assertSame(StatusBootstrapTestHilos::COMPONENT, $stops[0]->agentId);
        $worker->report(new WorkerAgentStoppedDTO(StatusBootstrapTestHilos::COMPONENT));
        $server->onBecameSingletonHost();
        $this->assertCount(1, $worker->startIds());

        $this->installLeadership(new StandaloneLeadership());
        $server->onBecameSingletonHost();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT, StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertNotSame($first, $manager->getAgent(StatusBootstrapTestHilos::COMPONENT));
        $this->assertSame(self::WORKER_INDEX, $manager->getAgentWorkerId(StatusBootstrapTestHilos::COMPONENT));
    }

    /** Losing the hosting worker reaches the daemon's real rearm and ensure-once paths. */
    public function testWorkerDeathRearmsTheSingletonPassAndStartsOnTheReplacementWorker(): void
    {
        $daemon = new StatusBootstrapTestDaemonManager();
        [$server, $manager, $worker] = $this->buildServer($daemon->getAgentManagerDaemon());
        $daemon->registerServer($server);
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($daemon, true);
        $ensure = new ReflectionMethod(DaemonManager::class, 'ensureSingletonsStarted');

        $ensure->invoke($daemon);
        $ensure->invoke($daemon);
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $server->disconnectWorker();
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $manager->forgetAgentsOfWorker(self::WORKER_INDEX, false));
        $this->assertFalse($manager->hasAgent(StatusBootstrapTestHilos::COMPONENT));
        $replacement = $server->addWorker(self::WORKER_INDEX + 1);

        $ensure->invoke($daemon);
        $ensure->invoke($daemon);

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $replacement->startIds());
        $this->assertSame(self::WORKER_INDEX + 1, $manager->getAgentWorkerId(StatusBootstrapTestHilos::COMPONENT));
    }

    /** A failed type is contained once, even when two components name it. */
    public function testOneFailedTypeDoesNotStrandTheNextComponentOrQueuedProjectBootstrap(): void
    {
        $this->bindApp(StatusBootstrapFailureHilos::class);
        $daemon = new StatusBootstrapTestDaemonManager();
        Hilos::$sr = new StatusBootstrapTestRouter();
        [$server, $manager, $worker] = $this->buildServer($daemon->getAgentManagerDaemon());
        $daemon->registerServer($server);
        $sink = new StatusBootstrapTestFailureSink();
        $server->setContainedFailureSink($sink);
        $manager->failingType = StatusBootstrapFailureHilos::BROKEN;

        $server->onBecameSingletonHost();
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($daemon);

        $this->assertSame([StatusBootstrapFailureHilos::BROKEN, StatusBootstrapTestHilos::COMPONENT], $manager->requestedTypes);
        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertFalse($manager->hasAgent(StatusBootstrapFailureHilos::BROKEN));
        $this->assertCount(1, $sink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $sink->reported[0]->unit);
        $this->assertSame(StatusBootstrapFailureHilos::BROKEN, $sink->reported[0]->address);
        $this->assertInstanceOf(AgentDaemonCreationFailedException::class, $sink->reported[0]->failure);
        $worker->report(new WorkerAgentStartedDTO(StatusBootstrapTestHilos::COMPONENT, StatusBootstrapTestHilos::COMPONENT));
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($daemon);
        $messages = array_values(array_filter($worker->frames(), static fn(WorkerDTO $frame): bool => $frame instanceof DaemonAgentMessageDTO));
        $this->assertCount(1, $messages, 'The bootstrap queued before the failed start is still delivered.');
    }

    /**
     * @return iterable<string, array{string}> Closed freeze phases
     */
    public static function closedPhases(): iterable
    {
        yield 'activating' => [StateProtectedModeRuntime::PHASE_ACTIVATING];
        yield 'active' => [StateProtectedModeRuntime::PHASE_ACTIVE];
        yield 'deactivating' => [StateProtectedModeRuntime::PHASE_DEACTIVATING];
    }

    /**
     * A first start refused by the real freeze gate has no roster entry for a lift to replay.
     *
     * @param string $phase Closed freeze phase
     */
    #[DataProvider('closedPhases')]
    public function testLiftStartsAComponentWhoseFirstStartWasRefusedByFreeze(string $phase): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->freeze($phase);
        $server->ensureAgentUp(StatusBootstrapTestHilos::COMPONENT, null);
        $server->onBecameSingletonHost();
        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->requestedTypes);
        $this->assertFalse($manager->hasAgent(StatusBootstrapTestHilos::COMPONENT));
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        $this->assertFalse($server->awaitsProtectedModeResume(StatusBootstrapTestHilos::COMPONENT, null));

        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING);
        $server->resumeAgentsForProtectedMode();
        $server->rosterStepPublic();
        $server->liftPublic();
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $server->resumeAgentsForProtectedMode();
        $server->rosterStepPublic();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertSame(self::WORKER_INDEX, $manager->getAgentWorkerId(StatusBootstrapTestHilos::COMPONENT));
    }

    /** A final lift can be the first opportunity to start, without an earlier verification window. */
    public function testInactiveLiftStartsAComponentWithoutAnEarlierVerificationWindow(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE);
        $server->ensureAgentUp(StatusBootstrapTestHilos::COMPONENT, null);
        $this->assertFalse($manager->hasAgent(StatusBootstrapTestHilos::COMPONENT));

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $server->liftPublic();
        $server->liftPublic();

        $this->assertSame([StatusBootstrapTestHilos::COMPONENT], $worker->startIds());
        $this->assertTrue($manager->hasAgent(StatusBootstrapTestHilos::COMPONENT));
    }

    /** Opening either lift phase on a follower does not grant leadership. */
    public function testFollowerLiftDoesNotRaiseTheLeaderComponent(): void
    {
        [$server, $manager, $worker] = $this->buildServer();
        $this->installLeadership(new PendingLeadership());
        foreach ([StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::PHASE_INACTIVE] as $phase) {
            $this->freeze($phase);
            $server->liftPublic();
        }

        $this->assertSame([], $worker->frames());
        $this->assertSame([], $manager->requestedTypes);
    }

    /**
     * @param ?StatusBootstrapTestAgentManager $manager Optional daemon-owned manager
     * @return array{StatusBootstrapTestWorkerServer, StatusBootstrapTestAgentManager, StatusBootstrapTestWorkerClient} Linked fixture
     */
    private function buildServer(?StatusBootstrapTestAgentManager $manager = null): array
    {
        return StatusComponentBootstrapTestSupport::buildServer($manager, self::WORKER_INDEX);
    }

    /**
     * @return SignalDTO Unchanged project bootstrap envelope
     */
    private function takeInitialSignal(): SignalDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::SYSTEM, $signal->signalType->getType());
        $this->assertSame(SignalConstants::INITIAL_AGENTS_START, $signal->signalName->getName());
        $this->assertInstanceOf(SystemSignalDTO::class, $signal->data);
        $this->assertSame(SignalConstants::INITIAL_AGENTS_START, $signal->data->systemName);

        return $signal;
    }

    /**
     * @param class-string<Hilos> $appClass Fixture facade
     */
    private function bindApp(string $appClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $appClass);
    }

    /**
     * @param Leadership $leadership Local leadership verdict
     */
    private function installLeadership(Leadership $leadership): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($leadership);
    }

    /**
     * @param string $phase Freeze phase received through the runtime snapshot boundary
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new StatusBootstrapTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::initiatorAgentType => 'restorer',
            StateProtectedModeRuntime::initiatorAgentIndex => null,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }
}

/** Uses the production ensure-once, rearm and signal-dispatch implementations. */
final class StatusBootstrapTestDaemonManager extends DaemonManager
{
    /** @return SignalRouter Router with an empty project bootstrap list */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return StatusBootstrapTestAgentManager Real roster with the fixture factory */
    protected function createAgentManagerDaemon(): StatusBootstrapTestAgentManager
    {
        return new StatusBootstrapTestAgentManager();
    }
}

/** Project bootstrap list overlapping the declared Status component. */
final class StatusBootstrapTestRouter extends SignalRouter
{
    /** @return list<string> Original bootstrap addressee */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [StatusBootstrapTestHilos::COMPONENT];
    }
}

/** Records the production containment cards. */
final class StatusBootstrapTestFailureSink implements ContainedFailureSink
{
    /** @var list<ContainedFailure> Failures in report order */
    public array $reported = [];

    /**
     * @param ContainedFailure $failure Contained start failure
     */
    public function reportContainedFailure(ContainedFailure $failure): void
    {
        $this->reported[] = $failure;
    }
}

/** Supplies only the framework's unconditional freeze row mount. */
final class StatusBootstrapTestRtContext extends RtContext
{
    /** No project runtime collections are required. */
    public function configure(): void
    {
    }
}

/** Enabled project with a permanent component absent from the router bootstrap list. */
abstract class StatusBootstrapTestHilos extends Hilos
{
    public const string COMPONENT = 'bootstrap_component';
    public const string UNRELATED = 'unrelated';

    public const array AGENTS = [self::COMPONENT => [], self::UNRELATED => []];

    protected const array FEATURES = [HilosFeature::STATUS];

    protected const array STATUS_COMPONENTS = [
        'app' => ['name' => 'Application', 'description' => null, 'agentType' => self::COMPONENT],
    ];
}

/** Includes repeated types, NODE and POLICY components, and an unrelated leader singleton. */
abstract class StatusBootstrapMixedHilos extends StatusBootstrapTestHilos
{
    public const string NODE = 'node_component';
    public const string POLICY = 'policy_component';

    public const array AGENTS = [
        ...parent::AGENTS,
        self::NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        self::POLICY => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];

    protected const array STATUS_COMPONENTS = [
        ...parent::STATUS_COMPONENTS,
        'alias' => ['name' => 'Another facet', 'description' => null, 'agentType' => self::COMPONENT],
        'node' => ['name' => 'Node', 'description' => null, 'agentType' => self::NODE],
        'policy' => ['name' => 'Placed', 'description' => null, 'agentType' => self::POLICY],
    ];
}

/** A repeated failing type precedes a healthy component in declaration order. */
abstract class StatusBootstrapFailureHilos extends StatusBootstrapTestHilos
{
    public const string BROKEN = 'broken_component';

    public const array AGENTS = [...parent::AGENTS, self::BROKEN => []];

    protected const array STATUS_COMPONENTS = [
        'broken' => ['name' => 'Broken', 'description' => null, 'agentType' => self::BROKEN],
        'alias' => ['name' => 'Broken alias', 'description' => null, 'agentType' => self::BROKEN],
        ...parent::STATUS_COMPONENTS,
    ];
}

/** The default declaration resolves the registered index agent. */
abstract class StatusBootstrapFallbackHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];

    protected const array FEATURES = [HilosFeature::STATUS];
}

/** A disabled feature must not try to resolve its missing index fallback. */
abstract class StatusBootstrapDisabledHilos extends Hilos
{
    public const array AGENTS = [];
}

/** An enabled empty declaration whose index fallback is unregistered. */
abstract class StatusBootstrapInvalidHilos extends StatusBootstrapDisabledHilos
{
    protected const array FEATURES = [HilosFeature::STATUS];
}

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
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** The journal starts through the real manager and worker gates without a frame addressed to it. */
final class WorkerServerChangeLogBootstrapTest extends TestCase
{
    private const int WORKER_INDEX = 17;

    /** @var class-string<Hilos> Project facade to restore */
    private string $boundAppClass;

    /** @var array{?SignalRouter, ?RtContext, ?ClusterContext} Process globals replaced by the fixture */
    private array $facadeGlobals;

    /** Isolates the lifecycle fixture from the process's project and runtime. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->boundAppClass = Hilos::appClass();
        $this->facadeGlobals = [Hilos::$sr, Hilos::$rt, Hilos::$cluster];
        $this->bindApp(ChangeLogBootstrapTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = null;
    }

    /** Restores all facade boundaries touched by the fixture. */
    protected function tearDown(): void
    {
        $this->bindApp($this->boundAppClass);
        [Hilos::$sr, Hilos::$rt, Hilos::$cluster] = $this->facadeGlobals;
        parent::tearDown();
    }

    /** @return iterable<string, array{?Leadership}> Standalone and explicit leader contexts */
    public static function leaderContexts(): iterable
    {
        yield 'standalone' => [null];
        yield 'leader' => [new StandaloneLeadership()];
    }

    /**
     * @param ?Leadership $leadership Explicit leadership seam, or the standalone null boundary
     */
    #[DataProvider('leaderContexts')]
    public function testSingletonHostStartsTheJournalWithoutAddressingIt(?Leadership $leadership): void
    {
        if ($leadership !== null) {
            $this->installLeadership($leadership);
        }
        [$server, $manager] = $this->buildServer();
        $worker = $server->addWorker(self::WORKER_INDEX, true);

        $server->onBecameSingletonHost();

        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $agent = $manager->getAgent(HilosAgentType::HILOS_CHANGE_LOG);
        $this->assertNotNull($agent);
        $this->assertTrue($agent->requiresMonopolisticProcess());
        $this->assertSame($worker, $agent->getWorkerClient());
        $this->assertSame(-self::WORKER_INDEX, $manager->getAgentWorkerId(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertFalse($manager->isAgentStarted(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertCount(1, $manager->getAgents());
        $this->assertCount(1, $worker->frames(), 'The direct start sends only agent_start.');
        $this->assertSame([], $server->orderedWorkers);
        $this->assertInitialSignal();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $server->onBecameSingletonHost();
        $worker->report(new WorkerAgentStartedDTO(HilosAgentType::HILOS_CHANGE_LOG, HilosAgentType::HILOS_CHANGE_LOG));
        $server->onBecameSingletonHost();

        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $this->assertSame($agent, $manager->getAgent(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertTrue($manager->isAgentStarted(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $manager->requestedTypes);
    }

    /** A pending monopolistic worker is ordered once, including when project bootstrap overlaps. */
    public function testWaitingForAWorkerAndOverlappingProjectBootstrapDoNotDuplicateTheStart(): void
    {
        $daemon = new ChangeLogBootstrapTestDaemonManager();
        Hilos::$sr = new ChangeLogBootstrapTestRouter();
        [$server, $manager] = $this->buildServer($daemon->getAgentManagerDaemon());
        $daemon->registerServer($server);

        $server->onBecameSingletonHost();
        $this->assertSame([true], $server->orderedWorkers);
        $this->assertTrue($server->isAgentAwaitingWorker(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertTrue($manager->hasAgent(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertFalse($manager->getAgent(HilosAgentType::HILOS_CHANGE_LOG)->hasWorkerClient());
        $this->assertFalse($manager->isAgentStarted(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $server->agentsStillStarting());

        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($daemon);
        $server->onBecameSingletonHost();
        $this->assertSame([true], $server->orderedWorkers);
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $manager->requestedTypes);

        $worker = $server->addWorker(self::WORKER_INDEX, true);
        $server->advanceWaitingPublic();
        $this->assertFalse($server->isAgentAwaitingWorker(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $this->assertSame(-self::WORKER_INDEX, $manager->getAgentWorkerId(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertFalse($manager->isAgentStarted(HilosAgentType::HILOS_CHANGE_LOG));
        $worker->report(new WorkerAgentStartedDTO(HilosAgentType::HILOS_CHANGE_LOG, HilosAgentType::HILOS_CHANGE_LOG));
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($daemon);

        $this->assertTrue($manager->isAgentStarted(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $this->assertSame([true], $server->orderedWorkers);
        $this->assertCount(1, $manager->getAgents());
        $messages = array_values(array_filter(
            $worker->frames(),
            static fn(WorkerDTO $frame): bool => $frame instanceof DaemonAgentMessageDTO,
        ));
        $this->assertCount(2, $messages, 'Both original bootstrap signals still reach the one acknowledged instance.');
    }

    /** Merely registering the journal is insufficient to activate its lifecycle start. */
    public function testDisabledFeatureSkipsBootstrapAndLiftEvenWithARegistryEntry(): void
    {
        $this->bindApp(ChangeLogBootstrapDisabledHilos::class);
        [$server, $manager] = $this->buildServer();

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $manager->requestedTypes);
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $server->orderedWorkers);
        $this->assertInitialSignal();
    }

    /** The direct LEADER path never creates a record or requests a worker on a follower. */
    public function testFollowerSkipsBootstrapAndBothLiftPhases(): void
    {
        $this->installLeadership(new PendingLeadership());
        [$server, $manager] = $this->buildServer();

        $server->onBecameSingletonHost();
        foreach ([StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::PHASE_INACTIVE] as $phase) {
            $this->freeze($phase);
            $server->liftPublic();
        }

        $this->assertSame([], $manager->requestedTypes);
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $server->orderedWorkers);
        $this->assertInitialSignal();
    }

    /** NODE declarations retain the existing per-node lift path without a direct singleton start. */
    public function testNodeScopeIsLeftToThePerNodePath(): void
    {
        $this->bindApp(ChangeLogBootstrapNodeHilos::class);
        [$server, $manager] = $this->buildServer();
        $worker = $server->addWorker(self::WORKER_INDEX, true);

        $server->onBecameSingletonHost();
        $this->assertSame([], $manager->requestedTypes);
        $this->assertSame([], $worker->frames());
        $server->liftPublic();

        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $manager->requestedTypes);
        $this->assertSame([], $server->orderedWorkers);
    }

    /** POLICY declarations must not be started on the leader by either new lifecycle call. */
    public function testPolicyPlacementIsLeftToThePlacementPath(): void
    {
        $this->bindApp(ChangeLogBootstrapPolicyHilos::class);
        [$server, $manager] = $this->buildServer();
        $worker = $server->addWorker(self::WORKER_INDEX, true);

        $server->onBecameSingletonHost();
        $server->liftPublic();

        $this->assertSame([], $manager->requestedTypes);
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $worker->frames());
        $this->assertSame([], $server->orderedWorkers);
    }

    /** @return iterable<string, array{string, string}> Closed phases and both independent first lift opportunities */
    public static function freezeAndLiftPhases(): iterable
    {
        foreach (
            [StateProtectedModeRuntime::PHASE_ACTIVATING, StateProtectedModeRuntime::PHASE_ACTIVE,
                StateProtectedModeRuntime::PHASE_DEACTIVATING] as $closed
        ) {
            foreach ([StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::PHASE_INACTIVE] as $open) {
                yield $closed . ' to ' . $open => [$closed, $open];
            }
        }
    }

    /**
     * A refused first start is absent from the replay roster; the real lift default must request it.
     *
     * @param string $closed Freeze phase that refuses the initial start
     * @param string $open Phase that first permits the journal to start
     */
    #[DataProvider('freezeAndLiftPhases')]
    public function testLiftStartsAFirstJournalRefusedBeforeManagerAndWorkerRegistration(string $closed, string $open): void
    {
        [$server, $manager] = $this->buildServer();
        $this->freeze($closed);

        $server->onBecameSingletonHost();
        $server->stopAgentsForProtectedMode('restorer', null);
        $server->rosterStepPublic();

        $this->assertSame([], $manager->requestedTypes);
        $this->assertSame([], $manager->getAgents());
        $this->assertSame([], $server->orderedWorkers);
        $this->assertFalse($server->isAgentAwaitingWorker(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        $this->assertFalse($server->awaitsProtectedModeResume(HilosAgentType::HILOS_CHANGE_LOG, null));

        $worker = $server->addWorker(self::WORKER_INDEX, true);
        $this->freeze($open);
        $server->resumeAgentsForProtectedMode();
        $server->rosterStepPublic();
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $journal = $manager->getAgent(HilosAgentType::HILOS_CHANGE_LOG);
        $this->assertNotNull($journal);
        $this->assertSame($worker, $journal->getWorkerClient());

        $server->liftPublic();
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $server->resumeAgentsForProtectedMode();
        $server->rosterStepPublic();

        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $worker->startIds());
        $this->assertSame($journal, $manager->getAgent(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG], $manager->requestedTypes);
        $this->assertSame(-self::WORKER_INDEX, $manager->getAgentWorkerId(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertSame([], $server->orderedWorkers);
    }

    /** A failed journal factory leaves the queued project bootstrap and the independent Status start intact. */
    public function testJournalFailureIsContainedWithoutStrandingStatusOrProjectBootstrap(): void
    {
        $this->bindApp(ChangeLogBootstrapNeighborsHilos::class);
        [$server, $manager] = $this->buildServer();
        $worker = $server->addWorker(self::WORKER_INDEX, false);
        $sink = new ChangeLogBootstrapTestFailureSink();
        $server->setContainedFailureSink($sink);
        $manager->refuseJournal = true;

        $server->onBecameSingletonHost();

        $this->assertSame([HilosAgentType::HILOS_CHANGE_LOG, ChangeLogBootstrapNeighborsHilos::STATUS], $manager->requestedTypes);
        $this->assertSame([ChangeLogBootstrapNeighborsHilos::STATUS], $worker->startIds());
        $this->assertJournalFailure($manager, $sink);
        $this->assertInitialSignal();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** The lift still starts NODE and Status neighbors when the journal factory fails. */
    public function testJournalFailureOnLiftPreservesPerNodeAndStatusStarts(): void
    {
        $this->bindApp(ChangeLogBootstrapNeighborsHilos::class);
        [$server, $manager] = $this->buildServer();
        $worker = $server->addWorker(self::WORKER_INDEX, false);
        $sink = new ChangeLogBootstrapTestFailureSink();
        $server->setContainedFailureSink($sink);
        $manager->refuseJournal = true;

        $server->liftPublic();

        $this->assertSame(
            [ChangeLogBootstrapNeighborsHilos::NODE, HilosAgentType::HILOS_CHANGE_LOG, ChangeLogBootstrapNeighborsHilos::STATUS],
            $manager->requestedTypes,
        );
        $this->assertSame([ChangeLogBootstrapNeighborsHilos::NODE, ChangeLogBootstrapNeighborsHilos::STATUS], $worker->startIds());
        $this->assertJournalFailure($manager, $sink);
    }

    /**
     * @param ?ChangeLogBootstrapTestAgentManager $manager Optional daemon-owned roster
     * @return array{ChangeLogBootstrapTestWorkerServer, ChangeLogBootstrapTestAgentManager} Server and real manager
     */
    private function buildServer(?ChangeLogBootstrapTestAgentManager $manager = null): array
    {
        $manager ??= new ChangeLogBootstrapTestAgentManager();
        // Worker env and log-directory initialization are outside this in-memory lifecycle regression.
        $server = new ReflectionClass(ChangeLogBootstrapTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return [$server, $manager];
    }

    /** The original DAEMON/SYSTEM bootstrap envelope and its empty project addressee list are preserved. */
    private function assertInitialSignal(): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::SYSTEM, $signal->signalType->getType());
        $this->assertSame(SignalConstants::INITIAL_AGENTS_START, $signal->signalName->getName());
        $this->assertInstanceOf(SystemSignalDTO::class, $signal->data);
        $this->assertSame(SignalConstants::INITIAL_AGENTS_START, $signal->data->systemName);
        $this->assertSame([], Hilos::$sr->getDestinations($signal));
    }

    /**
     * @param ChangeLogBootstrapTestAgentManager $manager Roster after the refused factory call
     * @param ChangeLogBootstrapTestFailureSink $sink Contained start failure cards
     */
    private function assertJournalFailure(ChangeLogBootstrapTestAgentManager $manager, ChangeLogBootstrapTestFailureSink $sink): void
    {
        $this->assertFalse($manager->hasAgent(HilosAgentType::HILOS_CHANGE_LOG));
        $this->assertCount(1, $sink->reported);
        $this->assertSame(MasterFailureUnit::AGENT_START, $sink->reported[0]->unit);
        $this->assertSame(HilosAgentType::HILOS_CHANGE_LOG, $sink->reported[0]->address);
        $this->assertInstanceOf(AgentDaemonCreationFailedException::class, $sink->reported[0]->failure);
    }

    /** @param class-string<Hilos> $appClass Fixture project facade */
    private function bindApp(string $appClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $appClass);
    }

    /** @param Leadership $leadership Local leadership verdict */
    private function installLeadership(Leadership $leadership): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($leadership);
    }

    /** @param string $phase Phase received through the runtime snapshot boundary */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new ChangeLogBootstrapTestRtContext();
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

/** Exposes lifecycle steps while keeping production agent start, selection and worker waits. */
final class ChangeLogBootstrapTestWorkerServer extends WorkerServer
{
    /** @var list<bool> Monopolistic flag of each requested OS worker launch */
    public array $orderedWorkers = [];

    /**
     * @param int $index Worker connection identity
     * @param bool $monopolistic Whether this connection belongs to the dedicated pool
     * @return ChangeLogBootstrapTestWorkerClient Link retaining the real serialized write buffer
     */
    public function addWorker(int $index, bool $monopolistic): ChangeLogBootstrapTestWorkerClient
    {
        $client = new ReflectionClass(ChangeLogBootstrapTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($index);
        $client->setIsMonopolistic($monopolistic);
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client,
            new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this),
        );
        $this->clients[] = $client;

        return $client;
    }

    /** Advances the production wait until a registered worker can take the journal. */
    public function advanceWaitingPublic(): void
    {
        $this->advanceAgentsAwaitingWorker();
    }

    /** Runs the framework lift default. */
    public function liftPublic(): void
    {
        $this->onProtectedModeLifted();
    }

    /** Advances the production stopped-roster replay by one pass. */
    public function rosterStepPublic(): void
    {
        $this->advanceProtectedModeRoster();
    }

    /** @param bool $isMonopolistic Dedicated pool flag of the OS process request */
    protected function startWorker(bool $isMonopolistic): void
    {
        $this->orderedWorkers[] = $isMonopolistic;
    }

    /** No socket or OS worker is launched by this in-memory fixture. */
    protected function onStart(): void
    {
    }
}

/** Observes real start frames and handles acknowledgements without replacing sendAgentStart(). */
final class ChangeLogBootstrapTestWorkerClient extends WorkerClient
{
    /** @return list<WorkerDTO> Decoded frames in the production write buffer */
    public function frames(): array
    {
        if ($this->writeBuffer === '') {
            return [];
        }

        return array_map(WorkerDTO::factoryWorkerDTO(...), explode("\n", rtrim($this->writeBuffer, "\n")));
    }

    /** @return list<string> Identities carried by actual agent_start frames */
    public function startIds(): array
    {
        return array_values(array_map(
            static fn(AgentStartDTO $frame): string => $frame->agentId,
            array_filter($this->frames(), static fn(WorkerDTO $frame): bool => $frame instanceof AgentStartDTO),
        ));
    }

    /** @param WorkerDTO $report Acknowledgement passed through the production decoder */
    public function report(WorkerDTO $report): void
    {
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($this, $report->toJson());
    }
}

/** Keeps production roster registration and permits a journal factory failure. */
final class ChangeLogBootstrapTestAgentManager extends AgentManagerDaemon
{
    /** @var list<string> Factory requests past the start gates */
    public array $requestedTypes = [];

    /** Whether the journal factory should refuse this attempt. */
    public bool $refuseJournal = false;

    /**
     * @param string $agentType Requested agent type
     * @param ?string $agentIndex Requested instance index
     * @return AgentDaemonInterface Monopolistic journal proxy or regular neighbor
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $this->requestedTypes[] = $agentType;
        if ($this->refuseJournal && $agentType === HilosAgentType::HILOS_CHANGE_LOG) {
            throw new AgentDaemonCreationFailedException($agentType, $agentIndex);
        }

        return new ChangeLogBootstrapTestAgentDaemon($agentType);
    }
}

/** Daemon proxy carrying the journal's actual dedicated-worker requirement. */
final class ChangeLogBootstrapTestAgentDaemon extends AbstractAgentDaemon
{
    /** @param string $type Registry type of this proxy */
    public function __construct(private readonly string $type)
    {
    }

    /** @return string Registry type of this proxy */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return bool True for the dedicated journal, false for its regular neighbors */
    public function requiresMonopolisticProcess(): bool
    {
        return $this->type === HilosAgentType::HILOS_CHANGE_LOG;
    }
}

/** Uses real dispatch and held-frame delivery for an overlapping project bootstrap. */
final class ChangeLogBootstrapTestDaemonManager extends DaemonManager
{
    /** @return SignalRouter Empty project bootstrap router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return ChangeLogBootstrapTestAgentManager Real daemon roster with the fixture factory */
    protected function createAgentManagerDaemon(): ChangeLogBootstrapTestAgentManager
    {
        return new ChangeLogBootstrapTestAgentManager();
    }
}

/** A project whose existing bootstrap list also addresses the journal. */
final class ChangeLogBootstrapTestRouter extends SignalRouter
{
    /** @return list<string> Original project bootstrap addressee */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [HilosAgentType::HILOS_CHANGE_LOG];
    }
}

/** Records the production failure cards. */
final class ChangeLogBootstrapTestFailureSink implements ContainedFailureSink
{
    /** @var list<ContainedFailure> Failures in report order */
    public array $reported = [];

    /** @param ContainedFailure $failure Contained start failure */
    public function reportContainedFailure(ContainedFailure $failure): void
    {
        $this->reported[] = $failure;
    }
}

/** Supplies the unconditional freeze row without project collections. */
final class ChangeLogBootstrapTestRtContext extends RtContext
{
    /** No project state is required by the start gate. */
    public function configure(): void
    {
    }
}

/** Journal-enabled project with explicit placement axes and an empty project bootstrap list. */
abstract class ChangeLogBootstrapTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_CHANGE_LOG => [
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::LEADER,
        ],
        'unrelated' => [],
    ];

    protected const array FEATURES = [HilosFeature::CHANGE_LOG];
}

/** The same journal registration must remain inert when the feature is disabled. */
abstract class ChangeLogBootstrapDisabledHilos extends ChangeLogBootstrapTestHilos
{
    protected const array FEATURES = [];
}

/** NODE is served by the existing per-node lifecycle. */
abstract class ChangeLogBootstrapNodeHilos extends ChangeLogBootstrapTestHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_CHANGE_LOG => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

/** POLICY is served by the existing placement lifecycle. */
abstract class ChangeLogBootstrapPolicyHilos extends ChangeLogBootstrapTestHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_CHANGE_LOG => [
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];
}

/** Independent Status and NODE starts surrounding the journal's lifecycle request. */
abstract class ChangeLogBootstrapNeighborsHilos extends ChangeLogBootstrapTestHilos
{
    public const string STATUS = 'journal_neighbor_status';
    public const string NODE = 'journal_neighbor_node';

    public const array AGENTS = [
        ...parent::AGENTS,
        self::STATUS => [],
        self::NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];

    protected const array FEATURES = [HilosFeature::CHANGE_LOG, HilosFeature::STATUS];

    protected const array STATUS_COMPONENTS = [
        'app' => ['name' => 'Application', 'description' => null, 'agentType' => self::STATUS],
    ];
}

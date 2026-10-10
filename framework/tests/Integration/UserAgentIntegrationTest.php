<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
use Hilos\Core\Agent\TopologyAgentFactory;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\Agent\AbstractUserAgentDaemon;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/**
 * The addressed frame and worker lifecycle agree on one person's claims across an idle stop.
 *
 * The socket between master and worker is bridged in-process: its transport has separate tests.
 * Everything on either side of that socket is production code, including factory, start, claims,
 * idle verdict and stop cleanup. No production signal is added for this test.
 */
final class UserAgentIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string USER_ID = '42';

    private const string AGENT_ID = HilosAgentType::HILOS_USER . ':' . self::USER_ID;

    /** @var class-string<Hilos> App class bound before this test */
    private string $previousAppClass;

    private ?SignalRouter $previousRouter;

    private ?RtContext $previousRt;

    /** Binds the test topology after the integration database is ready. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UserAgentIntegrationApp::class);
        Hilos::$rt = null;
    }

    /** Restores the app binding and clears the test agent's grants. */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::AGENT_ID);
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);

        parent::tearDown();
    }

    /** The second address creates a fresh instance with the same scoped claims. */
    public function testFrameRaisesPersonClaimsIdleReleasesThemAndNextFrameRaisesThemAgain(): void
    {
        $worker = new UserAgentIntegrationWorkerManager();
        $master = new UserAgentIntegrationDaemonManager();
        $server = new ReflectionClass(UserAgentIntegrationWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $master);
        $server->worker = $worker;
        $server->daemonManager = $master;

        $this->sendFrame($server);
        $first = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertInstanceOf(UserAgentIntegrationWorker::class, $first);
        self::assertSame([self::AGENT_ID], $server->startedAgentIds);
        $this->assertPersonClaimsAndBoundary();

        $worker->idleAndTick(self::AGENT_ID);
        self::assertFalse($worker->agentManagerDouble->hasAgent(self::AGENT_ID));
        self::assertSame([self::AGENT_ID], $worker->daemonClientDouble->stoppedAgentIds());
        $this->assertClaimReleased();
        $master->removeAgent(self::AGENT_ID); // The worker's agent_stopped report clears the master record.

        $this->sendFrame($server);
        $second = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertInstanceOf(UserAgentIntegrationWorker::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame([self::AGENT_ID, self::AGENT_ID], $server->startedAgentIds);
        $this->assertPersonClaimsAndBoundary();
    }

    /**
     * @param UserAgentIntegrationWorkerServer $server Addressed-frame door under test
     */
    private function sendFrame(UserAgentIntegrationWorkerServer $server): void
    {
        try {
            $server->sendSignalToAgent(
                HilosAgentType::HILOS_USER,
                self::USER_ID,
                new DaemonAgentMessageDTO(self::AGENT_ID, new SignalDTO(
                    new SignalSource(SignalSource::DAEMON),
                    new SignalType('noop'),
                    new SignalName('noop'),
                    new SignalData([]),
                )),
            );
        } catch (WorkerClientNotFoundException) {
            // The frame has already entered the start path. This test has no socket on which to
            // deliver its no-op body after the worker reports ready.
        }
    }

    /** Reads the grants laid by the worker's actual start path. */
    private function assertPersonClaimsAndBoundary(): void
    {
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::users,
            self::USER_ID,
            static fn (): array => [],
            TruthSourceOperation::Update,
        );
        $this->assertWriteDenied(HilosDbContext::users, '43', []);
        // The rename journal, the second-factor settings row and the step-up confirmations are the
        // sets the agent adds to - the journal with the name, the settings row on its first edit
        // (HIL-1406), a confirmation of the person's own proof (HIL-1407) - and only to its own
        // person's set.
        foreach (self::addedSets() as $collection) {
            TruthSourceRegistry::checkCanCreate($collection, static fn (): array => [self::USER_ID]);
            $this->assertCreateDenied($collection, ['43']);
        }
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::secondFactorSettings,
            self::USER_ID,
            static fn (): array => [self::USER_ID],
            TruthSourceOperation::Update,
        );
        $this->assertWriteDenied(HilosDbContext::secondFactorSettings, '43', ['43']);
        foreach (self::borrowedSets() as $collection) {
            TruthSourceRegistry::checkCanWriteItem(
                $collection,
                '1',
                static fn (): array => [self::USER_ID],
                TruthSourceOperation::Update,
            );
            $this->assertWriteDenied($collection, '2', ['43']);
            try {
                TruthSourceRegistry::checkCanCreate($collection, static fn (): array => [self::USER_ID]);
                self::fail("{$collection} allowed a child-row creation");
            } catch (CreateNotAllowedException) {
                // The library keeps the right to create rows of the person's set.
            }
        }
    }

    /** The stopped agent can no longer write even though the integration harness still can. */
    private function assertClaimReleased(): void
    {
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        $this->assertWriteDenied(HilosDbContext::users, self::USER_ID, []);
        foreach (self::addedSets() as $collection) {
            $this->assertCreateDenied($collection, [self::USER_ID]);
        }
        $this->assertWriteDenied(HilosDbContext::secondFactorSettings, self::USER_ID, [self::USER_ID]);
        foreach (self::borrowedSets() as $collection) {
            $this->assertWriteDenied($collection, '1', [self::USER_ID]);
        }
    }

    /**
     * @return list<string> Child sets the agent borrows to edit and remove, never to add
     */
    private static function borrowedSets(): array
    {
        return array_values(array_diff(array_keys(UserAgentIntegrationWorker::OWNS_DB_SET), self::addedSets()));
    }

    /**
     * @return list<string> Child sets the agent adds rows to, its own person's alone
     */
    private static function addedSets(): array
    {
        return [HilosDbContext::userRenames, HilosDbContext::secondFactorSettings, HilosDbContext::stepUps];
    }

    /**
     * @param string $collection Collection being checked
     * @param list<string> $setKeys Root set keys of the row that would be created
     */
    private function assertCreateDenied(string $collection, array $setKeys): void
    {
        try {
            TruthSourceRegistry::checkCanCreate($collection, static fn (): array => $setKeys);
            self::fail("{$collection} allowed a creation in set [" . implode(', ', $setKeys) . '] outside this agent\'s claim');
        } catch (CreateNotAllowedException) {
            // Refused, which is what the claim promises.
        }
    }

    /**
     * @param string $collection Collection being checked
     * @param string $rowId Row key being checked
     * @param list<string> $setKeys Row's root set keys
     */
    private function assertWriteDenied(string $collection, string $rowId, array $setKeys): void
    {
        try {
            TruthSourceRegistry::checkCanWriteItem(
                $collection,
                $rowId,
                static fn (): array => $setKeys,
                TruthSourceOperation::Update,
            );
            self::fail("{$collection} allowed a write to row {$rowId} outside this agent's claim");
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString(self::AGENT_ID, $refusal->getMessage());
        }
    }
}

/** The registry of the small integration app, with the same lifecycle entry the six demos use. */
abstract class UserAgentIntegrationApp extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [
            AgentRegistryKey::WORKER => UserAgentIntegrationWorker::class,
            AgentRegistryKey::DAEMON => UserAgentIntegrationDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
        ],
    ];
}

final class UserAgentIntegrationWorker extends AbstractUserAgent
{
}

final class UserAgentIntegrationDaemon extends AbstractUserAgentDaemon
{
}

/** Worker-side harness: it uses the topology factory and the real idle tick. */
final class UserAgentIntegrationWorkerManager extends WorkerManager
{
    public UserAgentIntegrationAgentManager $agentManagerDouble;

    public UserAgentIntegrationDaemonClient $daemonClientDouble;

    /** Builds a worker without a transport socket while retaining its normal lifecycle. */
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClientDouble = new UserAgentIntegrationDaemonClient();
        $this->daemonClient = $this->daemonClientDouble;
    }

    /**
     * @param string $agentId Agent whose silence is backdated past the registry window
     * @throws LogicException When the worker has no idle tracker
     */
    public function idleAndTick(string $agentId): void
    {
        $tracker = new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->getValue($this);
        if (!$tracker instanceof AgentIdleTracker) {
            throw new LogicException('Worker has no idle tracker');
        }
        $tracker->noteAddressed($agentId, microtime(true) - (AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC + 1));
        $tick = Closure::bind(static function (WorkerManager $manager): void {
            $manager->tickAgents();
        }, null, WorkerManager::class);
        $tick($this);
    }

    /**
     * @return SignalRouter The ordinary framework router for the test worker
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Agent manager that uses the test app's topology factory
     */
    protected function createAgentManager(): AgentManager
    {
        $this->agentManagerDouble = new UserAgentIntegrationAgentManager();

        return $this->agentManagerDouble;
    }
}

final class UserAgentIntegrationAgentManager extends AgentManager
{
    /**
     * @param string $agentType Type requested by the addressed frame
     * @param ?string $agentIndex Addressed person id
     * @return AgentInterface Person agent from the test app registry
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return TopologyAgentFactory::createWorker(UserAgentIntegrationApp::class, $agentType, $agentIndex);
    }
}

/** A connected, in-process link that accepts the worker's lifecycle reports. */
final class UserAgentIntegrationDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Worker lifecycle reports */
    public array $sent = [];

    /** The test link has no operating-system socket. */
    public function __construct()
    {
    }

    /**
     * @return bool True while this in-process link receives lifecycle reports
     */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Worker lifecycle report
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    /**
     * @return list<string> Agent ids reported stopped to the master
     */
    public function stoppedAgentIds(): array
    {
        $stopped = [];
        foreach ($this->sent as $message) {
            if (is_array($message) && ($message[WorkerDTO::TYPE] ?? null) === WorkerConstants::MESSAGE_AGENT_STOPPED) {
                $stopped[] = (string)$message[AgentConstants::FIELD_AGENT_ID];
            }
        }

        return $stopped;
    }
}

/** Master-side addressed-frame door bridged to the worker manager for this scenario. */
final class UserAgentIntegrationWorkerServer extends WorkerServer
{
    /** @var list<string> Agent ids started by addressed frames */
    public array $startedAgentIds = [];

    public UserAgentIntegrationWorkerManager $worker;

    public UserAgentIntegrationDaemonManager $daemonManager;

    /**
     * @param string $agentType Addressed agent type
     * @param ?string $agentIndex Addressed person id
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $this->startedAgentIds[] = $agentId;
        $this->worker->handleDaemonMessage(new AgentStartDTO($agentId));
        $this->daemonManager->addAgent(
            $agentId,
            $this->daemonManager->instantiateAgentDaemon($agentType, $agentIndex),
            0,
            false,
        );
    }

    /** The server loop is not started; an addressed frame calls the delivery door directly. */
    protected function onStart(): void
    {
    }
}

final class UserAgentIntegrationDaemonManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Type requested by the addressed frame
     * @param ?string $agentIndex Addressed person id
     * @return AgentDaemonInterface Person proxy from the test app registry
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return TopologyAgentFactory::createDaemon(UserAgentIntegrationApp::class, $agentType, $agentIndex);
    }
}

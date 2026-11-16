<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
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
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\Agent\AbstractUserAgentDaemon;
use Hilos\Utils\Helpers\TimeHelper;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/**
 * The addressed frame and worker lifecycle agree on one person's claims across an idle stop.
 *
 * The socket between master and worker is bridged in-process: its transport has separate tests.
 * Everything on either side of that socket is production code, including factory, start, claims,
 * idle verdict and stop cleanup. No production signal is added for this test. The start reads the
 * person's browser trusts (HIL-1901), so the case stands on the framework's account tables. In a
 * project with a sign-in surface the start also runs the erasure duty the agent is owed (HIL-1951).
 */
final class UserAgentIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string USER_ID = '42';

    private const string AGENT_ID = HilosAgentType::HILOS_USER . ':' . self::USER_ID;

    private const array ACCOUNT_OPERATION_TABLES = [
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

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
     * The worker that raises the person's agent runs the duty of the person's erasure the agent
     * is owed right after its start hook, one that threw included, and the answer is queued for
     * the session holder before anything else reaches the agent (HIL-1951).
     */
    public function testTheStartingWorkerResumesTheReadyErasureDutyEvenAfterAThrowingStartHook(): void
    {
        self::runAccountOperationStubs(down: true);
        self::runAccountOperationStubs(down: false);
        Schema::reset();
        Schema::initialize();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UserAgentIntegrationAuthApp::class);
        try {
            $start = $this->openErasure((int)self::USER_ID);
            $worker = new UserAgentIntegrationWorkerManager();
            $master = new UserAgentIntegrationDaemonManager();
            $server = new ReflectionClass(UserAgentIntegrationWorkerServer::class)->newInstanceWithoutConstructor();
            new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $master);
            $server->worker = $worker;
            $server->daemonManager = $master;

            $this->sendFrame($server);

            $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
            self::assertInstanceOf(UserAgentIntegrationFailingStartWorker::class, $agent);
            self::assertTrue($agent->startHookThrew);
            $answers = [];
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                    $answers[] = $signal->data->data;
                }
            }
            self::assertCount(1, $answers);
            self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answers[0]);
            self::assertSame($start->id, $answers[0]->assignmentId);
            self::assertSame(AccountOperationAnswerKind::Done, $answers[0]->answer);
        } finally {
            TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_USERS_LIBRARY);
            TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_SESSIONS_LIBRARY);
            self::runAccountOperationStubs(down: true);
            Schema::reset();
        }
    }

    /**
     * Opens the person's erasure past its census and records the root's start, the way the session
     * holder does on entering it: the request in the users library's frame, the rest in the holder's.
     *
     * @param int $userId Person whose standing request the erasure answers
     * @return AccountOperationAssignment The root's start, ready and without a result
     */
    private function openErasure(int $userId): AccountOperationAssignment
    {
        Database::sqlRun("INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (?, 'Person')", [$userId]);
        OwnershipDeclaration::claimDb(UserAgentIntegrationUsersLibrary::class, HilosAgentType::HILOS_USERS_LIBRARY);
        OwnershipDeclaration::claimDb(UserAgentIntegrationSessionsHolder::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $request = ExecutionContext::run(
            new ExecutionFrame(agentId: HilosAgentType::HILOS_USERS_LIBRARY),
            static fn () => Hilos::$db->accountDeletions->actions->request(
                $userId,
                TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            ),
        );
        $start = ExecutionContext::run(
            new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static fn () => AccountErasureStart::recordMissing(
                Hilos::$db->accountOperations->actions->recordErasure($request->id, $userId, Hilos::$db->userMerges->erasureCircleOf($userId)),
            ),
        );
        self::assertNotNull($start);

        return $start;
    }

    /**
     * Raises or drops the account operation tables beyond the session base, which the starting
     * agent's resume reads (HIL-1951).
     *
     * @param bool $down Drop tables when true, create them when false
     */
    private static function runAccountOperationStubs(bool $down): void
    {
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::ACCOUNT_OPERATION_TABLES) : self::ACCOUNT_OPERATION_TABLES as $table) {
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"));
        }
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
        // Ordinary identities, passkeys, enrollments, backup codes and reset requests are added here too (HIL-1989).
        // The rename journal, the second-factor settings row, the step-up confirmations, the
        // muted-channel rows and the photo row are the sets the agent adds to - the journal with
        // the name, the settings row on its first edit (HIL-1406), a confirmation of the person's
        // own proof (HIL-1407), a channel the person turned off (HIL-1408), the photo row on the
        // first photo (HIL-1409), the result of its own step in an account operation, keyed by the
        // agent's full id (HIL-1939) - and only to its own person's set.
        foreach (self::addedSets() as $collection) {
            TruthSourceRegistry::checkCanCreate($collection, static fn (): array => [self::setKeyOf($collection, self::USER_ID)]);
            $this->assertCreateDenied($collection, [self::setKeyOf($collection, '43')]);
        }
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::secondFactorSettings,
            self::USER_ID,
            static fn (): array => [self::USER_ID],
            TruthSourceOperation::Update,
        );
        $this->assertWriteDenied(HilosDbContext::secondFactorSettings, '43', ['43']);
        foreach ([HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes, HilosDbContext::secondFactorResets, HilosDbContext::secondFactorTrusts] as $collection) {
            foreach ([TruthSourceOperation::Update, TruthSourceOperation::Remove] as $operation) {
                TruthSourceRegistry::checkCanWriteItem($collection, '1', static fn (): array => [self::USER_ID], $operation);
                $this->assertWriteDenied($collection, '2', ['43'], $operation);
            }
        }
        foreach (self::borrowedSets() as $collection) {
            $operation = in_array($collection, [HilosDbContext::legalAcceptances, HilosDbContext::userMerges], true)
                ? TruthSourceOperation::Remove
                : TruthSourceOperation::Update;
            TruthSourceRegistry::checkCanWriteItem(
                $collection,
                '1',
                static fn (): array => [self::USER_ID],
                $operation,
            );
            $this->assertWriteDenied($collection, '2', ['43'], $operation);
            if (in_array($collection, [HilosDbContext::legalAcceptances, HilosDbContext::userMerges], true)) {
                $this->assertWriteDenied(
                    $collection,
                    '1',
                    [self::USER_ID],
                    TruthSourceOperation::Update,
                );
            }
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
            $this->assertCreateDenied($collection, [self::setKeyOf($collection, self::USER_ID)]);
        }
        $this->assertWriteDenied(HilosDbContext::secondFactorSettings, self::USER_ID, [self::USER_ID]);
        foreach ([HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes, HilosDbContext::secondFactorResets, HilosDbContext::secondFactorTrusts] as $collection) {
            foreach ([TruthSourceOperation::Update, TruthSourceOperation::Remove] as $operation) {
                $this->assertWriteDenied($collection, '1', [self::USER_ID], $operation);
            }
        }
        foreach (self::borrowedSets() as $collection) {
            $operation = in_array($collection, [HilosDbContext::legalAcceptances, HilosDbContext::userMerges], true)
                ? TruthSourceOperation::Remove
                : TruthSourceOperation::Update;
            $this->assertWriteDenied($collection, '1', [self::USER_ID], $operation);
        }
    }

    /**
     * @return list<string> Child sets borrowed for updates or removal, including remove-only sets
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
        return [
            HilosDbContext::identities,
            HilosDbContext::passkeyCredentials,
            HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes,
            HilosDbContext::secondFactorResets,
            HilosDbContext::secondFactorTrusts,
            HilosDbContext::userRenames,
            HilosDbContext::secondFactorSettings,
            HilosDbContext::stepUps,
            HilosDbContext::notificationPreferences,
            HilosDbContext::userPhotos,
            HilosDbContext::accountOperationResults,
        ];
    }

    /**
     * @param string $collection Added set being checked
     * @param string $userId Person whose set is meant
     * @return string The person's agent id for operation results, the person's id for every other set
     */
    private static function setKeyOf(string $collection, string $userId): string
    {
        return $collection === HilosDbContext::accountOperationResults ? HilosAgentType::HILOS_USER . ':' . $userId : $userId;
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
     * @param TruthSourceOperation $operation Operation expected to be refused
     */
    private function assertWriteDenied(
        string $collection,
        string $rowId,
        array $setKeys,
        TruthSourceOperation $operation = TruthSourceOperation::Update,
    ): void
    {
        try {
            TruthSourceRegistry::checkCanWriteItem(
                $collection,
                $rowId,
                static fn (): array => $setKeys,
                $operation,
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

/** The same app with a sign-in surface, whose person's agent has a start hook that throws (HIL-1951). */
abstract class UserAgentIntegrationAuthApp extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [
            AgentRegistryKey::WORKER => UserAgentIntegrationFailingStartWorker::class,
            AgentRegistryKey::DAEMON => UserAgentIntegrationDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
        ],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => UserAgentIntegrationUsersLibrary::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => UserAgentIntegrationSessionsHolder::class],
    ];
}

/** The person's agent whose start hook runs the framework's and then throws, as a defective project hook does. */
final class UserAgentIntegrationFailingStartWorker extends AbstractUserAgent
{
    public bool $startHookThrew = false;

    /** Applies what the framework's start applies, then fails. */
    public function onStart(): void
    {
        parent::onStart();
        $this->startHookThrew = true;
        throw new LogicException('The fixture start hook fails');
    }
}

/** The users library that records the person's deletion request. */
final class UserAgentIntegrationUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** The session holder that records the erasure and receives the answer. */
final class UserAgentIntegrationSessionsHolder extends AbstractSessionsLibraryAgent
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
     * @return AgentInterface Person agent from the registry of the test app the case bound
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return TopologyAgentFactory::createWorker(Hilos::appClass(), $agentType, $agentIndex);
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

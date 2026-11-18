<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Agent\Daemon\AgentStartSink;
use Closure;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeCleanupResult;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeFinishing;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Legal\LegalDocument;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Users\DTO\UserLegalHoldForgetSignalData;
use Hilos\Users\DTO\UserPushEndpointGoneSignalData;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;

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
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Core\Exception\InvalidFormatException;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
        'hilos_account_merge_copy',
        'hilos_passkey_credential',
        'hilos_push_subscription',
    ];

    /** @var class-string<Hilos> App class bound before this test */
    private string $previousAppClass;

    private ?SignalRouter $previousRouter;

    private ?RtContext $previousRt;

    private ?AbstractUserAgent $survivor = null;

    /** Binds the test topology after the integration database is ready. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UserAgentIntegrationApp::class);
        Hilos::$rt = null;
        self::runAccountOperationStubs(down: true);
        self::runAccountOperationStubs(down: false);
    }

    /** Restores the app binding and clears the test agent's grants. */
    protected function tearDown(): void
    {
        self::runAccountOperationStubs(down: true);
        foreach ([self::AGENT_ID, HilosAgentType::HILOS_USER . ':7', HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosAgentType::HILOS_USERS_LIBRARY] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
        }
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

    /** A committed source stays alive across its merge fact, saved give and a cleanup that still waits. */
    public function testPreparedSourceKeepsItsClaimsUntilReceiptAndCleanup(): void
    {
        [$worker, $server, $agent, $operation, $give, $cleanup] = $this->preparedSource();
        self::assertTrue($agent->hasWorkInFlight());
        $agent->onSignalDbSyncCreated(new DbSyncCreatedSignalData(HilosDbContext::userMerges, self::USER_ID, []),
            'db', SignalConstants::DB_SYNC_CREATED);
        self::assertFalse($agent->shouldStop());
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $give)->answer);
        self::assertSame(AccountOperationAnswerKind::Waits, $this->operationFrame($worker, $cleanup)->answer);
        self::assertNull(AccountMergeCleanupResult::forOperation($operation));
        self::assertTrue(Hilos::$db->accountMergeCopies->hasStored($give->id));
        $worker->idleAndTick(self::AGENT_ID);
        self::assertSame($agent, $worker->agentManagerDouble->getAgent(self::AGENT_ID));
        $this->assertPersonClaimsAndBoundary();
    }

    /** Late ordinary and folded-compatible service frames retain correlation and change no owned rows. */
    public function testPreparedSourceRefusesLateServiceAndOrdinaryWritesWithoutStopping(): void
    {
        [$worker, , $agent] = $this->preparedSource();
        Database::sql('INSERT INTO `hilos_push_subscription` (`user_id`, `endpoint`, `p256dh`, `auth`, `endpoint_hash`, `created_at`, `revision`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)', [42, 'https://push.example.test/source', 'public', 'private', str_repeat('a', 64), 0, 1]);
        $pushId = Database::lastInsertId();
        $before = $this->ownedSnapshot();
        $asks = [
            [HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET,
                new UserLegalHoldForgetSignalData(42, LegalDocument::TERMS->value, 'held', 'source_service_done', 'command-1')],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
                new UserSecondFactorTrustRestrictSignalData(42, 1, 'source_service_done')],
            [HilosSignalConstants::HILOS_USER_PUSH_RELEASE,
                new UserPushReleaseSignalData(42, $pushId, 'https://push.example.test/source', 7, 'public', 'private', null,
                    'source_service_done', 'accept', 'release-1', PushSubscriptionAction::SUBSCRIBE, null)],
            [HilosSignalConstants::HILOS_USER_RENAME,
                new UserRenameSignalData(42, 'Late name', 42, 'source_service_done', 'accept', 'rename-1', 'rename', null, null)],
        ];
        foreach ($asks as [$name, $ask]) {
            $this->drainSignals();
            $this->deliverFrame($worker, $name, $ask);
            $answers = array_values(array_filter($this->drainSignals(), static fn (SignalDTO $signal): bool =>
                $signal->signalName->getName() === 'source_service_done'));
            self::assertCount(1, $answers);
            self::assertEquals($ask, $ask instanceof UserRenameSignalData
                ? $answers[0]->data->data->ask : $answers[0]->data->data->request);
            if ($ask instanceof UserRenameSignalData) {
                self::assertNotNull($answers[0]->data->data->error);
            } else {
                self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $answers[0]->data->data->error);
            }
            self::assertSame($before, $this->ownedSnapshot());
            self::assertFalse($agent->shouldStop());
        }
        $this->deliverFrame($worker, HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE,
            new UserPushEndpointGoneSignalData(42, $pushId, 1));
        self::assertSame($before, $this->ownedSnapshot());
        self::assertTrue($agent->hasWorkInFlight());
        self::assertFalse($agent->shouldStop());
    }

    /** A cold facade and the same worker's replacement restore the idle pin without consulting DB on idle checks. */
    public function testColdAndSameWorkerSourceRestartRestoreFinishingBeforeTrustMaterialization(): void
    {
        [$worker, $server, $first] = $this->preparedSource();
        $worker->handleDaemonMessage(new AgentStopDTO(self::AGENT_ID));
        $this->assertClaimReleased();
        $server->daemonManager->removeAgent(self::AGENT_ID);
        Database::close();
        Database::connect();
        $context = new UserAgentIntegrationSourceContext();
        $context->configure();
        Hilos::$db = $context;
        $this->sendFrame($server);
        $second = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertNotSame($first, $second);
        self::assertTrue($second->hasWorkInFlight());
        self::assertFalse($second->shouldStop());
        self::assertSame(0, $context->trustReads);
        $sourceReads = $context->sourceReads;
        $context->refuseSourceLookup = true;
        $worker->idleAndTick(self::AGENT_ID);
        self::assertSame($sourceReads, $context->sourceReads);
        self::assertSame($second, $worker->agentManagerDouble->getAgent(self::AGENT_ID));
        self::assertTrue($second->hasWorkInFlight());
        $context->refuseSourceLookup = false;
        $this->assertPersonClaimsAndBoundary();
    }

    /** Cleanup failure leaves the worker running; commit and exact replay queue answers before stop releases claims. */
    public function testCleanupSignalStopsOnlyAfterCommitAndSavedReplayQueuesItsAnswer(): void
    {
        [$worker, $server, $agent, $operation, $give, $cleanup, $receive] = $this->preparedSource();
        $this->receiveSource($give, $receive);
        Database::sqlRun("CREATE TRIGGER source_cleanup_fault BEFORE INSERT ON `hilos_account_operation_result` FOR EACH ROW"
            . " BEGIN IF NEW.assignment_id = {$cleanup->id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cleanup fault'; END IF; END");
        try {
            self::assertSame(AccountOperationAnswerKind::Failed, $this->operationFrame($worker, $cleanup)->answer);
            self::assertFalse($agent->shouldStop());
            self::assertTrue($agent->hasWorkInFlight());
            self::assertTrue(Hilos::$db->accountMergeCopies->hasStored($give->id));
            self::assertNull(AccountMergeCleanupResult::forOperation($operation));
        } finally {
            Database::sqlRun('DROP TRIGGER source_cleanup_fault');
        }
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        self::assertTrue($agent->shouldStop());
        self::assertFalse($agent->hasWorkInFlight());
        self::assertNotNull(AccountMergeCleanupResult::forOperation($operation));
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
        $this->assertPersonClaimsAndBoundary();
        $worker->idleAndTick(self::AGENT_ID);
        self::assertFalse($worker->agentManagerDouble->hasAgent(self::AGENT_ID));
        $this->assertClaimReleased();
        $server->daemonManager->removeAgent(self::AGENT_ID);
        $this->sendFrame($server);
        $replacement = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertFalse($replacement->hasWorkInFlight());
        self::assertFalse($replacement->shouldStop());
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        self::assertTrue($replacement->shouldStop());
        $worker->idleAndTick(self::AGENT_ID);
        $this->assertClaimReleased();
    }

    /** @return iterable<string, array{bool}> Open and completed immutable operation metadata */
    public static function cleanupReplayOperations(): iterable
    {
        yield 'open' => [false];
        yield 'completed' => [true];
    }

    /** @param bool $completed Whether the coordinator already finished the committed merge */
    #[DataProvider('cleanupReplayOperations')]
    public function testSavedCleanupSignalReplaysPastUnavailableTombstoneAndReleasesClaims(bool $completed): void
    {
        [$worker, $server, $first, $operation, $give, $cleanup, $receive] = $this->preparedSource();
        $this->receiveSource($give, $receive);
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        $saved = Hilos::$db->accountOperationResults->byAssignment($cleanup->id);
        self::assertNotNull($saved);
        $worker->idleAndTick(self::AGENT_ID);
        $this->assertClaimReleased();
        $server->daemonManager->removeAgent(self::AGENT_ID);
        if ($completed) {
            ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
                static fn () => $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        }
        $before = $this->ownedSnapshot();
        $context = $this->startWithoutTombstone($server);
        $replacement = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertNotSame($first, $replacement);
        self::assertTrue($replacement->hasWorkInFlight());
        self::assertFalse($replacement->shouldStop());
        self::assertSame(1, $context->tombstoneReads);
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        $replayed = Hilos::$db->accountOperationResults->byAssignment($cleanup->id);
        self::assertSame($saved->id, $replayed->id);
        self::assertSame($saved->payloadJson, $replayed->payloadJson);
        self::assertSame(['cleanup' => 'cleaned'], AccountMergeCleanupResult::of($replayed->payload)->toArray());
        self::assertSame($before, $this->ownedSnapshot());
        self::assertSame(1, $context->tombstoneReads);
        self::assertSame(0, $context->copyReads);
        self::assertSame(0, $context->trustReads);
        self::assertTrue($replacement->shouldStop());
        self::assertFalse($replacement->hasWorkInFlight());
        self::assertSame($replacement, $worker->agentManagerDouble->getAgent(self::AGENT_ID));
        $this->assertPersonClaimsAndBoundary();
        $worker->idleAndTick(self::AGENT_ID);
        self::assertFalse($worker->agentManagerDouble->hasAgent(self::AGENT_ID));
        $this->assertClaimReleased();
    }

    /** An available receipt cannot authorize new cleanup while the source context cannot be resolved. */
    public function testUnsavedCleanupSignalStaysPinnedWhenOnlyTombstoneReadFails(): void
    {
        [$worker, $server, , , $give, $cleanup, $receive] = $this->preparedSource();
        $this->receiveSource($give, $receive);
        $worker->handleDaemonMessage(new AgentStopDTO(self::AGENT_ID));
        $this->assertClaimReleased();
        $server->daemonManager->removeAgent(self::AGENT_ID);
        $before = $this->ownedSnapshot();
        $context = $this->startWithoutTombstone($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertSame(AccountOperationAnswerKind::Failed, $this->operationFrame($worker, $cleanup)->answer);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($cleanup->id));
        self::assertSame($before, $this->ownedSnapshot());
        self::assertSame(0, $context->copyReads);
        self::assertFalse($agent->shouldStop());
        self::assertTrue($agent->hasWorkInFlight());
        $worker->idleAndTick(self::AGENT_ID);
        self::assertSame($agent, $worker->agentManagerDouble->getAgent(self::AGENT_ID));
        $this->assertPersonClaimsAndBoundary();
    }

    /** @return iterable<string, array{string}> Saved results and immutable addresses that cannot authorize replay */
    public static function invalidCleanupReplayProofs(): iterable
    {
        foreach (['result owner', 'result payload', 'assignment owner', 'assignment user', 'assignment payload'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** @param string $defect Foreign or malformed cleanup proof presented while mutable state is unavailable */
    #[DataProvider('invalidCleanupReplayProofs')]
    public function testInvalidSavedCleanupSignalCannotStopPastUnavailableTombstone(string $defect): void
    {
        [$worker, $server, , , $give, $cleanup, $receive] = $this->preparedSource();
        $this->receiveSource($give, $receive);
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        $worker->idleAndTick(self::AGENT_ID);
        $this->assertClaimReleased();
        $server->daemonManager->removeAgent(self::AGENT_ID);
        match ($defect) {
            'result owner' => Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                [HilosAgentType::HILOS_USER . ':7', $cleanup->id]),
            'result payload' => Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                ['{"cleanup":"pending"}', $cleanup->id]),
            'assignment owner' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?',
                ['7', $cleanup->id]),
            'assignment user' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?',
                [7, $cleanup->id]),
            'assignment payload' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `payload_json` = ? WHERE `id` = ?',
                ['{"foreign":true}', $cleanup->id]),
        };
        $before = $this->ownedSnapshot();
        $context = $this->startWithoutTombstone($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertNotSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $cleanup)->answer);
        self::assertSame($before, $this->ownedSnapshot());
        self::assertSame(0, $context->copyReads);
        self::assertFalse($agent->shouldStop());
        self::assertTrue($agent->hasWorkInFlight());
        $worker->idleAndTick(self::AGENT_ID);
        self::assertSame($agent, $worker->agentManagerDouble->getAgent(self::AGENT_ID));
        $this->assertPersonClaimsAndBoundary();
    }

    /** Saved preparation/give survive cleanup/end, and a later own erasure start is served without a startup stop. */
    public function testCleanedFinishedSourceServesReplayAndLaterErasureAndStillStopsForItsDeletedRow(): void
    {
        [$worker, $server, , $operation, $give, $cleanup, $receive] = $this->preparedSource();
        $this->receiveSource($give, $receive);
        $this->operationFrame($worker, $cleanup);
        $worker->idleAndTick(self::AGENT_ID);
        $server->daemonManager->removeAgent(self::AGENT_ID);
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static fn () => $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->sendFrame($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertFalse($agent->shouldStop());
        self::assertFalse($agent->hasWorkInFlight());
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $give)->answer);
        $preparation = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
            AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value);
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $preparation)->answer);
        $start = $this->openErasure(42);
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $start)->answer);
        self::assertFalse($agent->shouldStop());
        $agent->onSignalDbSyncDeleted(new DbSyncDeletedSignalData(HilosDbContext::users, self::USER_ID), 'db', SignalConstants::DB_SYNC_DELETED);
        self::assertTrue($agent->shouldStop());
        $worker->idleAndTick(self::AGENT_ID);
        $this->assertClaimReleased();
    }

    /** A source accepts no other merge's preparation and cannot begin an erasure while cleanup is still owed. */
    public function testPendingSourceRefusesForeignContinuationAndNewPreparation(): void
    {
        [$worker, , $agent] = $this->preparedSource();
        $foreign = ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), static function () {
            $operation = Hilos::$db->accountOperations->actions->recordMerge(42, 43, new AccountMergeChoice(null, null, null, null));

            return Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Preparing->value,
                AccountMergeDuty::PrepareSurvivor->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, self::USER_ID),
                42, AccountOperationAssignmentPayload::fromJson('{}'));
        });
        $before = $this->ownedSnapshot();
        self::assertSame(AccountOperationAnswerKind::Refused, $this->operationFrame($worker, $foreign)->answer);
        self::assertSame($before, $this->ownedSnapshot());
        $start = $this->openErasure(42);
        self::assertSame(AccountOperationAnswerKind::Refused, $this->operationFrame($worker, $start)->answer);
        self::assertSame($before, $this->ownedSnapshot());
        self::assertTrue($agent->hasWorkInFlight());
        self::assertFalse($agent->shouldStop());
    }

    /** Source metadata uses a current read past an established snapshot and keeps the already issued Object. */
    public function testSourceLookupIncludesClosedOperationsAndRetainsHeldIdentityPastAnOldSnapshot(): void
    {
        [, , , $operation, $give] = $this->preparedSource();
        $held = $give->getObject();
        $config = Database::getConnectionConfig(0);
        Database::configure(index: 2, host: $config->host, user: $config->user, password: $config->password,
            database: $config->database, port: $config->port, charset: $config->charset, socket: $config->socket);
        Database::connect(2);
        Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?', ['foreign', $give->id]);
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_assignment`'
                . ' WHERE `owner_index` = ? AND `assignment_key` = ?', [self::USER_ID, AccountMergeDuty::Give->value])->field('n'));
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?',
                [self::USER_ID, $give->id], index: 2);
            Database::sqlRun('UPDATE `hilos_account_operation` SET `finished_at` = ? WHERE `id` = ?', [1, $operation->id], index: 2);
            $found = Hilos::$db->accountOperationAssignments->mergeGiveOfLoser(42);
            self::assertSame($held, $found->getObject());
            self::assertSame($operation->id, AccountMergeFinishing::forLoser(42)->id);
        } finally {
            Database::transactionRollback();
        }
        self::assertNull(Hilos::$db->accountOperationAssignments->mergeGiveOfLoser(7));
        self::assertFalse($this->survivor->shouldStop());
    }

    /** Malformed saved proofs and a failed source lookup cannot turn a source into a legacy stop or ordinary writer. */
    public function testMalformedSourceContextPinsTheAgentAndRefusesServiceWorkUntilAFrameRetries(): void
    {
        [$worker, $server, , , $give, $cleanup] = $this->preparedSource();
        $worker->handleDaemonMessage(new AgentStopDTO(self::AGENT_ID));
        $server->daemonManager->removeAgent(self::AGENT_ID);
        Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?', ['foreign', $give->id]);
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->sendFrame($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertTrue($agent->hasWorkInFlight());
        $before = $this->ownedSnapshot();
        $this->deliverFrame($worker, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
            new UserSecondFactorTrustRestrictSignalData(42, 1, 'source_service_done'));
        self::assertSame($before, $this->ownedSnapshot());
        self::assertFalse($agent->shouldStop());
        self::assertSame(AccountOperationAnswerKind::Refused, $this->operationFrame($worker, $cleanup)->answer);
        Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
            [self::AGENT_ID, $give->id]);
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        self::assertSame(AccountOperationAnswerKind::Waits, $this->operationFrame($worker, $cleanup)->answer);
        self::assertFalse($agent->shouldStop());
        self::assertTrue($agent->hasWorkInFlight());
    }

    /** A storage failure at cold start pins the instance, closes writes and retries on an addressed operation frame. */
    public function testSourceLookupFailureDoesNotBecomeLegacyAbsenceOrAllowAnOrdinaryWrite(): void
    {
        [$worker, $server, , , , $cleanup] = $this->preparedSource();
        $worker->handleDaemonMessage(new AgentStopDTO(self::AGENT_ID));
        $server->daemonManager->removeAgent(self::AGENT_ID);
        $context = new UserAgentIntegrationSourceContext();
        $context->configure();
        $context->refuseSourceLookup = true;
        Hilos::$db = $context;
        $this->sendFrame($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        self::assertTrue($agent->hasWorkInFlight());
        self::assertFalse($agent->shouldStop());
        self::assertSame(0, $context->trustReads);
        $before = $this->ownedSnapshot();
        $this->deliverFrame($worker, HilosSignalConstants::HILOS_USER_RENAME,
            new UserRenameSignalData(42, 'Late name', 42, 'source_service_done', 'accept', 'rename-1', 'rename', null, null));
        $answers = array_values(array_filter($this->drainSignals(), static fn (SignalDTO $signal): bool =>
            $signal->signalName->getName() === 'source_service_done'));
        self::assertCount(1, $answers);
        self::assertNotNull($answers[0]->data->data->error);
        self::assertSame($before, $this->ownedSnapshot());
        self::assertSame(AccountOperationAnswerKind::Failed, $this->operationFrame($worker, $cleanup)->answer);
        self::assertFalse($agent->shouldStop());
        $context->refuseSourceLookup = false;
        self::assertSame(AccountOperationAnswerKind::Waits, $this->operationFrame($worker, $cleanup)->answer);
        self::assertTrue($agent->hasWorkInFlight());
    }

    /** @param string $defect Corrupted immutable proof or tombstone coordinate */
    #[DataProvider('invalidSourceProofs')]
    public function testSourceContextRefusesMalformedPreparationsManifestsAndTombstone(string $defect): void
    {
        [, , , $operation, $give] = $this->preparedSource();
        $preparation = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
            AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value);
        switch ($defect) {
            case 'preparation owner':
                Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?',
                    ['43', $preparation->id]);
                break;
            case 'preparation result owner':
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                    ['foreign', $preparation->id]);
                break;
            case 'missing preparation result':
                Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$preparation->id]);
                break;
            case 'preparation payload':
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                    ['{}', $preparation->id]);
                break;
            case 'give payload':
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                    ['{}', $give->id]);
                break;
            case 'give assignment payload':
                Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `payload_json` = ? WHERE `id` = ?',
                    ['{"foreign":true}', $give->id]);
                break;
            case 'give user':
                Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?', [7, $give->id]);
                break;
            case 'manifest choice':
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = JSON_SET(`payload_json`, ?, ?)'
                    . ' WHERE `assignment_id` = ?', ['$.passwordKept', PasswordFate::SURVIVOR->value, $give->id]);
                break;
            case 'tombstone survivor':
                Database::sqlRun('UPDATE `hilos_user_merge` SET `survivor_user_id` = ? WHERE `user_id` = ?', [43, 42]);
                break;
        }
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->expectException(InvalidFormatException::class);
        AccountMergeFinishing::forLoser(42);
    }

    /** @return iterable<string, array{string}> Immutable proof binding and manifest failures */
    public static function invalidSourceProofs(): iterable
    {
        foreach (['preparation owner', 'preparation result owner', 'missing preparation result', 'preparation payload',
            'give payload', 'give assignment payload', 'give user', 'manifest choice', 'tombstone survivor'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /**
     * @return array{UserAgentIntegrationWorkerManager, UserAgentIntegrationWorkerServer, AbstractUserAgent,
     *     AccountOperation, AccountOperationAssignment, AccountOperationAssignment, AccountOperationAssignment} Real saved source give
     */
    private function preparedSource(): array
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (42, 'Source'), (7, 'Survivor'), (43, 'Other')");
        $worker = new UserAgentIntegrationWorkerManager();
        $master = new UserAgentIntegrationDaemonManager();
        $server = new ReflectionClass(UserAgentIntegrationWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $master);
        $server->worker = $worker;
        $server->daemonManager = $master;
        $this->sendFrame($server);
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        OwnershipDeclaration::claimDb(UserAgentIntegrationSessionsHolder::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $this->survivor = new UserAgentIntegrationWorker('7');
        OwnershipDeclaration::claimAll($this->survivor);
        $operation = ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static fn () => AccountMergeOpening::open(7, 42, new AccountMergeChoice(null, null, null, null)));
        foreach ([$agent, $this->survivor] as $person) {
            $duty = $person === $agent ? AccountMergeDuty::PrepareLoser : AccountMergeDuty::PrepareSurvivor;
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            ExecutionContext::run(new ExecutionFrame(agentId: $person->getId()), static fn () => $person->prepareForMerge($assignment->id));
        }
        [$give, $cleanup, $receive] = ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static function () use ($operation): array {
                $operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value);
                $assignments = [];
                foreach ([[AccountMergeDuty::Give, 42], [AccountMergeDuty::Cleanup, 42], [AccountMergeDuty::Receive, 7]] as [$duty, $userId]) {
                    $assignments[] = Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
                        $duty->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId), $userId,
                        AccountOperationAssignmentPayload::fromJson('{}'));
                }

                return $assignments;
            });
        self::assertSame(AccountOperationAnswerKind::Done, $this->operationFrame($worker, $give)->answer);

        return [$worker, $server, $agent, $operation, $give, $cleanup, $receive];
    }

    /**
     * @param AccountOperationAssignment $give Exact source give
     * @param AccountOperationAssignment $receive Exact survivor receive
     */
    private function receiveSource(AccountOperationAssignment $give, AccountOperationAssignment $receive): void
    {
        $handover = ExecutionContext::run(new ExecutionFrame(agentId: self::AGENT_ID),
            static fn () => AccountMergeGiving::handover($give->id, 42));
        ExecutionContext::run(new ExecutionFrame(agentId: $this->survivor->getId()),
            fn () => $this->survivor->receiveForMerge($receive->id, $handover));
    }

    /**
     * @param UserAgentIntegrationWorkerManager $worker Actual recipient worker
     * @param AccountOperationAssignment $assignment Immutable addressed duty
     * @return AccountOperationAnswerSignalData Queued correlated answer, before the worker's next stop tick
     */
    private function operationFrame(
        UserAgentIntegrationWorkerManager $worker,
        AccountOperationAssignment $assignment,
    ): AccountOperationAnswerSignalData {
        $this->drainSignals();
        $this->deliverFrame($worker, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
            new AccountOperationAskSignalData($assignment->operationId, $assignment->stage, $assignment->id, self::USER_ID));
        $answers = array_values(array_filter($this->drainSignals(), static fn (SignalDTO $signal): bool =>
            $signal->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER));
        self::assertCount(1, $answers);
        $answer = $answers[0]->data->data;
        self::assertSame([$assignment->operationId, $assignment->stage, $assignment->id],
            [$answer->operationId, $answer->stage, $answer->assignmentId]);

        return $answer;
    }

    /**
     * @param UserAgentIntegrationWorkerServer $server Real lifecycle entry for the replacement owner
     * @return UserAgentIntegrationSourceContext Cold metadata with only the mutable tombstone read refused
     */
    private function startWithoutTombstone(UserAgentIntegrationWorkerServer $server): UserAgentIntegrationSourceContext
    {
        $context = new UserAgentIntegrationSourceContext();
        $context->configure();
        $context->refuseTombstoneLookup = true;
        Hilos::$db = $context;
        $this->sendFrame($server);

        return $context;
    }

    /**
     * @param UserAgentIntegrationWorkerManager $worker Actual recipient worker
     * @param string $name Existing personal frame
     * @param SignalDataInterface $ask Its production payload
     */
    private function deliverFrame(UserAgentIntegrationWorkerManager $worker, string $name, SignalDataInterface $ask): void
    {
        $agent = $worker->agentManagerDouble->getAgent(self::AGENT_ID);
        ExecutionContext::run(new ExecutionFrame(agentId: self::AGENT_ID),
            static fn () => $agent->onSignalAgent(new AgentSignalData($ask), 'coordinator', $name));
    }

    /** @return list<SignalDTO> Queued frames, drained without executing another agent's work */
    private function drainSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /** @return array<string, string> Persisted digests of the source's sets, excluding private payload output */
    private function ownedSnapshot(): array
    {
        $snapshot = [];
        foreach (['hilos_user', 'hilos_user_merge', 'hilos_user_rename', 'hilos_second_factor_trust', 'hilos_legal_acceptance',
            'hilos_push_subscription', 'hilos_account_operation_result', 'hilos_account_merge_copy'] as $table) {
            $snapshot[$table] = hash('sha256', serialize(Database::sql('SELECT * FROM `' . $table . '` ORDER BY 1')->rows()));
        }

        return $snapshot;
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
        // The prepared merge adds its own tombstone and private copy; the copy uses the full agent id (HIL-1992).
        foreach (self::addedSets() as $collection) {
            TruthSourceRegistry::checkCanCreate($collection, static fn (): array => [self::setKeyOf($collection, self::USER_ID)]);
            $this->assertCreateDenied($collection, [self::setKeyOf($collection, '43')]);
        }
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::userMerges,
            self::USER_ID,
            static fn (): array => [self::USER_ID],
            TruthSourceOperation::Remove,
        );
        $this->assertWriteDenied(HilosDbContext::userMerges, '43', ['43'], TruthSourceOperation::Remove);
        $this->assertWriteDenied(HilosDbContext::userMerges, self::USER_ID, [self::USER_ID]);
        $this->assertCreateDenied(HilosDbContext::accountMergeCopies, [self::USER_ID]);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::accountMergeCopies, '1', static fn (): array => [self::AGENT_ID], TruthSourceOperation::Remove,
        );
        $this->assertWriteDenied(HilosDbContext::accountMergeCopies, '1', [self::AGENT_ID], TruthSourceOperation::Update);
        foreach ([TruthSourceOperation::Update, TruthSourceOperation::Remove] as $operation) {
            $this->assertWriteDenied(HilosDbContext::accountMergeCopies, '2', [self::setKeyOf(HilosDbContext::accountMergeCopies, '43')], $operation);
        }
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::secondFactorSettings,
            self::USER_ID,
            static fn (): array => [self::USER_ID],
            TruthSourceOperation::Update,
        );
        $this->assertWriteDenied(HilosDbContext::secondFactorSettings, '43', ['43']);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::accountMergeFences,
            'identities:1',
            static fn (): array => [self::USER_ID],
            TruthSourceOperation::Update,
        );
        $this->assertWriteDenied(HilosDbContext::accountMergeFences, 'identities:2', ['43']);
        $this->assertWriteDenied(HilosDbContext::accountMergeFences, 'identities:1', [self::AGENT_ID]);
        $this->assertCreateDenied(HilosDbContext::accountMergeFences, [self::AGENT_ID]);
        $this->assertWriteDenied(HilosDbContext::accountMergeFences, 'identities:1', [self::USER_ID], TruthSourceOperation::Remove);
        $this->assertWriteDenied(HilosDbContext::accountMergeFences, 'identities:2', ['43'], TruthSourceOperation::Remove);
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
        try {
            TruthSourceRegistry::checkCanWriteItem(
                HilosDbContext::accountMergeFences,
                'identities:1',
                static fn (): array => [self::USER_ID],
                TruthSourceOperation::Update,
            );
            self::fail('A stopped person retained its fence update claim');
        } catch (WriteNotAllowedException $refusal) {
            self::assertSame(
                $this->survivor === null
                    ? "Write operation not allowed: no truth source registered for table 'accountMergeFences'. "
                        . 'Register via TruthSourceRegistry::register() first.'
                    : "Write operation not allowed: agent 'hilos_user:42' is not a truth source for table 'accountMergeFences'"
                        . " item 'identities:1'.",
                $refusal->getMessage(),
            );
        }
        $this->assertWriteDenied(HilosDbContext::userMerges, self::USER_ID, [self::USER_ID], TruthSourceOperation::Remove);
        try {
            TruthSourceRegistry::checkCanWriteItem(
                HilosDbContext::accountMergeCopies, '1', static fn (): array => [self::AGENT_ID], TruthSourceOperation::Remove,
            );
            self::fail('A stopped person retained its copy Remove claim');
        } catch (WriteNotAllowedException $refusal) {
            self::assertSame(
                $this->survivor === null
                    ? "Write operation not allowed: no truth source registered for table 'accountMergeCopies'. "
                        . 'Register via TruthSourceRegistry::register() first.'
                    : "Write operation not allowed: agent 'hilos_user:42' is not a truth source for table 'accountMergeCopies' item '1'.",
                $refusal->getMessage(),
            );
        }
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
            HilosDbContext::userMerges,
            HilosDbContext::accountOperationResults,
            HilosDbContext::accountMergeCopies,
            HilosDbContext::accountMergeFences,
        ];
    }

    /**
     * @param string $collection Added set being checked
     * @param string $userId Person whose set is meant
     * @return string The full agent id for results and private copies, the person's id for other sets
     */
    private static function setKeyOf(string $collection, string $userId): string
    {
        return in_array($collection, [HilosDbContext::accountOperationResults, HilosDbContext::accountMergeCopies], true)
            ? HilosAgentType::HILOS_USER . ':' . $userId
            : $userId;
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
     * @param ?AgentStartSink $immediateStartSink Synchronous reports for this call only; deferred reports use the registered sink
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null, ?AgentStartSink $immediateStartSink = null): void
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

/** Detects an accidental source lookup from the idle path without changing lifecycle behavior. */
final class UserAgentIntegrationSourceContext extends HilosDbContext
{
    public bool $refuseSourceLookup = false;
    public bool $refuseTombstoneLookup = false;
    public int $sourceReads = 0;
    public int $trustReads = 0;
    public int $tombstoneReads = 0;
    public int $copyReads = 0;

    /**
     * @param string $name Requested collection
     * @return mixed Real framework collection
     */
    public function __get(string $name): mixed
    {
        if ($name === self::accountOperationAssignments) {
            $this->sourceReads++;
            if ($this->refuseSourceLookup) {
                throw new DatabaseException('Source metadata is unavailable');
            }
        }
        if ($name === self::secondFactorTrusts) {
            $this->trustReads++;
        }
        if ($name === self::userMerges) {
            $this->tombstoneReads++;
            if ($this->refuseTombstoneLookup) {
                throw new DatabaseException('The mutable merge tombstone is unavailable');
            }
        }
        if ($name === self::accountMergeCopies) {
            $this->copyReads++;
        }

        return parent::__get($name);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Generator;
use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Throwable;

/** Real holder claims, actual give/receive and real participant commits. */
final class AccountMergeSessionsParticipantIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int USER_ID = 1995;
    private const int SURVIVOR = 1996;
    private const int NEIGHBOUR_ID = 1997;
    private const int SESSION_CREATED_AT = 1790416800000;
    private const int FUTURE = 2082758400000;
    private const string SIGNED_IN_TOKEN = '19950000000000000000000000000001';
    private const string SURVIVOR_TOKEN = '19960000000000000000000000000001';
    private const string NEIGHBOUR_TOKEN = '19970000000000000000000000000001';
    private const string ADDRESS = '203.0.113.7';
    private const array TABLES = [
        'hilos_passkey_credential', 'hilos_account_operation_assignment',
        'hilos_account_operation_result', 'hilos_account_merge_copy',
    ];
    private MergeSessionsTestHolder $holder;
    private MergeSessionsTestRtContext $rt;
    private string $previousApp;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;

    /**
     * @throws HilosException When the schema, the runtime context or the holder's claims cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $this->rt = new MergeSessionsTestRtContext();
        $this->rt->mountFeatureRuntime([new AuthFeature()]);
        $this->rt->configure();
        $this->rt->bindStateCollectionNames();
        Hilos::$rt = $this->rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        MergeSessionsTestHilos::initBrowser();
        $this->holder = new MergeSessionsTestHolder();
        OwnershipDeclaration::claimAll($this->holder);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            $this->releasePersonAgents();
            SourceChangeBus::reset();
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();

            parent::tearDown();
        }
    }

    public function testBothDutiesWaitForGiveWithoutChangesOrResults(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready();
        foreach ([AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions] as $duty) {
            $assignment = $this->obligation($operation, $duty);
            $this->assertNoWork($assignment, ValidationException::class, 'waiting for the account handover');
        }
    }

    public function testGiveTransfersLogAndEndsOnlyLoserSessionsWithSavedFirstReplay(): void
    {
        $loser = $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $survivor = $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        $neighbour = $this->signIn(self::NEIGHBOUR_TOKEN, self::NEIGHBOUR_ID);
        self::logAccess(self::USER_ID);
        self::logAccess(self::USER_ID);
        $original = self::accessLogOf(self::USER_ID);
        $operation = $this->ready();
        $this->give($operation);
        $assignment = $this->obligation($operation, AccountMergeDuty::Sessions);
        $this->observeCommit();

        $result = $this->execute($assignment);

        self::assertSame('{"sessions":1,"accessLog":2}', $result->payloadJson);
        self::assertSame($assignment->id, $result->assignmentId);
        self::assertSame($this->holder->getId(), $result->ownerAgentId);
        self::assertNull(self::sessionRowById($loser)['user_id']);
        self::assertSame((string)self::SURVIVOR, self::sessionRowById($survivor)['user_id']);
        self::assertSame((string)self::NEIGHBOUR_ID, self::sessionRowById($neighbour)['user_id']);
        foreach ($original as &$row) {
            $row['user_id'] = (string)self::SURVIVOR;
        }
        unset($row);
        self::assertSame($original, self::accessLogOf(self::SURVIVOR));
        self::assertSame([], self::accessLogOf(self::USER_ID));
        self::assertSame([$loser], array_keys($this->framesBySession()));

        $this->asHolder(fn () => $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $this->assertSavedAfterNewBusiness($assignment, $result);
    }

    public function testTouchingSessionsBecomeGuestsOnceWhileBlockedCardsAndUnrelatedBrowsersStay(): void
    {
        $signedIn = $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $target = $this->signIn('19950000000000000000000000000002', self::USER_ID, self::NEIGHBOUR_ID);
        $impersonator = $this->signIn('19950000000000000000000000000003', self::NEIGHBOUR_ID, self::USER_ID);
        $shared = $this->signIn('19950000000000000000000000000004', self::SURVIVOR, self::USER_ID);
        $waiting = $this->waitOnSecondFactor('19950000000000000000000000000005', self::USER_ID);
        Database::sqlRun('UPDATE `hilos_session` SET `pending_second_factor_user_id` = ?, `pending_second_factor_mode` = ?,'
            . ' `pending_second_factor_until` = ? WHERE `id` = ?',
            [self::USER_ID, SecondFactorPendingMode::VERIFY, self::FUTURE, $target]);
        $blocked = $this->blockedCard('19950000000000000000000000000006', self::USER_ID);
        $survivor = $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        $neighbour = $this->signIn(self::NEIGHBOUR_TOKEN, self::NEIGHBOUR_ID);
        $unrelatedWait = $this->waitOnSecondFactor('19970000000000000000000000000002', self::NEIGHBOUR_ID);
        $untouched = [];
        foreach ([$blocked, $survivor, $neighbour, $unrelatedWait] as $id) {
            $untouched[$id] = self::sessionRowById($id);
        }
        $operation = $this->ready();
        $this->give($operation);
        $assignment = $this->obligation($operation, AccountMergeDuty::Sessions);
        $this->observeCommit();

        $result = $this->execute($assignment);

        self::assertSame('{"sessions":5,"accessLog":0}', $result->payloadJson);
        foreach ([$signedIn, $target, $impersonator, $shared, $waiting] as $id) {
            $row = self::sessionRowById($id);
            foreach (['user_id', 'impersonator_user_id', 'pending_second_factor_user_id'] as $column) {
                self::assertNull($row[$column]);
            }
        }
        foreach ($untouched as $id => $row) {
            self::assertSame($row, self::sessionRowById($id));
        }
        $frames = $this->framesBySession();
        self::assertSame([$signedIn, $target, $impersonator, $shared, $waiting], array_keys($frames));
        foreach ($frames as $states) {
            self::assertNull(end($states)->userId);
        }
        self::assertSame(AuthFlowStep::IDENTIFIER, end($frames[$waiting])->pendingAuthStep[HandshakeResponseSignalData::step] ?? null);
        self::assertSame($result->id, $this->execute($assignment)->id);
        self::assertSame([], $this->drainQueue());
    }

    /** @return iterable<string, array{string}> Saved give fates that preserve survivor sessions */
    public static function preservingFates(): iterable
    {
        yield 'both' => [AccountMergeGiveResult::SECOND_FACTOR_BOTH];
        yield 'survivor' => [AccountMergeGiveResult::SECOND_FACTOR_SURVIVOR];
        yield 'none' => [AccountMergeGiveResult::SECOND_FACTOR_NONE];
    }

    /** @param string $fate Protection decision already recorded by the genuine give */
    #[DataProvider('preservingFates')]
    public function testSavedPreservingFatesDoNotEndSurvivorSessionsAfterFactorsChange(string $fate): void
    {
        if ($fate !== AccountMergeGiveResult::SECOND_FACTOR_NONE) {
            $this->factor(self::USER_ID);
            $this->factor(self::SURVIVOR);
        }
        $survivor = $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        $operation = $this->ready(match ($fate) {
            AccountMergeGiveResult::SECOND_FACTOR_BOTH => SecondFactorFate::BOTH,
            AccountMergeGiveResult::SECOND_FACTOR_SURVIVOR => SecondFactorFate::SURVIVOR,
            default => null,
        });
        $give = $this->give($operation);
        self::assertSame($fate, AccountMergeGiveResult::of($give->payload)->secondFactorKept);
        $this->receive($operation);
        $assignment = $this->obligation($operation, AccountMergeDuty::SurvivorSessions);
        Database::sqlRun('DELETE FROM `hilos_second_factor`');
        $this->factor(self::USER_ID);
        $this->freshContext();
        $sessions = self::allSessionRows();
        $log = Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows();
        $factors = Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows();
        $this->observeCommit();

        $result = $this->execute(Hilos::$db->accountOperationAssignments[$assignment->id]);

        self::assertSame('{"sessions":0,"accessLog":0}', $result->payloadJson);
        self::assertSame((string)self::SURVIVOR, self::sessionRowById($survivor)['user_id']);
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($log, Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows());
        self::assertSame($factors, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
        $signals = $this->drainQueue();
        $profiles = array_values(array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SECOND_FACTOR_STATE));
        self::assertCount(1, $profiles);
        self::assertSame(SecondFactorGroup::forUser(self::SURVIVOR), $profiles[0]->data->targetGroup);
        self::assertSame(SecondFactorStateProjector::stateFor(self::SURVIVOR)->toArray(), $profiles[0]->data->data->toArray());
        self::assertCount(0, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE));
        self::assertSame($result->id, $this->execute($assignment)->id);
        self::assertSame([], $this->drainQueue());
    }

    public function testSurvivorWaitsForRealReceiptThenUsesSavedLoserFactorFate(): void
    {
        $this->factor(self::USER_ID);
        $survivor = $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        $operation = $this->ready(SecondFactorFate::BOTH);
        $this->give($operation);
        $assignment = $this->obligation($operation, AccountMergeDuty::SurvivorSessions);
        $this->assertNoWork($assignment, ValidationException::class, 'waiting for confirmed acceptance');
        $this->receive($operation);
        $this->observeCommit();

        $result = $this->execute($assignment);

        self::assertSame('{"sessions":1,"accessLog":0}', $result->payloadJson);
        self::assertNull(self::sessionRowById($survivor)['user_id']);
        $signals = $this->drainQueue();
        $profiles = array_values(array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SECOND_FACTOR_STATE));
        self::assertCount(1, $profiles);
        self::assertInstanceOf(WebSocketSignalData::class, $profiles[0]->data);
        self::assertSame(SecondFactorGroup::forUser(self::SURVIVOR), $profiles[0]->data->targetGroup);
        self::assertInstanceOf(SecondFactorStateSignalData::class, $profiles[0]->data->data);
        self::assertSame(SecondFactorStateProjector::stateFor(self::SURVIVOR)->toArray(), $profiles[0]->data->data->toArray());
        self::assertCount(1, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE));
        $this->asHolder(fn () => $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $this->assertSavedAfterNewBusiness($assignment, $result);
    }

    public function testEmptyWorkRecordsBothResultsAndOnlyOneSurvivorNotification(): void
    {
        $operation = $this->ready();
        $this->give($operation);
        $source = $this->obligation($operation, AccountMergeDuty::Sessions);
        self::assertSame('{"sessions":0,"accessLog":0}', $this->execute($source)->payloadJson);
        $this->receive($operation);
        $survivor = $this->obligation($operation, AccountMergeDuty::SurvivorSessions);
        $this->observeCommit();
        $result = $this->execute($survivor);
        self::assertSame('{"sessions":0,"accessLog":0}', $result->payloadJson);
        $signals = $this->drainQueue();
        self::assertCount(1, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SECOND_FACTOR_STATE));
        self::assertCount(0, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE));
        self::assertSame($result->id, $this->execute($survivor)->id);
        self::assertSame([], $this->drainQueue());
    }

    public function testExistingAskCorrelationAndInlineDispatchKeepMergeWaitingForReceipt(): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $survivorSession = $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready();
        $source = $this->obligation($operation, AccountMergeDuty::Sessions);
        $survivor = $this->obligation($operation, AccountMergeDuty::SurvivorSessions);
        $ask = new AccountOperationAskSignalData($operation->id, AccountMergeStage::Go->value, $source->id, null);
        $this->drainQueue();
        $sessions = self::allSessionRows();
        $log = self::accessLogOf(self::USER_ID);

        $refused = $this->answer($ask);
        self::assertSame(AccountOperationAnswerKind::Refused, $refused->answer);
        self::assertSame($ask->operationId, $refused->operationId);
        self::assertSame($ask->stage, $refused->stage);
        self::assertSame($ask->assignmentId, $refused->assignmentId);
        foreach ([
            new AccountOperationAskSignalData($operation->id + 1, $ask->stage, $source->id, null),
            new AccountOperationAskSignalData($operation->id, AccountMergeStage::Preparing->value, $source->id, null),
        ] as $foreignAsk) {
            $answer = $this->answer($foreignAsk);
            self::assertSame(AccountOperationAnswerKind::Refused, $answer->answer);
            self::assertSame($foreignAsk->operationId, $answer->operationId);
            self::assertSame($foreignAsk->stage, $answer->stage);
            self::assertSame($foreignAsk->assignmentId, $answer->assignmentId);
        }
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($log, self::accessLogOf(self::USER_ID));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($source->id));
        self::assertSame([], $this->drainQueue());

        $this->give($operation);
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        $this->observeCommit();
        $this->inlineAsk([$source, $survivor]);
        $result = Hilos::$db->accountOperationResults->byAssignment($source->id);
        self::assertNotNull($result);
        self::assertSame('{"sessions":1,"accessLog":1}', $result->payloadJson);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($survivor->id));
        $this->drainQueue();
        $done = $this->answer($ask);
        self::assertSame(AccountOperationAnswerKind::Done, $done->answer);
        self::assertSame($ask->operationId, $done->operationId);
        self::assertSame($ask->stage, $done->stage);
        self::assertSame($ask->assignmentId, $done->assignmentId);
        $this->asHolder(fn () => $this->holder->onSignalAgent(
            new AgentSignalData($done), SignalSource::describe(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_SESSIONS_LIBRARY)),
            HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
        ));
        $this->inlineAsk([$source, $survivor]);
        self::assertSame($result->id, Hilos::$db->accountOperationResults->byAssignment($source->id)->id);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($survivor->id));
        self::assertSame((string)self::SURVIVOR, self::sessionRowById($survivorSession)['user_id']);
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        self::assertNull($operation->finishedAt);
        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame([], $this->drainQueue());
    }

    /** @param AccountMergeDuty $duty Saved holder duty requested by a new holder */
    #[DataProvider('duties')]
    public function testColdHolderReplaysSavedDutyAndFinishesItsOtherDutyOnlyOnce(AccountMergeDuty $duty): void
    {
        $this->factor(self::USER_ID);
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready(SecondFactorFate::BOTH);
        $this->give($operation);
        $this->receive($operation);
        $saved = $this->obligation($operation, $duty);
        $owed = $this->obligation($operation,
            $duty === AccountMergeDuty::Sessions ? AccountMergeDuty::SurvivorSessions : AccountMergeDuty::Sessions);
        $result = $this->execute($saved);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($owed->id));
        $this->coldHolder();

        $this->assertSavedAfterNewBusiness(Hilos::$db->accountOperationAssignments[$saved->id], $result);
        $this->observeCommit();
        $completed = $this->execute(Hilos::$db->accountOperationAssignments[$owed->id]);

        self::assertSame($owed->id, $completed->assignmentId);
        self::assertSame($this->holder->getId(), $completed->ownerAgentId);
        self::assertSame($duty === AccountMergeDuty::Sessions ? '{"sessions":2,"accessLog":0}' : '{"sessions":2,"accessLog":2}',
            $completed->payloadJson);
        $signals = $this->drainQueue();
        self::assertCount(2, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE));
        self::assertCount($duty === AccountMergeDuty::Sessions ? 1 : 0, array_filter($signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === HilosSignalConstants::HILOS_SECOND_FACTOR_STATE));
        $this->assertReplay(Hilos::$db->accountOperationAssignments[$owed->id], $completed);
    }

    /** @param AccountMergeDuty $duty Saved result independent of private copy and later operations */
    #[DataProvider('duties')]
    public function testClosedReplaySurvivesSourceCopyCleanupAndLaterMerge(AccountMergeDuty $duty): void
    {
        $this->factor(self::USER_ID);
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready(SecondFactorFate::BOTH);
        $give = $this->give($operation);
        $this->receive($operation);
        $assignment = $this->obligation($operation, $duty);
        $result = $this->execute($assignment);
        $this->asHolder(fn () => $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->assignmentId]);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        $later = $this->asHolder(static fn () => AccountMergeOpening::open(
            self::SURVIVOR, self::NEIGHBOUR_ID, new AccountMergeChoice(null, null, null, null),
        ));
        self::assertNotSame($operation->id, $later->id);
        $this->coldHolder();
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        $results = Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();

        $this->assertSavedAfterNewBusiness(Hilos::$db->accountOperationAssignments[$assignment->id], $result);

        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame($results, Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows());
        self::assertSame(AccountMergeStage::Preparing->value, Hilos::$db->accountOperations[$later->id]->stage);
        self::assertNull(Hilos::$db->accountOperations[$later->id]->finishedAt);
    }

    /** @param AccountMergeDuty $duty Canonical entry whose assignment the other entry must refuse */
    #[DataProvider('duties')]
    public function testPreparedEntriesCannotBorrowEachOthersWorkOrSavedResult(AccountMergeDuty $duty): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready();
        $this->give($operation);
        $this->receive($operation);
        $assignment = $this->obligation($operation, $duty);
        $this->assertNoWork($assignment, AccountOperationStepRefusedException::class, survivor: $duty === AccountMergeDuty::Sessions);
        $this->execute($assignment);
        $this->assertNoWork($assignment, AccountOperationStepRefusedException::class, survivor: $duty === AccountMergeDuty::Sessions);
    }

    /** @return iterable<string, array{AccountMergeDuty}> Each independently atomic entry */
    public static function duties(): iterable
    {
        yield 'source sessions' => [AccountMergeDuty::Sessions];
        yield 'survivor sessions' => [AccountMergeDuty::SurvivorSessions];
    }

    /** @param AccountMergeDuty $duty Duty whose result insert fails */
    #[DataProvider('duties')]
    public function testResultInsertFailureRestoresDatabaseHeldRowsRuntimeAndAnnouncements(AccountMergeDuty $duty): void
    {
        $this->factor(self::USER_ID);
        $sessionId = $this->signIn(self::SIGNED_IN_TOKEN,
            $duty === AccountMergeDuty::Sessions ? self::USER_ID : self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready(SecondFactorFate::BOTH);
        $this->give($operation);
        if ($duty === AccountMergeDuty::SurvivorSessions) {
            $this->receive($operation);
        }
        $assignment = $this->obligation($operation, $duty);
        $session = Hilos::$db->sessions[$sessionId];
        $object = $session->getObject();
        $beforeObject = $object->toArray();
        $logId = (int)self::accessLogOf(self::USER_ID)[0]['id'];
        $logObject = Hilos::$db->accessLogEntries[$logId]->getObject();
        $beforeLog = $logObject->toArray();
        $this->busyBrowser(self::SIGNED_IN_TOKEN);
        $runtime = $this->runtimeSnapshot();
        $this->drainQueue();
        Database::sqlRun("CREATE TRIGGER `merge_sessions_failure` BEFORE INSERT ON `hilos_account_operation_result`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'merge sessions storage failure'");
        try {
            $this->assertNoWork($assignment, DatabaseException::class, 'merge sessions storage failure');
            self::assertSame($object, Hilos::$db->sessions[$sessionId]->getObject());
            self::assertSame($beforeObject, $object->toArray());
            self::assertSame($logObject, Hilos::$db->accessLogEntries[$logId]->getObject());
            self::assertSame($beforeLog, $logObject->toArray());
            self::assertSame($runtime, $this->runtimeSnapshot());
        } finally {
            Database::sqlRun('DROP TRIGGER `merge_sessions_failure`');
        }
        $result = $this->execute($assignment);
        self::assertSame(1, json_decode($result->payloadJson, true)['sessions']);
    }

    /** @return iterable<string, array{AccountMergeDuty, string}> Immutable assignment defects */
    public static function addressDefects(): iterable
    {
        foreach ([AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions] as $duty) {
            foreach (['stage', 'user', 'owner', 'index', 'payload', 'duty', 'caller'] as $defect) {
                yield $duty->value . ' ' . $defect => [$duty, $defect];
            }
        }
    }

    /**
     * @param AccountMergeDuty $duty Holder entry to validate
     * @param string $defect Address or executor to refuse before work or saved replay
     */
    #[DataProvider('addressDefects')]
    public function testForeignAddressesNeverRunOrBorrowSavedResults(AccountMergeDuty $duty, string $defect): void
    {
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        self::logAccess(self::USER_ID);
        $operation = $this->ready();
        $this->give($operation);
        $assignment = $this->obligation($operation, $duty);
        if ($defect === 'caller') {
            $before = self::allSessionRows();
            try {
                ExecutionContext::run(new ExecutionFrame(agentId: 'foreign-holder'), fn () => $this->holder->applyPart($assignment->id));
                self::fail('A foreign caller ran holder work');
            } catch (AccountOperationStepRefusedException) {
                self::assertSame($before, self::allSessionRows());
                self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
            }

            return;
        }
        $column = match ($defect) {
            'stage' => 'stage', 'user' => 'user_id', 'owner' => 'owner_type',
            'payload' => 'payload_json', 'duty' => 'assignment_key', 'index' => 'owner_index',
        };
        $value = match ($defect) {
            'stage' => AccountMergeStage::Preparing->value, 'user' => self::NEIGHBOUR_ID,
            'owner' => HilosAgentType::HILOS_USER, 'payload' => '{"unexpected":true}', 'duty' => 'foreign-duty',
            'index' => 'foreign-index',
        };
        Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?', [$value, $assignment->id]);
        $this->freshContext();
        $this->assertNoWork(Hilos::$db->accountOperationAssignments[$assignment->id], AccountOperationStepRefusedException::class);
    }

    /** @return iterable<string, array{AccountMergeDuty, string}> Strict proof and saved result defects */
    public static function resultDefects(): iterable
    {
        foreach ([AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions] as $duty) {
            foreach (['give owner', 'give shape', 'give address', 'give missing', 'saved owner', 'saved shape'] as $defect) {
                yield $duty->value . ' ' . $defect => [$duty, $defect];
            }
        }
        yield 'receipt owner' => [AccountMergeDuty::SurvivorSessions, 'receipt owner'];
        yield 'receipt shape' => [AccountMergeDuty::SurvivorSessions, 'receipt shape'];
        yield 'receipt address' => [AccountMergeDuty::SurvivorSessions, 'receipt address'];
        yield 'receipt missing' => [AccountMergeDuty::SurvivorSessions, 'receipt missing'];
        yield 'survivor log count' => [AccountMergeDuty::SurvivorSessions, 'saved log'];
    }

    /**
     * @param AccountMergeDuty $duty Holder duty being requested
     * @param string $defect Forged fact that cannot authorize work or replay
     */
    #[DataProvider('resultDefects')]
    public function testMalformedOrForeignFactsFailClosed(AccountMergeDuty $duty, string $defect): void
    {
        $this->factor(self::USER_ID);
        $this->signIn(self::SIGNED_IN_TOKEN, self::USER_ID);
        $this->signIn(self::SURVIVOR_TOKEN, self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $operation = $this->ready(SecondFactorFate::BOTH);
        $give = $this->give($operation);
        $receipt = $this->receive($operation);
        $assignment = $this->obligation($operation, $duty);
        if (str_starts_with($defect, 'saved')) {
            $target = $this->execute($assignment);
        } else {
            $target = str_starts_with($defect, 'give') ? $give : $receipt;
        }
        if (str_ends_with($defect, 'missing')) {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `id` = ?', [$target->id]);
        } elseif (str_ends_with($defect, 'address')) {
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?',
                [self::NEIGHBOUR_ID, $target->assignmentId]);
        } elseif (str_ends_with($defect, 'owner')) {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `id` = ?',
                ['foreign-owner', $target->id]);
        } else {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `id` = ?',
                [$defect === 'saved log' ? '{"sessions":1,"accessLog":1}' : '{}', $target->id]);
        }
        $this->freshContext();
        $this->assertNoWork(Hilos::$db->accountOperationAssignments[$assignment->id],
            str_ends_with($defect, 'missing') ? ValidationException::class : InvalidFormatException::class);
    }

    /**
     * @param ?SecondFactorFate $fate Immutable administrator choice for protection
     * @return AccountOperation Merge with two genuine reservations and an open go stage
     */
    private function ready(?SecondFactorFate $fate = null): AccountOperation
    {
        foreach ([self::USER_ID, self::SURVIVOR, self::NEIGHBOUR_ID] as $userId) {
            self::seedPersonRow($userId);
        }

        return $this->asHolder(function () use ($fate): AccountOperation {
            $operation = AccountMergeOpening::open(self::SURVIVOR, self::USER_ID, new AccountMergeChoice(null, $fate, null, null));
            foreach ([[self::SURVIVOR, AccountMergeDuty::PrepareSurvivor], [self::USER_ID, AccountMergeDuty::PrepareLoser]] as [$user, $duty]) {
                $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
                    AccountMergeStage::Preparing->value, $duty->value);
                $result = $this->asPerson($user, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
                self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
            }
            self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

            return $operation;
        });
    }

    /**
     * @param AccountOperation $operation Prepared merge
     * @param AccountMergeDuty $duty Holder's own obligation
     * @return AccountOperationAssignment Canonical immutable address
     */
    private function obligation(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        return $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, $duty->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY),
            $duty === AccountMergeDuty::Sessions ? self::USER_ID : self::SURVIVOR,
            AccountOperationAssignmentPayload::fromJson('{}'),
        ));
    }

    /**
     * @param AccountOperation $operation Merge with two real preparations
     * @return AccountOperationResult Actual committed give
     */
    private function give(AccountOperation $operation): AccountOperationResult
    {
        $assignment = $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::USER_ID), self::USER_ID,
            AccountOperationAssignmentPayload::fromJson('{}'),
        ));

        return $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($assignment->id));
    }

    /**
     * @param AccountOperation $operation Merge whose give is committed
     * @return AccountOperationResult Genuine survivor receipt
     */
    private function receive(AccountOperation $operation): AccountOperationResult
    {
        $assignment = $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Receive->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::SURVIVOR), self::SURVIVOR,
            AccountOperationAssignmentPayload::fromJson('{}'),
        ));
        $give = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
            AccountMergeStage::Go->value, AccountMergeDuty::Give->value);
        $handover = $this->asPerson(self::USER_ID, static fn () => AccountMergeGiving::handover($give->id, self::USER_ID));

        return $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->receiveForMerge($assignment->id, $handover));
    }

    /** @param int $userId Person with a confirmed factor */
    private function factor(int $userId): void
    {
        self::seedPersonRow($userId);
        Database::sqlRun('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?)', [$userId, 'Authenticator', 'fixture-secret', -1, 0]);
    }

    /**
     * @param AccountOperationAssignment $assignment Holder's canonical immutable address
     * @return AccountOperationResult Prepared entry's committed result
     */
    private function execute(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->asHolder(fn () => $this->holder->applyPart($assignment->id));
    }

    /**
     * @param AccountOperationAskSignalData $ask Existing correlated request
     * @return AccountOperationAnswerSignalData Existing answer without a new transport
     */
    private function answer(AccountOperationAskSignalData $ask): AccountOperationAnswerSignalData
    {
        return $this->asHolder(fn () => AccountOperationAsks::answer(
            $ask, fn (AccountOperationAssignment $a) => $this->holder->applyPart($a->id), $this->holder->getId(),
        ));
    }

    /** @param list<AccountOperationAssignment> $assignments Ready duties given to the production inline queue */
    private function inlineAsk(array $assignments): void
    {
        $this->asHolder(fn () => (new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'askAccountOperation'))
            ->invoke($this->holder, $assignments));
    }

    /** Verifies no session/profile frame leaves while the result is still in its transaction. */
    private function observeCommit(): void
    {
        $this->drainQueue();
        SourceChangeBus::subscribe(new MergeSessionsCommitMirror(function (SourceChange $change): void {
            if ($change->sourceKey === HilosDbContext::accountOperationResults) {
                self::assertTrue(Database::hasOpenTransactions());
                self::assertSame([], $this->drainQueue());
            }
        }));
    }

    /**
     * @param AccountOperationAssignment $assignment Immutable duty
     * @param class-string<Throwable> $exception Expected failure family
     * @param ?string $words Optional safe refusal words
     * @param ?bool $survivor Optional forced entry for malformed duty keys
     */
    private function assertNoWork(
        AccountOperationAssignment $assignment, string $exception, ?string $words = null, ?bool $survivor = null,
    ): void
    {
        $sessions = self::allSessionRows();
        $logs = Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows();
        $results = Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();
        $this->drainQueue();
        try {
            if ($survivor === null) {
                $this->execute($assignment);
            } else {
                $this->asHolder(fn () => $survivor ? $this->holder->finishPart($assignment->id) : $this->holder->mergePart($assignment->id));
            }
            self::fail('The participant should refuse');
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure);
            if ($words !== null) {
                self::assertStringContainsString($words, $failure->getMessage());
            }
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($logs, Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows());
        self::assertSame($results, Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows());
        self::assertSame([], $this->drainQueue());
    }

    /**
     * @param AccountOperationAssignment $assignment Closed operation's completed duty
     * @param AccountOperationResult $result Original committed counts
     */
    private function assertSavedAfterNewBusiness(AccountOperationAssignment $assignment, AccountOperationResult $result): void
    {
        $this->signIn('19950000000000000000000000000009', self::USER_ID);
        $this->signIn('19960000000000000000000000000009', self::SURVIVOR);
        self::logAccess(self::USER_ID);
        $this->assertReplay($assignment, $result);
    }

    /**
     * @param AccountOperationAssignment $assignment Previously completed holder duty
     * @param AccountOperationResult $result Exact saved counts and owner
     */
    private function assertReplay(AccountOperationAssignment $assignment, AccountOperationResult $result): void
    {
        $sessions = self::allSessionRows();
        $log = Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows();
        $runtime = $this->runtimeSnapshot();
        $this->drainQueue();
        $again = $this->execute($assignment);
        self::assertSame($result->id, $again->id);
        self::assertSame($result->assignmentId, $again->assignmentId);
        self::assertSame($result->ownerAgentId, $again->ownerAgentId);
        self::assertSame($result->payloadJson, $again->payloadJson);
        self::assertSame($sessions, self::allSessionRows());
        self::assertSame($log, Database::sql('SELECT * FROM `hilos_access_log` ORDER BY `id`')->rows());
        self::assertSame($runtime, $this->runtimeSnapshot());
        self::assertSame([], $this->drainQueue());
    }

    /**
     * Gives a browser signed in as the person something under way in each of the four things a
     * sign-out clears and tells its tabs about: a toast, a profile window, a name change and a
     * send-progress line; runs in the holder's frame, and drops the frames the writes queued.
     *
     * @param string $token Session token of a browser signed in as the person
     * @throws HilosException When a runtime row cannot be written
     */
    private function busyBrowser(string $token): void
    {
        $hash = ProtectedModeRuntime::hashSessionToken($token);
        $this->asHolder(static function () use ($hash, $token): void {
            Hilos::$rt->hilosSessionToastStacks->actions->raise(
                $hash,
                'merge-toast',
                'Your copy of the data is ready.',
                SessionToastSeverity::SUCCESS,
                'Data export',
                '/profile',
            );
            Hilos::$rt->hilosProfileFlows->actions->put(
                $hash,
                StepUpOperationKey::CHANGE_EMAIL,
                self::USER_ID,
                HilosProfileFlow::STEP_CURRENT_SENT,
                'person@example.test',
                null,
                self::FUTURE,
            );
            Hilos::$rt->hilosProfileNameChanges->actions->open(
                $hash,
                self::USER_ID,
                str_repeat('a', 32),
                "accept-{$token}",
                'New name',
                TimeHelper::nowMs(),
                null,
            );
            Hilos::$rt->hilosCodeSendAttempts->actions->start(
                $hash,
                'merge-ticket',
                HilosCodeSendAttempt::CHANNEL_EMAIL,
                StepUpOperationKey::CHANGE_EMAIL,
            );
        });
        $this->drainQueue();
    }

    /** @return array<string, mixed> Runtime the holder owns and a sign-out changes */
    private function runtimeSnapshot(): array
    {
        return $this->asHolder(static fn (): array => [
            Hilos::$rt->hilosSessionRotations->toArray(), Hilos::$rt->hilosSessionToastStacks->toArray(),
            Hilos::$rt->hilosProfileFlows->toArray(), Hilos::$rt->hilosProfileNameChanges->toArray(),
            Hilos::$rt->hilosCodeSendAttempts->toArray(),
        ]);
    }

    /** Restores a cold facade after direct malformed-fixture SQL. */
    private function freshContext(): void
    {
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
    }

    /** Recreates the participant and facade with production claims over the same committed rows. */
    private function coldHolder(): void
    {
        TruthSourceRegistry::unregisterAgent($this->holder->getId());
        RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
        $this->freshContext();
        $this->holder = new MergeSessionsTestHolder();
        OwnershipDeclaration::claimAll($this->holder);
    }

    /**
     * Runs one step in the holder's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the holder
     * @return T Whatever the step returns
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $step);
    }

    /**
     * Writes a session signed in as one person, or taken over by another, with one live tab.
     *
     * @param string $token Session token
     * @param int $userId Person the session acts as
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null for an ordinary session
     * @return int Id of the session row
     * @throws HilosException When the row cannot be written
     */
    private function signIn(string $token, int $userId, ?int $impersonatorUserId = null): int
    {
        self::seedSession($token, $userId, self::SESSION_CREATED_AT, null, $impersonatorUserId);

        return $this->openTab($token, $userId);
    }

    /**
     * @param string $token Guest session waiting for a factor
     * @param int $userId Person whose second factor is pending
     * @return int Session with one live guest tab
     */
    private function waitOnSecondFactor(string $token, int $userId): int
    {
        self::seedPersonRow($userId);
        self::seedSession($token, null, self::SESSION_CREATED_AT, null);
        Database::sqlRun('UPDATE `hilos_session` SET `pending_second_factor_user_id` = ?, `pending_second_factor_mode` = ?,'
            . ' `pending_second_factor_until` = ? WHERE `token` = ?',
            [$userId, SecondFactorPendingMode::VERIFY, self::FUTURE, $token]);

        return $this->openTab($token, null);
    }

    /**
     * @param string $token Guest session carrying a blocked card
     * @param int $userId Person named by that card
     * @return int Session with one live guest tab
     */
    private function blockedCard(string $token, int $userId): int
    {
        self::seedPersonRow($userId);
        self::seedSession($token, null, self::SESSION_CREATED_AT, null);
        Database::sqlRun('UPDATE `hilos_session` SET `blocked_user_id` = ?, `blocked_signed_in` = 1 WHERE `token` = ?', [$userId, $token]);

        return $this->openTab($token, null);
    }

    /**
     * @param string $token Session token
     * @param ?int $userId Person the tab is signed in as, or null for a guest
     * @return int Id of the session row the tab belongs to
     * @throws DatabaseException When the session cannot be read
     */
    private function openTab(string $token, ?int $userId): int
    {
        $sessionId = (int)Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` = ?', [$token])->rows()[0]['id'];
        $this->rt->addConnection(MergeSessionsTestConnection::create("accept-{$token}", $userId, $token, $sessionId));

        return $sessionId;
    }

    /**
     * @param int $userId Person whose row the case needs, written once
     * @throws DatabaseException When the insert fails
     */
    private static function seedPersonRow(int $userId): void
    {
        Database::sqlRun("INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (?, 'Fixture person')", [$userId]);
    }

    /**
     * @param int $userId Person
     * @throws DatabaseException When the insert fails
     */
    private static function logAccess(int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_access_log` (`user_id`, `event`, `ip_address`, `occurred_at`) VALUES (?, ?, ?, ?)',
            [$userId, AccessLogEvent::SIGN_IN->value, self::ADDRESS, TimeHelper::nowMs()],
        );
    }

    /**
     * @return list<SignalDTO> Every queued signal, in queue order; the queue is empty afterwards
     */
    private function drainQueue(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @return array<int, non-empty-list<SessionStateSignalData>> Session-state frames the queue holds, by session, in queue order
     */
    private function framesBySession(): array
    {
        $frames = [];
        foreach ($this->drainQueue() as $signal) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_SESSION_STATE
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof SessionStateSignalData
            ) {
                $frames[(int)$signal->data->data->sessionId][] = $signal->data->data;
            }
        }
        ksort($frames);

        return $frames;
    }

    /**
     * @param int $sessionId Session row
     * @return array<string, mixed> The whole row as stored
     * @throws DatabaseException When the query fails
     */
    private static function sessionRowById(int $sessionId): array
    {
        $rows = Database::sql('SELECT * FROM `hilos_session` WHERE `id` = ?', [$sessionId])->rows();
        self::assertCount(1, $rows, 'The session row stays');

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>> Every session row as stored, in id order
     * @throws DatabaseException When the query fails
     */
    private static function allSessionRows(): array
    {
        return Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows();
    }

    /**
     * @param int $userId Person
     * @return list<array<string, mixed>> The person's log rows as stored, in id order
     * @throws DatabaseException When the query fails
     */
    private static function accessLogOf(int $userId): array
    {
        return Database::sql('SELECT * FROM `hilos_access_log` WHERE `user_id` = ? ORDER BY `id`', [$userId])->rows();
    }

    /**
     * @param bool $down Drop in reverse dependency order
     * @throws DatabaseException When a stub statement fails
     */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }

}

/** Observes in-transaction mirrors without becoming a deferred reaction. */
final readonly class MergeSessionsCommitMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe In-transaction observation */
    public function __construct(private Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Mirrored mutation
     * @param SourceChangeProvenance $provenance Publisher metadata
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

/** The production holder with both prepared entry points exposed. */
final class MergeSessionsTestHolder extends AbstractSessionsLibraryAgent
{
    public const array OWNS_RT = [
        HilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        HilosProfileFlow::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        HilosProfileNameChange::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    public function onStop(): void
    {
    }

    /**
     * @param int $assignmentId Holder duty selected by its kind and key
     * @return AccountOperationResult Prepared dispatcher result
     */
    public function applyPart(int $assignmentId): AccountOperationResult
    {
        return $this->applyAccountSessionsPart($assignmentId);
    }

    /** @param int $assignmentId Exact prepared holder assignment */
    public function mergePart(int $assignmentId): AccountOperationResult
    {
        return $this->mergeAccountSessionsPart($assignmentId);
    }

    /** @param int $assignmentId Exact prepared survivor obligation */
    public function finishPart(int $assignmentId): AccountOperationResult
    {
        return $this->finishMergeSurvivorSessions($assignmentId);
    }
}

/** Auth surface for session notification projection. */
abstract class MergeSessionsTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    public const array AGENTS = [
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergeSessionsTestHolder::class],
    ];
}

/** Runtime holding the case's live tabs. */
final class MergeSessionsTestRtContext extends RtContext
{
    private MergeSessionsTestConnections $connections;

    public function configure(): void
    {
        $this->connections = MergeSessionsTestConnections::init();
        $this->_stateCollections[MergeSessionsTestConnections::RT_COLLECTION] = $this->connections;
    }

    /**
     * @param MergeSessionsTestConnection $connection Live tab to mount
     */
    public function addConnection(MergeSessionsTestConnection $connection): void
    {
        $this->connections->add($connection);
    }
}

/** Session-stage connection collection of the case. */
final class MergeSessionsTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'mergeSessionsTestConnections';
    public const string STATE_CLASS = MergeSessionsTestConnection::class;
}

/** Session-stage connection row adding no project fields. */
final class MergeSessionsTestConnection extends HilosSessionConnection
{
    /**
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Incoming field changes
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeEnd;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

require_once __DIR__ . '/AccountMergeProjectParticipantIntegrationTest.php';

/** Prepared end through actual holder claims, real owner commits and the original project expectation. */
final class AccountMergeEndIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int LOSER = 9;
    private const int SURVIVOR = 7;
    private const int PEER = 2;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private MergeEndHolder $holder;
    private AccountMergeProjectNoteKeeper $keeper;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private int $frameToken;
    /** @var array<string, mixed> Private copy used only for deliberate negative fixture corruption */
    private array $copyRow = [];

    /** Uses real commits; no rollback test root or synthetic success result is installed. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->stubs(true);
        $this->stubs(false);
        Database::sqlRun('CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->freshContext();
        MergeEndHilos::initBrowser();
        $this->holder = new MergeEndHolder();
        $this->keeper = new AccountMergeProjectNoteKeeper();
        OwnershipDeclaration::claimAll($this->holder);
        OwnershipDeclaration::claimAll($this->keeper);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->holder->getId()));
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser')");
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (?)', [self::LOSER]);
        $config = Database::getConnectionConfig(0);
        Database::configure(self::PEER, $config->host, $config->user, $config->password, $config->database,
            $config->port, $config->charset, $config->socket);
        Database::connect(self::PEER);
    }

    /** Restores facades and releases the same production claims the case acquired. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
            $this->stubs(true);
            Schema::reset();
        } finally {
            ExecutionContext::pop($this->frameToken);
            $this->releasePersonAgents();
            foreach ([$this->holder->getId(), $this->keeper->getId(), AccountMergeProjectStrayOwner::AGENT_TYPE] as $id) {
                TruthSourceRegistry::unregisterAgent($id);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($id));
            }
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{AccountMergeStage, AccountMergeDuty, bool}> Every fixed duty and each missing coordinate */
    public static function missingProofs(): iterable
    {
        foreach ([AccountMergeDuty::PrepareLoser, AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::Give,
            AccountMergeDuty::Receive, AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions, AccountMergeDuty::Cleanup] as $duty) {
            $stage = in_array($duty, [AccountMergeDuty::PrepareLoser, AccountMergeDuty::PrepareSurvivor], true)
                ? AccountMergeStage::Preparing : AccountMergeStage::Go;
            foreach ([false, true] as $missingAssignment) {
                yield $duty->value . ($missingAssignment ? '-assignment' : '-result') => [$stage, $duty, $missingAssignment];
            }
        }
    }

    /**
     * @param AccountMergeStage $stage Immutable duty stage
     * @param AccountMergeDuty $duty Required proof
     * @param bool $missingAssignment Whether its producer has not even recorded the assignment
     */
    #[DataProvider('missingProofs')]
    public function testEveryFixedDutyAndResultIsMandatory(AccountMergeStage $stage, AccountMergeDuty $duty, bool $missingAssignment): void
    {
        $operation = $this->complete();
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage->value, $duty->value);
        if ($missingAssignment) {
            Database::sqlRun('DELETE FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$assignment->id]);
        } else {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignment->id]);
        }
        $this->freshContext();
        $before = $this->operationRow($operation->id);
        self::assertFalse($this->holder->end($operation));
        self::assertSame($before, $this->operationRow($operation->id));
    }

    /** Standing wait, lifted wait without a result and a missing registered owner all keep the end open. */
    public function testWaitAndLiftedWaitNeedTheExpectedOwnersOwnResult(): void
    {
        AccountMergeProjectUnlistedHilos::initBrowser();
        $operation = $this->complete(project: false);
        self::assertCount(1, AccountOperationExpectedSet::of($operation->id)->waiting());
        self::assertFalse($this->holder->end($operation));
        MergeEndHilos::initBrowser();
        self::assertFalse($this->holder->end($operation));
        $set = AccountOperationExpectedSet::of($operation->id);
        self::assertSame([], $set->waiting());
        self::assertCount(1, $set->expected());
        self::assertSame(AccountMergeStage::Go->value, $set->expected()[0]->stage);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($set->expected()[0]->id));
        $this->project($set->expected()[0]);
        self::assertTrue($this->holder->end($operation));
    }

    /** A new owner from the end's reconcile is mandatory beside the unchanged original preparing expectation. */
    public function testEndReconcilesNewOwnersBeforeJudgingTheOriginalResults(): void
    {
        $operation = $this->complete();
        $original = AccountOperationExpectedSet::of($operation->id)->expected()[0];
        MergeEndExpandedHilos::initBrowser();
        self::assertFalse($this->holder->end($operation));
        $expected = AccountOperationExpectedSet::of($operation->id)->expected();
        self::assertCount(2, $expected);
        self::assertSame($original->id, $expected[0]->id);
        self::assertSame(AccountMergeStage::Preparing->value, $expected[0]->stage);
        self::assertSame(AccountMergeStage::Go->value, $expected[1]->stage);
        $owner = new AccountMergeProjectStrayOwner();
        OwnershipDeclaration::claimAll($owner);
        $result = ExecutionContext::run(new ExecutionFrame(agentId: $owner->getId()),
            static fn () => AccountMergeProjectStep::run($owner, $expected[1]->id));
        self::assertSame($expected[1]->id, $result->assignmentId);
        self::assertTrue($this->holder->end($operation));
    }

    /** Receipt permits cleanup and source stop before either holder duty or the original project part. */
    public function testCleanupStopsTheSourceWhileEndStillWaitsForOtherOwners(): void
    {
        $operation = $this->received();
        $give = $this->duty($operation, AccountMergeDuty::Give);
        $cleanup = $this->assignment($operation, AccountMergeDuty::Cleanup);
        $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge($cleanup->id));
        self::assertTrue($this->personAgent(self::LOSER)->shouldStop());
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
        self::assertFalse($this->holder->end($operation));
        self::assertNull($this->operationRow($operation->id)['finished_at']);
        $this->holderParts($operation);
        self::assertFalse($this->holder->end($operation));
        $this->project(AccountOperationExpectedSet::of($operation->id)->expected()[0]);
        self::assertTrue($this->holder->end($operation));
    }

    /** All real owner results and physical absence finish exactly once; a cold holder replays without participant work. */
    public function testFullOwnerAtOpenJourneyAndColdCompletedReplay(): void
    {
        $operation = $this->complete();
        $expectation = AccountOperationExpectedSet::of($operation->id)->expected()[0];
        self::assertSame(AccountMergeStage::Preparing->value, $expectation->stage);
        self::assertSame($expectation->id, Hilos::$db->accountOperationResults->byAssignment($expectation->id)->assignmentId);
        self::assertSame($this->keeper->getId(), Hilos::$db->accountOperationResults->byAssignment($expectation->id)->ownerAgentId);
        $facts = $this->facts();
        self::assertNotSame([], $facts['fences']);
        self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy`')->field('n'));
        self::assertTrue($this->holder->end($operation));
        $finished = $this->operationRow($operation->id);
        self::assertSame(AccountOperationOutcome::Completed->value, $finished['outcome']);
        self::assertNotNull($finished['finished_at']);
        $this->freshContext();
        $this->holder = new MergeEndHolder();
        self::assertTrue($this->holder->end($operation));
        self::assertSame($finished, $this->operationRow($operation->id));
        self::assertSame($facts, $this->facts());
        self::assertCount(1, $this->keeper->parts);
    }

    /** @return iterable<string, array{bool}> Cached absence and a pre-existing consistent snapshot */
    public static function copyViews(): iterable
    {
        yield 'missing mirror' => [false];
        yield 'old repeatable-read snapshot' => [true];
    }

    /** @param bool $oldSnapshot Whether the copy is restored after the end transaction established its snapshot */
    #[DataProvider('copyViews')]
    public function testCurrentCopyMetadataPreventsPrematureEnd(bool $oldSnapshot): void
    {
        $operation = $this->complete();
        $give = $this->duty($operation, AccountMergeDuty::Give);
        self::assertNull(Hilos::$db->accountMergeCopies[$give->id] ?? null);
        if ($oldSnapshot) {
            Database::sqlRun('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            Hilos::$db->beforeResults = function (): void {
                self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy`')->field('n'));
                $this->restoreCopy();
                self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy`')->field('n'));
            };
        } else {
            $this->restoreCopy();
        }
        self::assertFalse($this->holder->end($operation));
        self::assertNull($this->operationRow($operation->id)['finished_at']);
        self::assertTrue(Hilos::$db->accountMergeCopies->hasStored($give->id));
        Database::sql('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id], index: self::PEER);
        self::assertTrue($this->holder->end($operation));
    }

    /** @return iterable<string, array{bool}> Finish statement and commit failure windows */
    public static function endFailures(): iterable
    {
        yield 'finish' => [false];
        yield 'commit' => [true];
    }

    /** @param bool $commitFailure Whether the peer kills the connection after the terminal row changed */
    #[DataProvider('endFailures')]
    public function testFailedFinishRollsBackAndRetryDoesNotRepeatAnyParticipant(bool $commitFailure): void
    {
        $operation = $this->complete();
        $before = $this->operationRow($operation->id);
        $facts = $this->facts();
        $reaction = new MergeEndReaction();
        SourceChangeBus::subscribe($reaction);
        if ($commitFailure) {
            SourceChangeBus::subscribe(new MergeEndMirror(static function (SourceChange $change): void {
                if ($change->sourceKey === HilosDbContext::accountOperations && $change->mutationType === TableMutationType::Update
                    && ($change->row['finished_at'] ?? null) !== null) {
                    $connection = (int)Database::sql('SELECT CONNECTION_ID() AS id')->field('id');
                    Database::sql('KILL ?', [$connection], index: self::PEER);
                }
            }));
        } else {
            Database::sqlRun('CREATE TRIGGER `end_failure` BEFORE UPDATE ON `hilos_account_operation` FOR EACH ROW'
                . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'end storage failure'");
        }
        try {
            $this->refused(DatabaseException::class, fn () => $this->holder->end($operation));
            self::assertSame($before, $this->operationRow($operation->id));
            self::assertNull($operation->finishedAt);
            self::assertSame([], $reaction->seen);
            self::assertSame($facts, $this->facts());
        } finally {
            if (!$commitFailure) {
                Database::sqlRun('DROP TRIGGER `end_failure`');
            }
        }
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->freshContext();
        $this->holder = new MergeEndHolder();
        self::assertTrue($this->holder->end($operation));
        self::assertTrue($this->holder->end($operation));
        self::assertSame($facts, $this->facts());
        self::assertCount(1, $this->keeper->parts);
    }

    /** @return iterable<string, array{string}> Foreign and malformed durable proof coordinates */
    public static function malformedProofs(): iterable
    {
        foreach (['prepare-owner', 'prepare-result-owner', 'prepare-refused', 'give-owner', 'give-malformed',
            'receive-owner', 'receive-malformed', 'sessions-owner', 'survivor-access-log', 'cleanup-malformed',
            'project-owner', 'project-key', 'project-payload', 'project-user', 'project-result-owner', 'project-malformed'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** @param string $defect Deliberate corruption after the real positive owner steps */
    #[DataProvider('malformedProofs')]
    public function testForeignOrMalformedProofsNeverBecomeCompleted(string $defect): void
    {
        $operation = $this->complete();
        $project = AccountOperationExpectedSet::of($operation->id)->expected()[0];
        $duty = match (true) {
            str_starts_with($defect, 'prepare') => $this->duty($operation, AccountMergeDuty::PrepareLoser),
            str_starts_with($defect, 'give') => $this->duty($operation, AccountMergeDuty::Give),
            str_starts_with($defect, 'receive') => $this->duty($operation, AccountMergeDuty::Receive),
            $defect === 'sessions-owner' => $this->duty($operation, AccountMergeDuty::Sessions),
            $defect === 'survivor-access-log' => $this->duty($operation, AccountMergeDuty::SurvivorSessions),
            $defect === 'cleanup-malformed' => $this->duty($operation, AccountMergeDuty::Cleanup),
            default => $project,
        };
        if (in_array($defect, ['prepare-owner', 'give-owner', 'receive-owner', 'sessions-owner', 'project-owner'], true)) {
            Database::sqlRun("UPDATE `hilos_account_operation_assignment` SET `owner_type` = 'foreign' WHERE `id` = ?", [$duty->id]);
        } elseif ($defect === 'project-user') {
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?', [self::SURVIVOR, $duty->id]);
        } elseif ($defect === 'project-key') {
            Database::sqlRun("UPDATE `hilos_account_operation_assignment` SET `assignment_key` = 'expect:other' WHERE `id` = ?", [$duty->id]);
        } elseif ($defect === 'project-payload') {
            Database::sqlRun("UPDATE `hilos_account_operation_assignment` SET `payload_json` = '{}' WHERE `id` = ?", [$duty->id]);
        } elseif (str_ends_with($defect, 'result-owner')) {
            Database::sqlRun("UPDATE `hilos_account_operation_result` SET `owner_agent_id` = 'foreign' WHERE `assignment_id` = ?", [$duty->id]);
        } else {
            $payload = match ($defect) {
                'prepare-refused' => '{"prepare":"refused","refusal":"occupied"}',
                'survivor-access-log' => '{"sessions":0,"accessLog":1}',
                default => '{}',
            };
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?', [$payload, $duty->id]);
        }
        $this->freshContext();
        try {
            self::assertFalse($this->holder->end($operation));
        } catch (InvalidFormatException) {
        }
        self::assertNull($this->operationRow($operation->id)['finished_at']);
    }

    /** Other outcomes, cancelling, erasure and a foreign frame cannot borrow this holder's end. */
    public function testOtherOperationStatesAndForeignExecutorCannotComplete(): void
    {
        $operation = $this->complete();
        $this->refused(InvalidFormatException::class, static fn () => ExecutionContext::run(
            new ExecutionFrame(agentId: 'foreign-holder'), static fn () => AccountMergeEnd::advance($operation)));
        Database::transactionStart();
        try {
            $this->refused(LogicException::class, fn () => $this->holder->end($operation));
        } finally {
            Database::transactionRollback();
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Go->value, AccountMergeStage::Cancelling->value));
        self::assertFalse($this->holder->end($operation));
        self::assertTrue($operation->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted));
        self::assertFalse($this->holder->end($operation));
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(17, self::SURVIVOR,
            AccountOperationCircle::fromArray([self::SURVIVOR], self::SURVIVOR));
        $this->refused(InvalidFormatException::class, fn () => $this->holder->end($erasure));
        self::assertNull($this->operationRow($erasure->id)['finished_at']);
    }

    /**
     * @param bool $project Whether to execute the real project expectation
     * @return AccountOperation Open go merge with all real fixed owner results and, optionally, its project result
     */
    private function complete(bool $project = true): AccountOperation
    {
        $operation = $this->received();
        $this->holderParts($operation);
        if ($project) {
            $this->project(AccountOperationExpectedSet::of($operation->id)->expected()[0]);
        }
        $cleanup = $this->assignment($operation, AccountMergeDuty::Cleanup);
        $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge($cleanup->id));

        return $operation;
    }

    /** @return AccountOperation Real opening, preparations, give and receive with a registered owner at open */
    private function received(): AccountOperation
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR, self::LOSER, new AccountMergeChoice(null, null, null, null));
        foreach ([[self::LOSER, AccountMergeDuty::PrepareLoser], [self::SURVIVOR, AccountMergeDuty::PrepareSurvivor]] as [$user, $duty]) {
            $assignment = $this->duty($operation, $duty);
            $result = $this->asPerson($user, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $give = $this->assignment($operation, AccountMergeDuty::Give);
        $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($give->id));
        $this->copyRow = Database::sql('SELECT * FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id])->firstRow();
        $receive = $this->assignment($operation, AccountMergeDuty::Receive);
        $handover = $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER));
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->receiveForMerge($receive->id, $handover));

        return $operation;
    }

    /** @param AccountOperation $operation Merge with real give and receipt */
    private function holderParts(AccountOperation $operation): void
    {
        $sessions = $this->assignment($operation, AccountMergeDuty::Sessions);
        $survivor = $this->assignment($operation, AccountMergeDuty::SurvivorSessions);
        $this->holder->sessions($sessions->id);
        $this->holder->survivor($survivor->id);
    }

    /**
     * @param AccountOperationAssignment $assignment Original project expectation
     * @return AccountOperationResult Real owner's committed move
     */
    private function project(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->keeper->getId()),
            fn () => AccountMergeProjectStep::run($this->keeper, $assignment->id));
    }

    /**
     * @param AccountOperation $operation Merge receiving a future coordinator's immutable obligation
     * @param AccountMergeDuty $duty Required prepared duty
     * @return AccountOperationAssignment Canonical duty for the appropriate owner
     */
    private function assignment(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $userId = in_array($duty, [AccountMergeDuty::Receive, AccountMergeDuty::SurvivorSessions], true) ? self::SURVIVOR : self::LOSER;
        $owner = in_array($duty, [AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions], true)
            ? new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)
            : new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);

        return Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
            $duty->value, $owner, $userId, AccountOperationAssignmentPayload::fromJson('{}'));
    }

    /**
     * @param AccountOperation $operation Existing operation
     * @param AccountMergeDuty $duty Recorded canonical duty
     * @return AccountOperationAssignment Existing immutable address
     */
    private function duty(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $stage = in_array($duty, [AccountMergeDuty::PrepareLoser, AccountMergeDuty::PrepareSurvivor], true)
            ? AccountMergeStage::Preparing : AccountMergeStage::Go;

        return Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage->value, $duty->value);
    }

    /**
     * @param int $operationId Persisted operation observed through a separate connection
     * @return array<string, mixed> Committed operation row
     */
    private function operationRow(int $operationId): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operationId], index: self::PEER)->firstRow();
    }

    /** @return array<string, list<array<string, mixed>>> Nonsecret immutable facts retained through end and replay */
    private function facts(): array
    {
        return [
            'results' => Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`', index: self::PEER)->rows(),
            'fences' => Database::sql('SELECT * FROM `hilos_account_merge_fence` ORDER BY `collection_key`, `row_id`', index: self::PEER)->rows(),
        ];
    }

    /** Restores a held private copy solely to test the negative no-copy gate. */
    private function restoreCopy(): void
    {
        Database::sql('INSERT INTO `hilos_account_merge_copy` (`assignment_id`, `owner_agent_id`, `created_at`, `payload_json`) VALUES (?, ?, ?, ?)',
            [$this->copyRow['assignment_id'], $this->copyRow['owner_agent_id'], $this->copyRow['created_at'], $this->copyRow['payload_json']],
            index: self::PEER);
    }

    /** Mounts a cold framework context beside the same project collection. */
    private function freshContext(): void
    {
        Hilos::$db = new MergeEndContext();
        Hilos::$db->configure();
    }

    /**
     * @param class-string<Throwable> $exception Expected refusal
     * @param callable $work Invalid call
     */
    private function refused(string $exception, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());

            return;
        }
        self::fail('An invalid end was accepted');
    }

    /** @param bool $down Whether to remove the extra tables */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Thin access to prepared holder methods; this fixture has no runtime writes. */
final class MergeEndHolder extends AbstractSessionsLibraryAgent
{
    /**
     * @param AccountOperation $operation Merge to consider
     * @return bool Whether the prepared end confirms completion
     */
    public function end(AccountOperation $operation): bool
    {
        return $this->advanceMergeEnd($operation);
    }

    /**
     * @param int $assignmentId Canonical loser sessions duty
     * @return AccountOperationResult Real holder result
     */
    public function sessions(int $assignmentId): AccountOperationResult
    {
        return $this->mergeAccountSessionsPart($assignmentId);
    }

    /**
     * @param int $assignmentId Canonical survivor sessions duty
     * @return AccountOperationResult Real holder result
     */
    public function survivor(int $assignmentId): AccountOperationResult
    {
        return $this->finishMergeSurvivorSessions($assignmentId);
    }
}

/** The real mounts, with one test hook before a transactional proof read. */
final class MergeEndContext extends HilosDbContext
{
    /** @var ?Closure(): void One-shot fixture interference inside the end transaction */
    public ?Closure $beforeResults = null;

    /** Mounts the project notes beside framework carriers. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountMergeProjectNotes::class, Objects::LAZY_STRATEGY_KEY);
    }

    /**
     * @param string $name Requested framework collection
     * @return mixed Normal collection after the optional fixture interference
     */
    public function __get(string $name): mixed
    {
        if ($name === self::accountOperationResults && Database::hasOpenTransactions() && $this->beforeResults !== null) {
            $work = $this->beforeResults;
            $this->beforeResults = null;
            $work();
        }

        return parent::__get($name);
    }
}

/** Registered owner at opening, with the framework holder unchanged. */
abstract class MergeEndHilos extends AccountMergeProjectHilos
{
}

/** A second owner becomes registered while the first owner's original expectation remains. */
abstract class MergeEndExpandedHilos extends MergeEndHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = MergeEndExpandedDirectory::class;
    public const array AGENTS = [
        ...parent::AGENTS,
        AccountMergeProjectStrayOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => AccountMergeProjectStrayOwner::class],
    ];
}

/** The second project family has no rows; its owner's real zero result is still required. */
abstract class MergeEndExpandedDirectory extends AccountMergeProjectDirectory
{
    /** @return list<AccountDataOwner> Original owner and the newly activated family */
    protected static function owners(): array
    {
        return [...parent::owners(), new AccountDataOwner('extra', [AccountMergeProjectNotes::COLLECTION_KEY],
            AccountMergeProjectStrayOwner::AGENT_TYPE, false, static fn (int $userId): bool => false)];
    }
}

/** Interference at the real terminal ORM update. */
final class MergeEndMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe Test's terminal write interference */
    public function __construct(private readonly Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Current ORM mutation
     * @param SourceChangeProvenance $provenance Local write origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

/** Records only reactions released from the terminal transaction. */
final class MergeEndReaction implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Committed operation changes */
    public array $seen = [];

    /**
     * @param SourceChange $change Released ORM mutation
     * @param SourceChangeProvenance $provenance Local write origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::accountOperations) {
            $this->seen[] = $change;
        }
    }
}

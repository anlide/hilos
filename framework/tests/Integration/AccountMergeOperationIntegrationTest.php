<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Real commits, rollback and reconnect exercise durability without a synthetic rollback root. */
final class AccountMergeOperationIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result'];
    private const string COORDINATOR_ID = 'merge-storage-coordinator';
    private const string FOREIGN_ID = 'merge-storage-foreign';
    private const int SURVIVOR_ID = 7;
    private const int LOSER_ID = 9;
    private const int PEER_INDEX = 2;

    private ?HilosDbContext $previousDb;
    private int $frameToken;
    private AccountMergeStoragePerson $loser;
    private AccountMergeStoragePerson $survivor;

    /** Mounts all three real carriers and claims coordinator and participant rights separately. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->stubs(down: true);
        $this->stubs(down: false);
        $this->mountFreshContext();
        OwnershipDeclaration::claimDb(AccountMergeStorageCoordinator::class, self::COORDINATOR_ID);
        $this->loser = new AccountMergeStoragePerson(self::LOSER_ID);
        $this->survivor = new AccountMergeStoragePerson(self::SURVIVOR_ID);
        OwnershipDeclaration::claimAll($this->loser);
        OwnershipDeclaration::claimAll($this->survivor);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
    }

    /** Ends transactions before DDL, releases claims and returns the previous context. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(down: true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            foreach ([self::COORDINATOR_ID, $this->loser->getId(), $this->survivor->getId()] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /** Failure on the second obligation rolls back the first and the operation; success survives reconnect. */
    public function testAtomicOpeningRollsBackOnFailureAndCommitsAcrossReconnect(): void
    {
        Database::sqlRun('ALTER TABLE `hilos_account_operation_assignment` ADD UNIQUE KEY `test_one_assignment` (`operation_id`)');
        try {
            $this->assertRefused(DuplicateEntryException::class, static fn () => AccountMergeOpening::open(
                self::SURVIVOR_ID, self::LOSER_ID, self::choice(),
            ));
            self::assertFalse(Database::hasOpenTransactions());
            foreach (self::TABLES as $table) {
                self::assertSame([], Database::sql('SELECT * FROM `' . $table . '`')->rows());
            }
            self::assertNull(Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID));
            self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperationStage(1, AccountMergeStage::Preparing->value));
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_assignment` DROP KEY `test_one_assignment`');
        }
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertFalse(Database::hasOpenTransactions());
        $stored = $this->snapshot();
        self::assertCount(1, $stored[self::TABLES[0]]);
        self::assertCount(2, $stored[self::TABLES[1]]);
        $connection = Database::sql('SELECT CONNECTION_ID()')->rows();
        Database::close();
        Database::connect();
        self::assertNotSame($connection, Database::sql('SELECT CONNECTION_ID()')->rows());
        $this->mountFreshContext();
        $reopened = Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID);
        self::assertSame($operation->id, $reopened->id);
        self::assertNotSame($operation->getObject(), $reopened->getObject());
        self::assertSame(AccountOperationKind::Merge, $reopened->kind);
        self::assertNull($reopened->requestId);
        self::assertSame(self::SURVIVOR_ID, $reopened->survivorUserId);
        self::assertSame(self::LOSER_ID, $reopened->loserUserId);
        self::assertSame([self::LOSER_ID, self::SURVIVOR_ID], $reopened->circle->toArray());
        self::assertTrue(self::choice()->equals($reopened->mergeChoice));
        self::assertSame(AccountMergeStage::Preparing->value, $reopened->stage);
        self::assertNull($reopened->finishedAt);
        self::assertNull($reopened->outcome);
        self::assertNull($reopened->mergeFact());
        self::assertSame($operation->toArray(), $reopened->toArray());
        foreach ([AccountMergeDuty::PrepareSurvivor->value => self::SURVIVOR_ID, AccountMergeDuty::PrepareLoser->value => self::LOSER_ID]
            as $duty => $userId) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($reopened->id, AccountMergeStage::Preparing->value, $duty);
            self::assertNotNull($assignment);
            self::assertSame($userId, $assignment->userId);
            self::assertSame(HilosAgentType::HILOS_USER, $assignment->ownerType);
            self::assertSame((string)$userId, $assignment->ownerIndex);
            self::assertSame(HilosAgentType::HILOS_USER . ':' . $userId, $assignment->owner->agentId());
            self::assertSame('{}', $assignment->payloadJson);
        }
        self::assertSame($stored, $this->snapshot());
    }

    /** Both initial records repeat even after the operation has advanced beyond preparing. */
    public function testExactOpeningRepeatKeepsOperationAndAssignmentIdsAndAllStoredValues(): void
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        foreach ([false, true] as $advanced) {
            if ($advanced) {
                self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
            }
            $before = $this->snapshot();
            $repeat = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, AccountMergeChoice::fromJson(self::choice()->toJson()));
            self::assertSame($operation->id, $repeat->id);
            self::assertSame($operation->getObject(), $repeat->getObject());
            self::assertSame($before, $this->snapshot());
        }
    }

    /** Every fate, protection flag and survivor belongs to the immutable opening. */
    public function testConflictingChoiceOrSurvivorRefusesWithoutChangingTheOperation(): void
    {
        AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $before = $this->snapshot();
        foreach ([
            new AccountMergeChoice(PasswordFate::LOSER, SecondFactorFate::SURVIVOR, true, false),
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::BOTH, true, false),
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, false, false),
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, true),
            new AccountMergeChoice(null, null, null, null),
        ] as $choice) {
            $this->assertRefused(ValidationException::class, static fn () => AccountMergeOpening::open(
                self::SURVIVOR_ID, self::LOSER_ID, $choice,
            ));
            self::assertSame($before, $this->snapshot());
        }
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeOpening::open(11, self::LOSER_ID, self::choice()));
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{int, int}> Malformed account pairs */
    public static function invalidPairs(): iterable
    {
        yield 'zero survivor' => [0, self::LOSER_ID];
        yield 'negative survivor' => [-1, self::LOSER_ID];
        yield 'zero loser' => [self::SURVIVOR_ID, 0];
        yield 'negative loser' => [self::SURVIVOR_ID, -1];
        yield 'same account' => [self::SURVIVOR_ID, self::SURVIVOR_ID];
    }

    /**
     * @param int $survivorUserId Supplied survivor
     * @param int $loserUserId Supplied loser
     */
    #[DataProvider('invalidPairs')]
    public function testMalformedPairIsRefusedBeforeStorage(int $survivorUserId, int $loserUserId): void
    {
        $this->assertRefused(ValidationException::class, static fn () => AccountMergeOpening::open(
            $survivorUserId, $loserUserId, self::choice(),
        ));
        foreach ($this->snapshot() as $rows) {
            self::assertSame([], $rows);
        }
    }

    /** Storage accepts unnamed fates and nonexistent accounts; eligibility belongs to the future judge. */
    public function testStorageDoesNotJudgeEligibilityBeyondThePairShape(): void
    {
        $choice = new AccountMergeChoice(null, null, null, null);
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, $choice);
        self::assertTrue($choice->equals($operation->mergeChoice));
        self::assertCount(2, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, $operation->stage));
    }

    /** @return iterable<string, array{int, bool}> Equal and conflicting concurrent openings */
    public static function concurrentChoices(): iterable
    {
        yield 'equal choice' => [self::SURVIVOR_ID, true];
        yield 'different choice' => [self::SURVIVOR_ID, false];
        yield 'different survivor' => [11, true];
    }

    /**
     * UNIQUE observes the peer's commit after the caller acquired a consistent snapshot.
     *
     * @param int $survivorUserId Repeated survivor
     * @param bool $sameChoice Whether the repeated flags equal the winner's
     */
    #[DataProvider('concurrentChoices')]
    public function testCollisionReadsTheWinnerPastTheCallerSnapshot(int $survivorUserId, bool $sameChoice): void
    {
        $this->connectPeer();
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertNull(Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID));
            Database::sql(
                'INSERT INTO `hilos_account_operation`'
                . ' (`kind`, `root_user_id`, `loser_user_id`, `open_loser_user_id`, `merge_choice`, `circle_user_ids`,'
                . ' `stage`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [AccountOperationKind::Merge->value, self::SURVIVOR_ID, self::LOSER_ID, self::LOSER_ID,
                    self::choice()->toJson(), '[9,7]', AccountMergeStage::Preparing->value, 1234, 1234],
                index: self::PEER_INDEX,
            );
            $winner = Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows();
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            $choice = $sameChoice ? self::choice() : new AccountMergeChoice(null, null, null, null);
            $repeat = static fn () => Hilos::$db->accountOperations->actions->recordMerge($survivorUserId, self::LOSER_ID, $choice);
            if ($sameChoice && $survivorUserId === self::SURVIVOR_ID) {
                $operation = $repeat();
                self::assertSame((int)$winner[0]['id'], $operation->id);
                self::assertSame(1234, $operation->createdAt);
                self::assertTrue(self::choice()->equals($operation->mergeChoice));
                self::assertSame($operation->getObject(), $repeat()->getObject());
            } else {
                $this->assertRefused(ValidationException::class, $repeat);
            }
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            Database::transactionCommit();
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($winner, Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows());
    }

    /** Only the open-loser collision is recoverable; an unrelated unique violation reaches the caller. */
    public function testUnrelatedDuplicateEntryIsNotTreatedAsAnOpeningRepeat(): void
    {
        AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        Database::sqlRun('ALTER TABLE `hilos_account_operation` ADD UNIQUE KEY `test_survivor` (`root_user_id`)');
        try {
            $before = $this->snapshot();
            $this->assertRefused(DuplicateEntryException::class, static fn () => AccountMergeOpening::open(
                self::SURVIVOR_ID, 11, self::choice(),
            ));
            self::assertSame($before, $this->snapshot());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation` DROP KEY `test_survivor`');
        }
    }

    /** The existing CAS frees the open slot, while history and immutable choices stay on the closed row. */
    public function testFinishNotStartedReleasesOnlyTheOpenSlotAndAllowsANewOperation(): void
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $before = $this->snapshot();
        self::assertFalse($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::NotStarted));
        self::assertSame($before, $this->snapshot());
        self::assertTrue($operation->actions->finish(AccountMergeStage::Preparing->value, AccountOperationOutcome::NotStarted));
        self::assertNull(Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID));
        $closed = Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->rows();
        self::assertNull($closed[0]['open_loser_user_id']);
        self::assertSame(self::LOSER_ID, (int)$closed[0]['loser_user_id']);
        self::assertSame($before[self::TABLES[0]][0]['merge_choice'], $closed[0]['merge_choice']);
        self::assertSame(AccountOperationOutcome::NotStarted->value, $operation->outcome);
        self::assertNotNull($operation->finishedAt);
        $next = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertNotSame($operation->id, $next->id);
        self::assertSame($next->id, Hilos::$db->accountOperations->openMergeOfLoser(self::LOSER_ID)->id);
        self::assertSame($closed, Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->rows());
        self::assertCount(2, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
        self::assertCount(4, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
    }

    /** Both paged readers separate kinds and exclude warmed operations after their closing write. */
    public function testOpenReadersSeparateKindsAndPagePastClosedOperations(): void
    {
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::SURVIVOR_ID);
        $erasure = Hilos::$db->accountOperations->actions->recordErasure(
            101, self::SURVIVOR_ID, AccountOperationCircle::fromArray([self::SURVIVOR_ID], self::SURVIVOR_ID),
        );
        $erasureAssignment = Hilos::$db->accountOperationAssignments->actions->record(
            $erasure->id, $erasure->stage, 'one', $owner, self::SURVIVOR_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );
        self::assertNull($erasure->mergeFact());
        $first = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $closed = AccountMergeOpening::open(self::SURVIVOR_ID, 11, self::choice());
        $last = AccountMergeOpening::open(self::SURVIVOR_ID, 13, self::choice());
        $firstAssignment = $this->initialAssignment($first, AccountMergeDuty::PrepareSurvivor);
        $lastAssignment = $this->initialAssignment($last, AccountMergeDuty::PrepareSurvivor);
        self::assertCount(3, Hilos::$db->accountOperations->openPage(AccountOperationKind::Merge, 0, 10));
        self::assertCount(3, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Merge, $owner, 0, 10));
        self::assertTrue($closed->actions->finish($closed->stage, AccountOperationOutcome::NotStarted));
        self::assertSame([$erasure->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 10),
        )));
        self::assertSame([$first->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperations->openPage(AccountOperationKind::Merge, 0, 1),
        )));
        self::assertSame([$last->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperations->openPage(AccountOperationKind::Merge, $first->id, 1),
        )));
        self::assertCount(0, Hilos::$db->accountOperations->openPage(AccountOperationKind::Merge, $last->id, 1));
        self::assertSame([$erasureAssignment->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 10),
        )));
        self::assertSame([$firstAssignment->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Merge, $owner, 0, 1),
        )));
        self::assertSame([$lastAssignment->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Merge, $owner, $firstAssignment->id, 1),
        )));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Merge, $owner, $lastAssignment->id, 1,
        ));
        self::assertTrue($erasure->actions->finish($erasure->stage, AccountOperationOutcome::Completed));
        self::assertCount(0, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 10));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 10));
    }

    /** Cancellation persists on the operation; a merge fact exists only as the loser's give result. */
    public function testCancellationAndParticipantFactUseExistingStageAndResultCarriers(): void
    {
        $canceled = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertTrue($canceled->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Cancelling->value));
        self::assertTrue($canceled->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted));
        self::assertSame(AccountMergeStage::Cancelling->value, $canceled->stage);
        self::assertSame(AccountOperationOutcome::NotStarted->value, $canceled->outcome);
        self::assertNull($canceled->mergeFact());
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        self::assertNull($operation->mergeFact());
        $preparation = $this->initialAssignment($operation, AccountMergeDuty::PrepareLoser);
        $this->asParticipant($this->loser, static fn () => AccountOperationParticipantStep::run(
            $preparation->id, static fn () => AccountOperationResultPayload::fromJson('{"prepared":true}'),
        ));
        self::assertNull($operation->mergeFact());
        $give = $this->giveAssignment($operation);
        self::assertNull($operation->mergeFact());
        $fact = $this->asParticipant($this->loser, static fn () => AccountOperationParticipantStep::run(
            $give->id, static fn () => AccountOperationResultPayload::fromJson('{"transferred":true}'),
        ));
        self::assertSame($fact->id, $operation->mergeFact()->id);
        self::assertSame($give->id, $operation->mergeFact()->assignmentId);
        self::assertSame($this->loser->getId(), $fact->ownerAgentId);
        self::assertSame($fact->id, (int)Database::sql(
            'SELECT `id` FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$give->id],
        )->rows()[0]['id']);
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        Database::close();
        Database::connect();
        $this->mountFreshContext();
        self::assertSame($fact->toArray(), Hilos::$db->accountOperations[$operation->id]->mergeFact()->toArray());
        self::assertSame(AccountOperationOutcome::Completed->value, Hilos::$db->accountOperations[$operation->id]->outcome);
    }

    /** Guards apply before repeat lookup; the coordinator never acquires a participant's result right. */
    public function testCoordinatorParticipantsAndForeignFramesHaveSeparateWriteRights(): void
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
        $preparation = $this->initialAssignment($operation, AccountMergeDuty::PrepareLoser);
        $before = $this->snapshot();
        foreach ([self::FOREIGN_ID, $this->loser->getId()] as $agentId) {
            ExecutionContext::run(new ExecutionFrame(agentId: $agentId), function () use ($operation, $preparation): void {
                foreach ([self::LOSER_ID, 11] as $loserUserId) {
                    $this->assertRefused(CreateNotAllowedException::class, static fn () => Hilos::$db->accountOperations->actions->recordMerge(
                        self::SURVIVOR_ID, $loserUserId, self::choice(),
                    ));
                }
                foreach ([$preparation->assignmentKey, 'foreign'] as $key) {
                    $this->assertRefused(CreateNotAllowedException::class, static fn () => Hilos::$db->accountOperationAssignments->actions->record(
                        $operation->id, $operation->stage, $key, $preparation->owner, self::LOSER_ID,
                        AccountOperationAssignmentPayload::fromJson('{}'),
                    ));
                }
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $operation->actions->advance($operation->stage, AccountMergeStage::Go->value));
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $operation->actions->finish($operation->stage, AccountOperationOutcome::NotStarted));
            });
        }
        foreach ([self::COORDINATOR_ID, $this->survivor->getId()] as $agentId) {
            ExecutionContext::run(new ExecutionFrame(agentId: $agentId), function () use ($preparation): void {
                $this->assertRefused(CreateNotAllowedException::class, static fn () => Database::transaction(
                    DatabaseNames::PRIMARY, static fn () => Hilos::$db->accountOperationResults->actions->record(
                        $preparation, AccountOperationResultPayload::fromJson('{}'),
                    ),
                ));
            });
        }
        self::assertSame($before, $this->snapshot());
        $this->assertRefused(ValidationException::class, static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, 'outside-circle', $preparation->owner, 11, AccountOperationAssignmentPayload::fromJson('{}'),
        ));
        self::assertSame($before, $this->snapshot());
    }

    /** Real PII gates and anonymization purge populated merge carriers and preserve unrelated data. */
    public function testAnonymizationPurgesFilledMergeCarriersAndKeepsUnrelatedData(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_i18n_reflow`');
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_i18n_reflow.sql'));
        try {
            Database::sqlRun('INSERT INTO `hilos_i18n_reflow` (`id`, `fingerprint`) VALUES (1, ?)', [str_repeat('a', 64)]);
            $neighbour = Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows();
            foreach ([true, false] as $closed) {
                $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, self::choice());
                $give = $this->giveAssignment($operation);
                $this->asParticipant($this->loser, static fn () => AccountOperationParticipantStep::run(
                    $give->id, static fn () => AccountOperationResultPayload::fromJson('{"private":"transfer"}'),
                ));
                if ($closed) {
                    self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
                }
            }
            self::assertCount(2, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            self::assertCount(6, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            self::assertCount(2, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            $registry = PiiRegistry::collect();
            foreach (self::TABLES as $table) {
                self::assertTrue($registry->isPurged(0, $table));
            }
            $schemas = LiveSchemaReader::read(0);
            AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => $schemas]);
            $anonymizer = new CatalogRestoreAnonymizer($registry, new AnonymizationSqlBuilder(str_repeat('b', 64)));
            $anonymizer->validateArchive([0 => array_keys($schemas)]);
            $database = Database::getConnectionConfig(0)->database;
            $anonymizer->validateTargetSchema(0, $database);
            $anonymizer->anonymizeConnection(0, $database);
            foreach (self::TABLES as $table) {
                self::assertSame([], Database::sql('SELECT * FROM `' . $table . '`')->rows());
            }
            self::assertSame($neighbour, Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows());
        } finally {
            Database::sqlRun('DROP TABLE `hilos_i18n_reflow`');
        }
    }

    /** @return AccountMergeChoice Complete immutable merge choice */
    private static function choice(): AccountMergeChoice
    {
        return new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false);
    }

    /** Creates fresh Object and View instances without an ORM reset. */
    private function mountFreshContext(): void
    {
        Hilos::$db = new AccountMergeStorageContext();
        Hilos::$db->configure();
    }

    /** Connects an independent SQL session to the same physical schema. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
        self::assertNotSame(Database::sql('SELECT CONNECTION_ID()')->rows(),
            Database::sql('SELECT CONNECTION_ID()', index: self::PEER_INDEX)->rows());
        self::assertSame(Database::sql('SELECT DATABASE()')->rows(),
            Database::sql('SELECT DATABASE()', index: self::PEER_INDEX)->rows());
    }

    /**
     * @param AccountOperation $operation Merge with its initial obligations
     * @param AccountMergeDuty $duty Initial preparation duty
     * @return AccountOperationAssignment Persisted initial assignment
     */
    private function initialAssignment(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        self::assertNotNull($assignment);

        return $assignment;
    }

    /**
     * @param AccountOperation $operation Open preparing merge
     * @return AccountOperationAssignment Loser's give obligation at the go stage
     */
    private function giveAssignment(AccountOperation $operation): AccountOperationAssignment
    {
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

        return Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER_ID),
            self::LOSER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
        );
    }

    /**
     * @param AccountMergeStoragePerson $person Participant whose set grants apply
     * @param Closure $work Transactional participant action
     * @return mixed Action result
     */
    private function asParticipant(AccountMergeStoragePerson $person, Closure $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $person->getId()), $work);
    }

    /** @return array<string, list<array<string, mixed>>> SQL boundary snapshot in primary id order */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`')->rows();
        }

        return $snapshot;
    }

    /**
     * @param class-string<Throwable> $exception Expected refusal family
     * @param Closure $attempt Attempt that must throw
     */
    private function assertRefused(string $exception, Closure $attempt): void
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());
            return;
        }
        self::fail('Storage accepted forbidden or conflicting work');
    }

    /** @param bool $down Whether to drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

final class AccountMergeStorageContext extends HilosDbContext
{
}

final class AccountMergeStorageCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Each participant can create results only under its own durable agent address. */
final class AccountMergeStoragePerson extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments];

    /** @param int $userId Account represented by this participant */
    public function __construct(int $userId)
    {
        $this->agentIndex = (string)$userId;
    }

    /**
     * @param string $collection Declared result collection
     * @return string Participant's canonical durable address
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /** No background fixture work. */
    public function onStop(): void
    {
    }
}

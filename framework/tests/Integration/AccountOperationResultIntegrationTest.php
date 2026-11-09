<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
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
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Real participant commits need no synthetic rollback root. */
final class AccountOperationResultIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const array TABLES = ['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result'];
    private const int USER_ID = 7;
    private const int PEER_INDEX = 2;

    private AccountOperationResultPerson $person;
    private AccountOperationResultLibrary $library;
    private AccountOperationResultCoordinator $coordinator;

    /** Mounts the real schema and registers the two different participant classes. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->stubs(down: true);
        $this->stubs(down: false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Result fixture')", [self::USER_ID]);
        $this->person = new AccountOperationResultPerson(self::USER_ID);
        $this->library = new AccountOperationResultLibrary();
        $this->coordinator = new AccountOperationResultCoordinator();
        OwnershipDeclaration::claimAll($this->person);
        OwnershipDeclaration::claimAll($this->library);
        OwnershipDeclaration::claimDb($this->coordinator::class, $this->coordinator->getId());
    }

    /** Drops children first and releases every declaration before the base removes people. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(down: true);
        } finally {
            foreach ([$this->person, $this->library, $this->coordinator] as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            parent::tearDown();
        }
    }

    /** A committed result and its rename survive an independent SQL read and a fresh context. */
    public function testBusinessWorkAndResultCommitTogetherAndCanBeReadFromFreshObjects(): void
    {
        $assignment = $this->assignment();
        $payload = AccountOperationResultPayload::fromJson('{"renamed":true}');
        $record = $this->asAgent($this->person, function () use ($assignment, $payload): AccountOperationResult {
            return AccountOperationParticipantStep::run($assignment->id, function (AccountOperationAssignment $assigned) use ($assignment, $payload) {
                self::assertSame($assignment->id, $assigned->id);
                Hilos::$db->userRenames->actions->add(self::USER_ID, self::USER_ID, 'Before', 'After');
                return $payload;
            });
        });
        self::assertFalse(Database::hasOpenTransactions());
        $work = Database::sql('SELECT * FROM `hilos_user_rename`')->rows();
        $records = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        self::assertCount(1, $work);
        self::assertSame('After', $work[0]['new_name']);
        self::assertCount(1, $records);
        self::assertSame($assignment->id, (int)$records[0]['assignment_id']);
        self::assertSame($this->person->getId(), $records[0]['owner_agent_id']);
        self::assertSame($payload->toJson(), $records[0]['payload_json']);
        self::assertSame($record->createdAt, (int)$records[0]['created_at']);
        $saved = $record->toArray();
        Database::close();
        Database::connect();
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->asAgent($this->person, function () use ($record, $assignment, $saved): void {
            self::assertSame($saved, Hilos::$db->accountOperationResults[$record->id]->toArray());
            self::assertNotSame($record->getObject(), Hilos::$db->accountOperationResults[$record->id]->getObject());
            self::assertSame($record->id, Hilos::$db->accountOperationResults->byAssignment($assignment->id)->id);
            self::assertSame($assignment->id, Hilos::$db->accountOperationResults[$record->id]->assignment->id);
            self::assertCount(1, Hilos::$db->userRenames->byUser(self::USER_ID));
        });
    }

    /** A work failure retains its identity, rolls everything back, and permits one successful retry. */
    public function testWorkFailureRollsBackAndRetryCommitsOnce(): void
    {
        $assignment = $this->assignment();
        $failure = new RuntimeException('Participant work failed after its write');
        try {
            $this->asAgent($this->person, static fn () => AccountOperationParticipantStep::run($assignment->id, static function () use ($failure) {
                Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Failed');
                throw $failure;
            }));
            self::fail('Failed work returned a result');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        $this->asAgent($this->person, static function () use ($assignment): void {
            self::assertCount(0, Hilos::$db->userRenames->byUser(self::USER_ID));
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
            $calls = 0;
            $work = static function () use (&$calls): AccountOperationResultPayload {
                ++$calls;
                Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Retried');
                return AccountOperationResultPayload::fromJson('{"retried":true}');
            };
            $record = AccountOperationParticipantStep::run($assignment->id, $work);
            $repeat = AccountOperationParticipantStep::run($assignment->id, $work);
            self::assertSame($record->id, $repeat->id);
            self::assertSame($record->payloadJson, $repeat->payloadJson);
            self::assertSame(1, $calls);
        });
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** @return iterable<string, array{bool, bool}> Stage movement or finish, with and without a saved result */
    public static function closedStages(): iterable
    {
        yield 'advanced without result' => [false, false];
        yield 'advanced with result' => [false, true];
        yield 'finished without result' => [true, false];
        yield 'finished with result' => [true, true];
    }

    /**
     * @param bool $finish Finish rather than advance the operation
     * @param bool $saved Whether the participant already committed its result
     */
    #[DataProvider('closedStages')]
    public function testSavedResultPrecedesTheClosedStageRefusal(bool $finish, bool $saved): void
    {
        $assignment = $this->assignment();
        $record = $saved ? $this->record($this->person, $assignment) : null;
        $this->asAgent($this->coordinator, static function () use ($assignment, $finish): void {
            if ($finish) {
                self::assertTrue($assignment->operation->actions->finish($assignment->stage, AccountOperationOutcome::Completed));
            } else {
                self::assertTrue($assignment->operation->actions->advance($assignment->stage, 'next'));
            }
        });
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        try {
            $repeat = $this->asAgent($this->person, static fn () => AccountOperationParticipantStep::run($assignment->id, static function () {
                self::fail('Closed-stage work was called');
            }));
            self::assertNotNull($record);
            self::assertSame($record->id, $repeat->id);
            self::assertSame($record->payloadJson, $repeat->payloadJson);
        } catch (AccountOperationStepRefusedException $failure) {
            self::assertFalse($saved);
            self::assertSame(AccountOperationStepRefusal::ClosedStage, $failure->reason);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        self::assertCount($saved ? 1 : 0, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** No participant is checked before looking up an assignment; neither refusal calls the work. */
    public function testMissingParticipantAndUnknownAssignmentRefuseBeforeWork(): void
    {
        $work = static function () {
            self::fail('Refused work was called');
        };
        foreach ([
            [null, AccountOperationStepRefusal::NoParticipant],
            [$this->person->getId(), AccountOperationStepRefusal::UnknownAssignment],
        ] as [$agentId, $reason]) {
            try {
                ExecutionContext::run(new ExecutionFrame(agentId: $agentId),
                    static fn () => AccountOperationParticipantStep::run(PHP_INT_MAX, $work));
                self::fail('A step without its participant or assignment was accepted');
            } catch (AccountOperationStepRefusedException $failure) {
                self::assertSame($reason, $failure->reason);
            }
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** Transaction admission precedes even the create-right door. */
    public function testRecordOutsideTransactionRefusesWithoutWriting(): void
    {
        $assignment = $this->assignment();
        try {
            $this->asAgent($this->person, static fn () => Hilos::$db->accountOperationResults->actions->record(
                $assignment, AccountOperationResultPayload::fromJson('{}'),
            ));
            self::fail('A result was recorded without a participant transaction');
        } catch (DatabaseException $failure) {
            self::assertSame('An account operation result must be recorded inside its participant transaction', $failure->getMessage());
        }
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** An equal repeat returns its original id; a conflict cannot overwrite any saved value. */
    public function testRecordReturnsSemanticRepeatAndRejectsConflictingPayload(): void
    {
        $assignment = $this->assignment();
        $record = $this->record($this->person, $assignment, '{"a":1,"b":2}');
        $before = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        $repeat = $this->record($this->person, $assignment, '{"b":2.0,"a":1e0}');
        self::assertSame($record->id, $repeat->id);
        self::assertSame($record->getObject(), $repeat->getObject());
        try {
            $this->record($this->person, $assignment, '{"a":2,"b":2}');
            self::fail('A conflicting result was accepted');
        } catch (ValidationException) {
            self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        }
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{bool}> New and saved-result guard paths */
    public static function guardedRecords(): iterable
    {
        yield 'new record' => [false];
        yield 'equal repeat' => [true];
    }

    /** @param bool $saved Whether the foreign caller repeats an already stored result */
    #[DataProvider('guardedRecords')]
    public function testOtherParticipantAndCoordinatorCannotRecordIntoThePersonalSet(bool $saved): void
    {
        $assignment = $this->assignment();
        if ($saved) {
            $this->record($this->person, $assignment);
        }
        $before = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        foreach ([$this->library, $this->coordinator] as $foreign) {
            try {
                $this->record($foreign, $assignment);
                self::fail('A foreign agent recorded a personal result');
            } catch (CreateNotAllowedException) {
                self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            }
            try {
                $this->asAgent($foreign, static fn () => AccountOperationParticipantStep::run($assignment->id, static function () {
                    self::fail('A foreign participant ran the business work');
                }));
                self::fail('A foreign participant received the saved result');
            } catch (AccountOperationStepRefusedException $failure) {
                self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
                self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            }
        }
        $libraryAssignment = $this->assignment('library', owner: $this->library);
        self::assertSame($this->library->getId(), $this->record($this->library, $libraryAssignment)->ownerAgentId);
        try {
            $this->record($this->person, $libraryAssignment);
            self::fail('The person recorded a singleton result');
        } catch (CreateNotAllowedException) {
            self::assertTrue(true);
        }
    }

    /** Readers filter by both coordinates and order results by their own ids, after a cold mount too. */
    public function testReadersSelectTheOperationAndLiteralStageInResultIdOrder(): void
    {
        $firstAssignment = $this->assignment('z');
        $secondAssignment = $this->assignment('a', owner: $this->library);
        $otherOperation = $this->assignment('other-operation', requestId: 102);
        $nextStage = $this->assignment('next-stage', stage: 'next');
        $differentCase = $this->assignment('case', requestId: 103, stage: 'Preparing');
        $trailingSpace = $this->assignment('space', requestId: 104, stage: 'preparing ');
        $firstResult = $this->record($this->library, $secondAssignment);
        $secondResult = $this->record($this->person, $firstAssignment);
        foreach ([$otherOperation, $nextStage, $differentCase, $trailingSpace] as $assignment) {
            $this->record($this->person, $assignment);
        }
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->asAgent($this->person, function () use ($firstAssignment, $secondAssignment, $firstResult, $secondResult): void {
            self::assertSame([$firstResult->id, $secondResult->id], array_keys(iterator_to_array(
                Hilos::$db->accountOperationResults->forOperationStage($firstAssignment->operationId, $firstAssignment->stage),
            )));
            self::assertSame($secondAssignment->id, Hilos::$db->accountOperationResults[$firstResult->id]->assignment->id);
            self::assertSame($firstResult->id, Hilos::$db->accountOperationResults->byAssignment($secondAssignment->id)->id);
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment(PHP_INT_MAX));
            self::assertArrayNotHasKey(PHP_INT_MAX, Hilos::$db->accountOperationResults);
            self::assertNull(Hilos::$db->accountOperationResults[PHP_INT_MAX] ?? null);
            self::assertCount(0, Hilos::$db->accountOperationResults->forOperationStage(PHP_INT_MAX, $firstAssignment->stage));
            self::assertCount(0, Hilos::$db->accountOperationResults->forOperationStage($firstAssignment->operationId, 'absent'));
        });
    }

    /** A unique collision escapes record() and rolls back the work done under the stale snapshot. */
    public function testDuplicateInsertEscapesAndRollsBackParticipantWork(): void
    {
        $assignment = $this->assignment();
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->asAgent($this->person, function () use ($assignment): void {
            try {
                Database::transaction(DatabaseNames::PRIMARY, function () use ($assignment): void {
                    self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
                    Database::sql(
                        'INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
                        . ' VALUES (?, ?, ?, ?)',
                        [$assignment->id, $this->person->getId(), '{}', 1234],
                        index: self::PEER_INDEX,
                    );
                    Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'After');
                    Hilos::$db->accountOperationResults->actions->record($assignment, AccountOperationResultPayload::fromJson('{}'));
                    self::fail('The duplicate insert was swallowed inside record()');
                });
                self::fail('The participant transaction accepted a duplicate');
            } catch (DuplicateEntryException) {
                self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
                self::assertCount(0, Hilos::$db->userRenames->byUser(self::USER_ID));
                self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            }
        });
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** run() recovers only after the losing transaction's SQL and ORM work have rolled back. */
    public function testParticipantStepReturnsTheWinningResultAfterDuplicateRollback(): void
    {
        $assignment = $this->assignment();
        $this->connectPeer();
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $record = $this->asAgent($this->person, function () use ($assignment): AccountOperationResult {
            return AccountOperationParticipantStep::run($assignment->id, function () use ($assignment): AccountOperationResultPayload {
                self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
                $this->insertPeerResult($assignment);
                Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Losing attempt');
                return AccountOperationResultPayload::fromJson('{"loser":true}');
            });
        });
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame('{}', $record->payloadJson);
        self::assertSame(1234, $record->createdAt);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        $records = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        self::assertCount(1, $records);
        self::assertSame($record->id, (int)$records[0]['id']);
        $this->asAgent($this->person, static function () use ($assignment, $record): void {
            self::assertCount(0, Hilos::$db->userRenames->byUser(self::USER_ID));
            self::assertSame($record->id, Hilos::$db->accountOperationResults->byAssignment($assignment->id)->id);
        });
    }

    /** A visible equal peer result must roll back work even without an earlier primary snapshot. */
    public function testParticipantStepRollsBackWorkWhenAnEqualPeerResultIsAlreadyVisible(): void
    {
        $assignment = $this->assignment();
        $this->connectPeer();
        $record = $this->asAgent($this->person, function () use ($assignment): AccountOperationResult {
            return AccountOperationParticipantStep::run($assignment->id, function () use ($assignment): AccountOperationResultPayload {
                $this->insertPeerResult($assignment);
                Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Visible losing attempt');
                return AccountOperationResultPayload::fromJson('{}');
            });
        });
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame('{}', $record->payloadJson);
        self::assertSame(1234, $record->createdAt);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        $records = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        self::assertCount(1, $records);
        self::assertSame($record->id, (int)$records[0]['id']);
        $this->asAgent($this->person, static function () use ($assignment, $record): void {
            self::assertCount(0, Hilos::$db->userRenames->byUser(self::USER_ID));
            self::assertSame($record->id, Hilos::$db->accountOperationResults->byAssignment($assignment->id)->id);
        });
    }

    /** A visible conflicting peer result still refuses instead of returning a successful repeat. */
    public function testParticipantStepRejectsAVisibleConflictingPeerResultAndRollsBackWork(): void
    {
        $assignment = $this->assignment();
        $this->connectPeer();
        try {
            $this->asAgent($this->person, function () use ($assignment): AccountOperationResult {
                return AccountOperationParticipantStep::run($assignment->id, function () use ($assignment): AccountOperationResultPayload {
                    $this->insertPeerResult($assignment);
                    Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Conflicting losing attempt');
                    return AccountOperationResultPayload::fromJson('{"loser":true}');
                });
            });
            self::fail('A visible conflicting result was accepted');
        } catch (ValidationException $failure) {
            self::assertSame('This account operation assignment already has a different immutable result.', $failure->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        $records = Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows();
        self::assertCount(1, $records);
        self::assertSame('{}', $records[0]['payload_json']);
        $this->asAgent($this->person, static function (): void {
            self::assertCount(0, Hilos::$db->userRenames->byUser(self::USER_ID));
        });
    }

    /** An unrelated unique failure in work stays a failure even if another attempt has saved a result. */
    public function testWorkDuplicateIsNotMistakenForAResultCollision(): void
    {
        $assignment = $this->assignment();
        $this->connectPeer();
        $workFailure = null;
        try {
            $this->asAgent($this->person, function () use ($assignment, &$workFailure): AccountOperationResult {
                return AccountOperationParticipantStep::run($assignment->id, function () use ($assignment, &$workFailure) {
                    $this->insertPeerResult($assignment);
                    Hilos::$db->userRenames->actions->add(self::USER_ID, null, 'Before', 'Failed duplicate');
                    try {
                        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [self::USER_ID, 'Duplicate']);
                    } catch (DuplicateEntryException $failure) {
                        $workFailure = $failure;
                        throw $failure;
                    }
                    self::fail('The duplicate business insert succeeded');
                });
            });
            self::fail('A duplicate from participant work was swallowed');
        } catch (DuplicateEntryException $failure) {
            self::assertSame($workFailure, $failure);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_rename`')->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    /** The real coverage, compatibility and restore pass purge all carriers and preserve an unrelated row. */
    public function testAnonymizationPurgesResultsAndParentsAndPreservesUnrelatedData(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_i18n_reflow`');
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_i18n_reflow.sql'));
        try {
            Database::sqlRun('INSERT INTO `hilos_i18n_reflow` (`id`, `fingerprint`) VALUES (1, ?)', [str_repeat('a', 64)]);
            $neighbour = Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows();
            foreach ([111, 112] as $requestId) {
                $assignment = $this->assignment(requestId: $requestId);
                $this->record($this->person, $assignment, '{"private":"preparation"}');
                if ($requestId === 112) {
                    $this->asAgent($this->coordinator, static function () use ($assignment): void {
                        self::assertTrue($assignment->operation->actions->finish($assignment->stage, AccountOperationOutcome::Completed));
                    });
                }
            }
            foreach (self::TABLES as $table) {
                self::assertCount(2, Database::sql('SELECT * FROM `' . $table . '`')->rows());
            }
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

    /** Connects a separate SQL observer/writer without adding another process or clock wait. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
    }

    /** @param AccountOperationAssignment $assignment Result committed by the competing attempt */
    private function insertPeerResult(AccountOperationAssignment $assignment): void
    {
        Database::sql(
            'INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
            . ' VALUES (?, ?, ?, ?)',
            [$assignment->id, $this->person->getId(), '{}', 1234],
            index: self::PEER_INDEX,
        );
    }

    /**
     * @param string $key Immutable part identity
     * @param int $requestId Operation's durable request identity
     * @param string $stage Current stage for this assignment
     * @param ?AbstractAgent $owner Participant, defaulting to the personal owner
     * @return AccountOperationAssignment Real coordinator-created obligation
     */
    private function assignment(
        string $key = 'one',
        int $requestId = 101,
        string $stage = 'preparing',
        ?AbstractAgent $owner = null,
    ): AccountOperationAssignment {
        $owner ??= $this->person;
        return $this->asAgent($this->coordinator, static function () use ($key, $requestId, $stage, $owner): AccountOperationAssignment {
            $operation = Hilos::$db->accountOperations->actions->recordErasure(
                $requestId, self::USER_ID, AccountOperationCircle::fromArray([self::USER_ID], self::USER_ID),
            );
            if ($operation->stage !== $stage) {
                self::assertTrue($operation->actions->advance($operation->stage, $stage));
            }
            return Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $stage, $key, new AccountOperationOwnerAddress($owner->getType(), $owner->getIndex()),
                self::USER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
            );
        });
    }

    /**
     * @param AbstractAgent $owner Participant attempting the record
     * @param AccountOperationAssignment $assignment Immutable work assignment
     * @param string $json Result JSON
     * @return AccountOperationResult Committed record or exact repeat
     */
    private function record(AbstractAgent $owner, AccountOperationAssignment $assignment, string $json = '{}'): AccountOperationResult
    {
        return $this->asAgent($owner, static fn () => Database::transaction(DatabaseNames::PRIMARY,
            static fn () => Hilos::$db->accountOperationResults->actions->record($assignment, AccountOperationResultPayload::fromJson($json))));
    }

    /**
     * @param AbstractAgent $agent Identity whose declared rights apply
     * @param Closure $work Work performed in its real execution frame
     * @return mixed Work's result
     */
    private function asAgent(AbstractAgent $agent, Closure $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), $work);
    }

    /** @param bool $down Drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Personal participant with distinct set keys for business rows and its own results. */
final class AccountOperationResultPerson extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array OWNS_DB_SET = [
        HilosDbContext::userRenames => [TruthSourceOperation::Add],
        HilosDbContext::accountOperationResults => [TruthSourceOperation::Add],
    ];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @param int $userId Personal set owner */
    public function __construct(private readonly int $userId)
    {
        $this->agentIndex = (string)$userId;
    }

    /**
     * @param string $collection Declared set collection
     * @return string Person key for renames and canonical agent address for results
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $collection === HilosDbContext::accountOperationResults ? $this->getId() : (string)$this->userId;
    }

    /** No background fixture work. */
    public function onStop(): void
    {
    }
}

/** Singleton participant is a different class with its own Add-only result set. */
final class AccountOperationResultLibrary extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USERS_LIBRARY;
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /**
     * @param string $collection Declared result collection
     * @return string Singleton's canonical agent address
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

final class AccountOperationResultCoordinator extends AbstractSessionsLibraryAgent
{
}

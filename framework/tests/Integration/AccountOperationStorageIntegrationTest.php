<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real commits and a reopened connection are part of this storage contract; no rollback root. */
final class AccountOperationStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string COORDINATOR_ID = 'erasure-storage-coordinator';
    private const string FOREIGN_ID = 'erasure-storage-participant';
    private const string STUB_PATH = __DIR__ . '/../../backend/Database/Migration/Stub/';
    private const int PEER_INDEX = 1;

    private ?HilosDbContext $previousDb;
    private int $frameToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->stubs(down: true);
        $this->stubs(down: false);
        $this->mountFreshContext();
        OwnershipDeclaration::claimDb(AccountOperationStorageCoordinator::class, self::COORDINATOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(down: true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR_ID));
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /** A real commit survives a reopened connection and exact repeats of the original work. */
    public function testCommitReconnectAndExactRepeatKeepTheSameStoredObligation(): void
    {
        $owner = new AccountOperationOwnerAddress('offline-participant', 'shard-A ');
        $circle = AccountOperationCircle::fromArray([9, 7], 7);
        Database::transactionStart();
        try {
            $operation = Hilos::$db->accountOperations->actions->recordErasure(101, 7, $circle);
            $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id,
                EntityAccountOperation::INITIAL_STAGE,
                'one',
                $owner,
                9,
                AccountOperationAssignmentPayload::fromJson('{"a":1,"b":[2,3]}'),
            );
            self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'persisted-stage'));
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollback();
            throw $failure;
        }
        $operationId = $operation->id;
        $assignmentId = $assignment->id;
        $operationObject = $operation->getObject();
        $operationData = $operation->toArray();
        $assignmentData = $assignment->toArray();
        $storedOperation = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        $storedAssignment = Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows();
        Database::close();
        Database::connect();
        $this->mountFreshContext();
        $reopened = Hilos::$db->accountOperations->byRequest(101);
        self::assertSame($operationId, $reopened->id);
        self::assertNotSame($operationObject, $reopened->getObject());
        self::assertSame([9, 7], $reopened->circle->toArray());
        self::assertSame(AccountOperationKind::Erasure, $reopened->kind);
        self::assertNull($reopened->loserUserId);
        self::assertNull($reopened->survivorUserId);
        self::assertNull($storedOperation[0]['merge_choice']);
        self::assertNull($storedOperation[0]['open_loser_user_id']);
        self::assertSame($operationData, $reopened->toArray());
        self::assertSame($assignmentData, Hilos::$db->accountOperationAssignments[$assignmentId]->toArray());
        self::assertSame($assignmentId, Hilos::$db->accountOperationAssignments->byIdentity(
            $operationId,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
        )->id);
        self::assertSame($operationId, Hilos::$db->accountOperationAssignments[$assignmentId]->operation->id);
        self::assertSame($operationId, Hilos::$db->accountOperations->actions->recordErasure(101, 7, $circle)->id);
        self::assertSame($assignmentId, Hilos::$db->accountOperationAssignments->actions->record(
            $operationId,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            $owner,
            9,
            AccountOperationAssignmentPayload::fromJson('{"b":[2,3],"a":1.0}'),
        )->id);
        self::assertCount(1, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1));
        self::assertCount(1, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 1));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure,
            new AccountOperationOwnerAddress('offline-participant', 'shard-A'),
            0,
            1,
        ));
        $rows = Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows();
        self::assertCount(1, $rows);
        self::assertSame($assignmentId, (int)$rows[0]['id']);
        self::assertSame('shard-A ', $rows[0]['owner_index']);
        self::assertSame(9, (int)$rows[0]['user_id']);
        self::assertSame($storedAssignment, $rows);
        self::assertSame($storedOperation, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
    }

    /** The closing CAS releases its unique loser slot and a stale stage cannot release it. */
    public function testFinishClearsTheLoserReservationOnlyWhenItClosesTheOperation(): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(117, 7, AccountOperationCircle::fromArray([7], 7));
        Database::sql('UPDATE `hilos_account_operation` SET `open_loser_user_id` = ? WHERE `id` = ?', [9, $operation->id]);

        self::assertFalse($operation->actions->finish('stale-stage', AccountOperationOutcome::NotStarted));
        $stored = Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->rows()[0];
        self::assertSame(9, (int)$stored['open_loser_user_id']);
        self::assertNull($stored['finished_at']);
        self::assertTrue($operation->actions->finish(EntityAccountOperation::INITIAL_STAGE, AccountOperationOutcome::NotStarted));
        $stored = Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->rows()[0];
        self::assertNull($stored['open_loser_user_id']);
        self::assertSame($operation->finishedAt, (int)$stored['finished_at']);
    }

    /** @return iterable<string, array{int, list<int>, bool}> Exact and conflicting concurrent operation records */
    public static function concurrentOperations(): iterable
    {
        yield 'exact winner' => [7, [9, 7], true];
        yield 'different circle' => [7, [7], false];
        yield 'different root' => [9, [7, 9], false];
    }

    /**
     * The peer commits after A's first consistent read; UNIQUE sees a row A's snapshot cannot see.
     *
     * @param int $rootUserId Repeated root
     * @param list<int> $circleUserIds Repeated fixed order
     * @param bool $exact Whether the repeat matches the peer's winner
     */
    #[DataProvider('concurrentOperations')]
    public function testOperationCollisionReadsTheWinnerPastTheCallerSnapshot(int $rootUserId, array $circleUserIds, bool $exact): void
    {
        $this->connectPeer();
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertNull(Hilos::$db->accountOperations->byRequest(114));
            Database::sql(
                'INSERT INTO `hilos_account_operation`'
                . ' (`kind`, `request_id`, `root_user_id`, `circle_user_ids`, `stage`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                [AccountOperationKind::Erasure->value, 114, 7, '[9,7]', EntityAccountOperation::INITIAL_STAGE, 1234, 1234],
                index: self::PEER_INDEX,
            );
            $winner = Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows();
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            $repeat = static fn () => Hilos::$db->accountOperations->actions->recordErasure(
                114, $rootUserId, AccountOperationCircle::fromArray($circleUserIds, $rootUserId),
            );
            if ($exact) {
                $operation = $repeat();
                self::assertSame((int)$winner[0]['id'], $operation->id);
                self::assertSame(1234, $operation->createdAt);
                self::assertSame([9, 7], $operation->circle->toArray());
                self::assertSame($operation->getObject(), $repeat()->getObject());
            } else {
                $this->assertValidationRefused($repeat);
            }
            // The action must neither replace A's transaction nor change its isolation/snapshot.
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            Database::transactionCommit();
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($winner, Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows());
    }

    /** @return iterable<string, array{string, bool}> Open, advanced and finished winners, exact and conflicting */
    public static function concurrentAssignments(): iterable
    {
        foreach (['open', 'advanced', 'finished'] as $state) {
            yield $state . ' exact' => [$state, true];
            yield $state . ' conflict' => [$state, false];
        }
    }

    /**
     * An unseen existing assignment remains repeatable even if the peer also moves or closes its parent.
     *
     * @param string $state Parent state committed by B after A's snapshot
     * @param bool $exact Whether A repeats the same work
     */
    #[DataProvider('concurrentAssignments')]
    public function testAssignmentCollisionReadsTheWinnerPastTheCallerSnapshot(string $state, bool $exact): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(115, 7, AccountOperationCircle::fromArray([7], 7));
        $this->connectPeer();
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertNull(Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, 'one'));
            self::assertSame(EntityAccountOperation::INITIAL_STAGE, Hilos::$db->accountOperations->byRequest(115)->stage);
            Database::sql(
                'INSERT INTO `hilos_account_operation_assignment`'
                . ' (`operation_id`, `stage`, `assignment_key`, `owner_type`, `owner_index`, `user_id`, `payload_json`, `created_at`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$operation->id, EntityAccountOperation::INITIAL_STAGE, 'one', 'offline', '0 ', 7, '{"a":1,"b":[2,3]}', 1234],
                index: self::PEER_INDEX,
            );
            $this->movePeerOperation($operation->id, $state);
            $winner = Database::sql('SELECT * FROM `hilos_account_operation_assignment`', index: self::PEER_INDEX)->rows();
            $parent = Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows();
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            $repeat = static fn () => Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, EntityAccountOperation::INITIAL_STAGE, 'one', new AccountOperationOwnerAddress('offline', '0 '),
                7, AccountOperationAssignmentPayload::fromJson($exact ? '{"b":[2,3],"a":1.0}' : '{"a":2,"b":[2,3]}'),
            );
            if ($exact) {
                $assignment = $repeat();
                self::assertSame((int)$winner[0]['id'], $assignment->id);
                self::assertSame(1234, $assignment->createdAt);
                self::assertSame('0 ', $assignment->ownerIndex);
                self::assertSame($assignment->getObject(), $repeat()->getObject());
            } else {
                $this->assertValidationRefused($repeat);
            }
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            Database::transactionCommit();
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($winner, Database::sql('SELECT * FROM `hilos_account_operation_assignment`', index: self::PEER_INDEX)->rows());
        self::assertSame($parent, Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows());
    }

    /** @return iterable<string, array{string}> Parent transitions that invalidate a new assignment */
    public static function concurrentParentTransitions(): iterable
    {
        yield 'advanced parent' => ['advanced'];
        yield 'finished parent' => ['finished'];
    }

    /** @param string $state Parent state committed after the caller observed it open */
    #[DataProvider('concurrentParentTransitions')]
    public function testNewAssignmentCannotUseAnOpenParentFromTheCallerSnapshot(string $state): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(116, 7, AccountOperationCircle::fromArray([7], 7));
        $this->connectPeer();
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertCount(1, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1));
            $this->movePeerOperation($operation->id, $state);
            $parent = Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows();
            $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, EntityAccountOperation::INITIAL_STAGE, 'new', new AccountOperationOwnerAddress('offline'),
                null, AccountOperationAssignmentPayload::fromJson('{}'),
            ));
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            // Still the original snapshot, even though admission must use the current parent.
            self::assertCount(1, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1));
            Database::transactionCommit();
        } finally {
            Database::transactionRollback();
        }
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`', index: self::PEER_INDEX)->rows());
        self::assertSame($parent, Database::sql('SELECT * FROM `hilos_account_operation`', index: self::PEER_INDEX)->rows());
    }

    /** Conditional writes keep issued wrappers current and retain the closed operation's last stage. */
    public function testCasRefreshesExistingItemsAndClosedRowsAllowOnlyExactRepeats(): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(102, 7, AccountOperationCircle::fromArray([7], 7));
        $owner = new AccountOperationOwnerAddress('missing-owner');
        $assignment = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            $owner,
            null,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );
        self::assertFalse($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'preparing'));
        self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'Arbitrary-stage'));
        self::assertSame('Arbitrary-stage', $operation->stage);
        $unchangedAt = $operation->updatedAt;
        $advanced = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        self::assertFalse($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'stale'));
        self::assertSame($unchangedAt, $operation->updatedAt);
        self::assertFalse($operation->actions->finish('arbitrary-stage', AccountOperationOutcome::Completed));
        self::assertSame($unchangedAt, $operation->updatedAt);
        self::assertSame($advanced, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
        self::assertTrue($operation->actions->finish('Arbitrary-stage', AccountOperationOutcome::NotStarted));
        self::assertSame('Arbitrary-stage', $operation->stage);
        self::assertSame(AccountOperationOutcome::NotStarted->value, $operation->outcome);
        self::assertSame($operation->finishedAt, $operation->updatedAt);
        $finishedAt = $operation->finishedAt;
        $finished = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        self::assertFalse($operation->actions->advance('Arbitrary-stage', 'closed'));
        self::assertFalse($operation->actions->finish('Arbitrary-stage', AccountOperationOutcome::Completed));
        self::assertSame($finishedAt, $operation->updatedAt);
        self::assertSame($finished, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
        self::assertSame($assignment->id, Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            $owner,
            null,
            AccountOperationAssignmentPayload::fromJson('{}'),
        )->id);
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 1));
        self::assertCount(1, Hilos::$db->accountOperationAssignments->forOperationStage(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
        ));
        $this->expectException(ValidationException::class);
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            'Arbitrary-stage',
            'new',
            $owner,
            null,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );
    }

    /** Case, whitespace and null remain literal in identity and address lookups. */
    public function testLiteralIdentityAndOwnerIndexRemainDistinct(): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(104, 7, AccountOperationCircle::fromArray([7], 7));
        $payload = AccountOperationAssignmentPayload::fromJson('{}');
        $first = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'key',
            new AccountOperationOwnerAddress('offline', '0'),
            null,
            $payload,
        );
        $trailing = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'key ',
            new AccountOperationOwnerAddress('offline', '0 '),
            null,
            $payload,
        );
        self::assertNotSame($first->id, $trailing->id);
        self::assertSame($first->id, Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'key',
        )->id);
        self::assertCount(1, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure, new AccountOperationOwnerAddress('offline', '0'), 0, 10,
        ));
        self::assertCount(1, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure, new AccountOperationOwnerAddress('offline', '0 '), 0, 10,
        ));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure, new AccountOperationOwnerAddress('offline'), 0, 10,
        ));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure, new AccountOperationOwnerAddress('Offline', '0'), 0, 10,
        ));
        self::assertFalse($operation->actions->advance('preparing ', 'should-not-move'));
        self::assertSame(EntityAccountOperation::INITIAL_STAGE, $operation->stage);
        foreach ([null, 'null', 'Shard', 'shard'] as $index => $ownerIndex) {
            $owner = new AccountOperationOwnerAddress('offline', $ownerIndex);
            $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $operation->stage, 'address-' . $index, $owner, null, $payload,
            );
            self::assertSame([$assignment->id], array_keys(iterator_to_array(
                Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 10),
            )));
            self::assertSame($ownerIndex, Database::sql(
                'SELECT `owner_index` FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$assignment->id],
            )->rows()[0]['owner_index']);
        }
        $upperKey = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, 'Key', new AccountOperationOwnerAddress('offline', '0'), null, $payload,
        );
        self::assertNotSame($first->id, $upperKey->id);
        self::assertSame($upperKey->id, Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, 'Key')->id);
        foreach (['Preparing', 'Preparing '] as $stage) {
            self::assertTrue($operation->actions->advance($operation->stage, $stage));
            $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, $stage, 'key', new AccountOperationOwnerAddress('offline'), null, $payload,
            );
            self::assertSame($assignment->id, Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage, 'key')->id);
            self::assertSame([$assignment->id], array_keys(iterator_to_array(
                Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, $stage),
            )));
        }
        self::assertSame($first->id, Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, EntityAccountOperation::INITIAL_STAGE, 'key',
        )->id);
    }

    public function testLocalRollbackRestoresCasMemoryAndDropsBothNewRows(): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(105, 7, AccountOperationCircle::fromArray([7], 7));
        $beforeAt = $operation->updatedAt;
        Database::transactionStart();
        try {
            self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'next'));
            $newOperation = Hilos::$db->accountOperations->actions->recordErasure(106, 9, AccountOperationCircle::fromArray([9], 9));
            $assignment = Hilos::$db->accountOperationAssignments->actions->record(
                $newOperation->id,
                EntityAccountOperation::INITIAL_STAGE,
                'one',
                new AccountOperationOwnerAddress('offline'),
                null,
                AccountOperationAssignmentPayload::fromJson('{}'),
            );
            $newOperationId = $newOperation->id;
            $assignmentId = $assignment->id;
        } finally {
            Database::transactionRollback();
        }
        self::assertSame(EntityAccountOperation::INITIAL_STAGE, $operation->stage);
        self::assertSame($beforeAt, $operation->updatedAt);
        self::assertNull($newOperation->id);
        self::assertNull($assignment->id);
        self::assertArrayNotHasKey($newOperationId, Hilos::$db->accountOperations);
        self::assertArrayNotHasKey($assignmentId, Hilos::$db->accountOperationAssignments);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        $rows = Database::sql('SELECT `request_id`, `stage`, `updated_at` FROM `hilos_account_operation`')->rows();
        self::assertCount(1, $rows);
        self::assertSame(105, (int)$rows[0]['request_id']);
        self::assertSame(EntityAccountOperation::INITIAL_STAGE, $rows[0]['stage']);
        self::assertSame($beforeAt, (int)$rows[0]['updated_at']);
    }

    /** @return iterable<string, array{int, list<int>}> Conflicts in the immutable operation identity */
    public static function conflictingOperations(): iterable
    {
        yield 'circle member removed' => [7, [9, 7]];
        yield 'circle order changed' => [7, [2, 9, 7]];
        yield 'different root with a valid circle' => [9, [2, 7, 9]];
    }

    /**
     * @param int $rootUserId Conflicting root
     * @param list<int> $circleUserIds Conflicting fixed order
     */
    #[DataProvider('conflictingOperations')]
    public function testConflictingOperationRepeatCannotOverwriteTheFixedCircle(int $rootUserId, array $circleUserIds): void
    {
        Hilos::$db->accountOperations->actions->recordErasure(107, 7, AccountOperationCircle::fromArray([9, 2, 7], 7));
        $before = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperations->actions->recordErasure(
            107,
            $rootUserId,
            AccountOperationCircle::fromArray($circleUserIds, $rootUserId),
        ));
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
    }

    /** @return iterable<string, array{string, ?string, ?int, string}> Conflicting assignment content */
    public static function conflictingAssignments(): iterable
    {
        yield 'payload value' => ['offline', null, null, '{"n":2,"parts":[2,3]}'];
        yield 'payload array order' => ['offline', null, null, '{"n":1,"parts":[3,2]}'];
        yield 'payload scalar type' => ['offline', null, null, '{"n":"1","parts":[2,3]}'];
        yield 'owner type case' => ['Offline', null, null, '{"n":1,"parts":[2,3]}'];
        yield 'owner type space' => ['offline ', null, null, '{"n":1,"parts":[2,3]}'];
        yield 'owner index literal null' => ['offline', 'null', null, '{"n":1,"parts":[2,3]}'];
        yield 'owner index string zero' => ['offline', '0', null, '{"n":1,"parts":[2,3]}'];
        yield 'whole circle becomes one member' => ['offline', null, 7, '{"n":1,"parts":[2,3]}'];
    }

    /**
     * @param string $ownerType Repeated participant type
     * @param ?string $ownerIndex Repeated nullable index
     * @param ?int $userId Repeated account scope
     * @param string $payloadJson Repeated JSON work
     */
    #[DataProvider('conflictingAssignments')]
    public function testConflictingAssignmentRepeatCannotChangeItsImmutableWork(
        string $ownerType,
        ?string $ownerIndex,
        ?int $userId,
        string $payloadJson,
    ): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(108, 7, AccountOperationCircle::fromArray([7], 7));
        $owner = new AccountOperationOwnerAddress('offline');
        Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            $owner,
            null,
            AccountOperationAssignmentPayload::fromJson('{"n":1,"parts":[2,3]}'),
        );
        $before = Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows();
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            new AccountOperationOwnerAddress($ownerType, $ownerIndex),
            $userId,
            AccountOperationAssignmentPayload::fromJson($payloadJson),
        ));
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
    }

    /** @return iterable<string, array{int, int}> Invalid request or mismatched root */
    public static function invalidOperations(): iterable
    {
        yield 'zero request' => [0, 7];
        yield 'negative request' => [-1, 7];
        yield 'root differs from the checked circle' => [109, 9];
    }

    /**
     * @param int $requestId Invalid request identity
     * @param int $rootUserId Root supplied beside the checked circle
     */
    #[DataProvider('invalidOperations')]
    public function testInvalidOperationIdentityCreatesNoRows(int $requestId, int $rootUserId): void
    {
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperations->actions->recordErasure(
            $requestId,
            $rootUserId,
            AccountOperationCircle::fromArray([7], 7),
        ));
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
    }

    /** @return iterable<string, array{string, string, ?int, bool}> Refused new assignment boundaries */
    public static function invalidNewAssignments(): iterable
    {
        yield 'empty stage' => ['', 'one', null, false];
        yield 'stage too long' => [str_repeat('界', 65), 'one', null, false];
        yield 'empty key' => [EntityAccountOperation::INITIAL_STAGE, '', null, false];
        yield 'key too long' => [EntityAccountOperation::INITIAL_STAGE, str_repeat('界', 192), null, false];
        yield 'future stage' => ['future-stage', 'one', null, false];
        yield 'stage case is literal' => ['Preparing', 'one', null, false];
        yield 'stage trailing space is literal' => ['preparing ', 'one', null, false];
        yield 'zero user' => [EntityAccountOperation::INITIAL_STAGE, 'one', 0, false];
        yield 'negative user' => [EntityAccountOperation::INITIAL_STAGE, 'one', -1, false];
        yield 'user outside the fixed circle' => [EntityAccountOperation::INITIAL_STAGE, 'one', 8, false];
        yield 'missing operation' => [EntityAccountOperation::INITIAL_STAGE, 'one', null, true];
    }

    /**
     * @param string $stage Supplied stage
     * @param string $key Supplied part key
     * @param ?int $userId Supplied account scope
     * @param bool $missingOperation Whether the parent does not exist
     */
    #[DataProvider('invalidNewAssignments')]
    public function testNewAssignmentsRequireValidIdentityAndTheCurrentOpenStage(
        string $stage,
        string $key,
        ?int $userId,
        bool $missingOperation,
    ): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(109, 7, AccountOperationCircle::fromArray([9, 7], 7));
        $before = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $missingOperation ? $operation->id + 1 : $operation->id,
            $stage,
            $key,
            new AccountOperationOwnerAddress('offline'),
            $userId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        ));
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
    }

    /** Invalid CAS keys refuse before changing the row, through both conditional write APIs. */
    public function testInvalidCasStagesLeaveTheStoredOperationUnchanged(): void
    {
        $operation = Hilos::$db->accountOperations->actions->recordErasure(113, 7, AccountOperationCircle::fromArray([7], 7));
        $before = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        foreach (['', str_repeat('界', 65)] as $stage) {
            $this->assertValidationRefused(static fn () => $operation->actions->advance($stage, 'next'));
            $this->assertValidationRefused(static fn () => $operation->actions->advance($operation->stage, $stage));
            $this->assertValidationRefused(static fn () => $operation->actions->finish($stage, AccountOperationOutcome::Completed));
        }
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
    }

    /** Maximum Unicode boundaries survive storage; old identities remain repeatable after advancing and closing. */
    public function testOnlyExactOldWorkCanBeRepeatedWhileNewWorkFollowsTheCurrentStage(): void
    {
        $circle = AccountOperationCircle::fromArray([9, 7], 7);
        $operation = Hilos::$db->accountOperations->actions->recordErasure(110, 7, $circle);
        $fixedOperation = Database::sql('SELECT * FROM `hilos_account_operation`')->rows()[0];
        $owner = new AccountOperationOwnerAddress(str_repeat('界', 191), str_repeat('Ж', 191));
        $payload = AccountOperationAssignmentPayload::fromJson('{"part":{"a":1,"b":2}}');
        $key = str_repeat('界', 191);
        $assignment = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            $key,
            $owner,
            9,
            $payload,
        );
        $original = Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows();
        self::assertTrue($operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, str_repeat('Ж', 64)));
        self::assertSame($assignment->id, Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            $key,
            $owner,
            9,
            AccountOperationAssignmentPayload::fromJson('{"part":{"b":2.0,"a":1}}'),
        )->id);
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'new-in-old-stage',
            $owner,
            null,
            $payload,
        ));
        self::assertSame($original, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        $current = Hilos::$db->accountOperationAssignments->actions->record($operation->id, $operation->stage, $key, $owner, 7, $payload);
        self::assertNotSame($assignment->id, $current->id);
        self::assertNull(Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, str_repeat('Ж', 191)));
        self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
        $closed = Database::sql('SELECT * FROM `hilos_account_operation`')->rows();
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        self::assertSame($operation->id, Hilos::$db->accountOperations->actions->recordErasure(110, 7, $circle)->id);
        self::assertSame($current->id, Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $key, $owner, 7, $payload,
        )->id);
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, $operation->stage, $key, $owner, null, $payload,
        ));
        self::assertSame($closed, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame('completed', $closed[0]['outcome']);
        self::assertSame(str_repeat('Ж', 64), $closed[0]['stage']);
        self::assertSame($closed[0]['finished_at'], $closed[0]['updated_at']);
        foreach (['id', 'request_id', 'root_user_id', 'circle_user_ids', 'created_at'] as $field) {
            self::assertSame($fixedOperation[$field], $closed[0][$field], $field . ' is immutable across CAS and repeats');
        }
        self::assertSame($owner->ownerType, $assignments[1]['owner_type']);
        self::assertSame($owner->ownerIndex, $assignments[1]['owner_index']);
        self::assertSame($key, $assignments[1]['assignment_key']);
        self::assertSame(7, (int)$assignments[1]['user_id']);
        self::assertSame($original[0]['created_at'], $assignments[0]['created_at']);
    }

    /** Readers page by primary id across closed rows, independently of request and part key order. */
    public function testOpenReadersUseExclusiveOrderedPagesAndExcludeClosedOperations(): void
    {
        $circle = AccountOperationCircle::fromArray([7], 7);
        $first = Hilos::$db->accountOperations->actions->recordErasure(300, 7, $circle);
        $closed = Hilos::$db->accountOperations->actions->recordErasure(100, 7, $circle);
        $last = Hilos::$db->accountOperations->actions->recordErasure(200, 7, $circle);
        $owner = new AccountOperationOwnerAddress('offline');
        $payload = AccountOperationAssignmentPayload::fromJson('{}');
        $firstPart = Hilos::$db->accountOperationAssignments->actions->record($first->id, $first->stage, 'z', $owner, null, $payload);
        Hilos::$db->accountOperationAssignments->actions->record($closed->id, $closed->stage, 'a', $owner, null, $payload);
        Hilos::$db->accountOperationAssignments->actions->record(
            $last->id, $last->stage, 'other-owner', new AccountOperationOwnerAddress('Offline'), null, $payload,
        );
        $secondPart = Hilos::$db->accountOperationAssignments->actions->record($first->id, $first->stage, 'a', $owner, null, $payload);
        $lastPart = Hilos::$db->accountOperationAssignments->actions->record($last->id, $last->stage, 'z', $owner, null, $payload);
        // Warm both selections before closing, so a reader using cached membership would fail too.
        self::assertCount(3, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1000));
        self::assertCount(4, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 1000));
        self::assertTrue($closed->actions->finish($closed->stage, AccountOperationOutcome::NotStarted));

        self::assertSame([$first->id], array_keys(iterator_to_array(Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1))));
        self::assertSame([$last->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, $first->id, 1),
        )));
        self::assertSame([$first->id, $last->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, 0, 1000),
        )));
        self::assertCount(0, Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, $last->id, 1));
        self::assertSame([$firstPart->id, $secondPart->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, 0, 2),
        )));
        self::assertSame([$secondPart->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, $firstPart->id, 1),
        )));
        self::assertSame([$lastPart->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, $secondPart->id, 1000),
        )));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->openForOwner(AccountOperationKind::Erasure, $owner, $lastPart->id, 1000));
        self::assertSame([$firstPart->id, $secondPart->id], array_keys(iterator_to_array(
            Hilos::$db->accountOperationAssignments->forOperationStage($first->id, $first->stage),
        )));
        self::assertCount(1, Hilos::$db->accountOperationAssignments->forOperationStage($closed->id, $closed->stage));
        self::assertNull(Hilos::$db->accountOperations->byRequest(999));
        self::assertNull(Hilos::$db->accountOperations[$last->id + 1] ?? null);
        self::assertNull(Hilos::$db->accountOperationAssignments[$lastPart->id + 1] ?? null);
    }

    /** @return iterable<string, array{int, int}> Invalid cursor and page sizes */
    public static function invalidPages(): iterable
    {
        yield 'negative cursor' => [-1, 1];
        yield 'zero limit' => [0, 0];
        yield 'negative limit' => [0, -1];
        yield 'above maximum limit' => [0, 1001];
    }

    /**
     * @param int $afterId Supplied exclusive cursor
     * @param int $limit Supplied page size
     */
    #[DataProvider('invalidPages')]
    public function testBothOpenReadersRefuseUnboundedOrInvalidPages(int $afterId, int $limit): void
    {
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperations->openPage(AccountOperationKind::Erasure, $afterId, $limit));
        $this->assertValidationRefused(static fn () => Hilos::$db->accountOperationAssignments->openForOwner(
            AccountOperationKind::Erasure,
            new AccountOperationOwnerAddress('offline'), $afterId, $limit,
        ));
    }

    /** The real restore pass purges filled carriers while preserving an unrelated nonpersonal row. */
    public function testAnonymizationGatesAndPassPurgeBothFilledCarriersAndKeepUnrelatedData(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_i18n_reflow`');
        Database::sqlRun(file_get_contents(self::STUB_PATH . 'create_hilos_i18n_reflow.sql'));
        try {
            Database::sqlRun('INSERT INTO `hilos_i18n_reflow` (`id`, `fingerprint`) VALUES (1, ?)', [str_repeat('a', 64)]);
            $neighbour = Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows();
            foreach ([111, 112] as $requestId) {
                $operation = Hilos::$db->accountOperations->actions->recordErasure($requestId, 7, AccountOperationCircle::fromArray([7], 7));
                Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id, $operation->stage, 'one', new AccountOperationOwnerAddress('offline'), 7,
                    AccountOperationAssignmentPayload::fromJson('{"private":"work"}'),
                );
                if ($requestId === 112) {
                    self::assertTrue($operation->actions->finish($operation->stage, AccountOperationOutcome::Completed));
                }
            }
            self::assertCount(2, Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            self::assertCount(2, Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            $registry = PiiRegistry::collect();
            self::assertTrue($registry->isPurged(0, EntityAccountOperation::_table));
            self::assertTrue($registry->isPurged(0, EntityAccountOperationAssignment::_table));
            $schemas = LiveSchemaReader::read(0);
            AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => $schemas]);
            $anonymizer = new CatalogRestoreAnonymizer($registry, new AnonymizationSqlBuilder(str_repeat('b', 64)));
            $anonymizer->validateArchive([0 => array_keys($schemas)]);
            $database = Database::getConnectionConfig(0)->database;
            $anonymizer->validateTargetSchema(0, $database);
            $anonymizer->anonymizeConnection(0, $database);

            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
            self::assertSame($neighbour, Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows());
        } finally {
            Database::sqlRun('DROP TABLE `hilos_i18n_reflow`');
        }
    }

    /**
     * @return iterable<string, array{string, TruthSourceOperation}> Forbidden coordinator mutations
     */
    public static function forbiddenMutations(): iterable
    {
        yield 'remove operation' => [HilosDbContext::accountOperations, TruthSourceOperation::Remove];
        yield 'update assignment' => [HilosDbContext::accountOperationAssignments, TruthSourceOperation::Update];
        yield 'remove assignment' => [HilosDbContext::accountOperationAssignments, TruthSourceOperation::Remove];
    }

    /**
     * @param string $collection Working-row collection
     * @param TruthSourceOperation $operation Forbidden operation
     */
    #[DataProvider('forbiddenMutations')]
    public function testCoordinatorCannotMutateImmutableOrRetainedRows(string $collection, TruthSourceOperation $operation): void
    {
        $this->expectException(WriteNotAllowedException::class);
        DbWriteGuard::guardCollectionWrite($collection, $operation);
    }

    /** @return iterable<string, array{bool}> Open and closed repeat guard paths */
    public static function guardedOperationStates(): iterable
    {
        yield 'open operation' => [false];
        yield 'closed operation with old-stage work' => [true];
    }

    /**
     * Even an exact old or closed repeat is guarded before a storage lookup.
     *
     * @param bool $closed Whether the stored work belongs to an already closed operation
     */
    #[DataProvider('guardedOperationStates')]
    public function testForeignFramesCannotCreateRepeatOrAdvanceBeforeSql(bool $closed): void
    {
        $circle = AccountOperationCircle::fromArray([7], 7);
        $operation = Hilos::$db->accountOperations->actions->recordErasure(103, 7, $circle);
        $owner = new AccountOperationOwnerAddress('missing-owner');
        $payload = AccountOperationAssignmentPayload::fromJson('{}');
        $assignment = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            EntityAccountOperation::INITIAL_STAGE,
            'one',
            $owner,
            null,
            $payload,
        );
        if ($closed) {
            self::assertTrue($operation->actions->advance($operation->stage, 'last-stage'));
            self::assertTrue($operation->actions->finish('last-stage', AccountOperationOutcome::Completed));
        }
        // With both tables absent, reaching any lookup or write would raise a SQL error instead of the guard refusal.
        $this->stubs(down: true);
        ExecutionContext::run(new ExecutionFrame(agentId: self::FOREIGN_ID), function () use ($circle, $operation, $owner, $payload): void {
            foreach ([103, 104] as $requestId) {
                try {
                    Hilos::$db->accountOperations->actions->recordErasure($requestId, 7, $circle);
                    self::fail('A participant recorded an operation');
                } catch (CreateNotAllowedException) {
                    self::assertTrue(true);
                }
            }
            foreach (['one', 'new'] as $key) {
                try {
                    Hilos::$db->accountOperationAssignments->actions->record(
                        $operation->id,
                        EntityAccountOperation::INITIAL_STAGE,
                        $key,
                        $owner,
                        null,
                        $payload,
                    );
                    self::fail('A participant recorded an assignment');
                } catch (CreateNotAllowedException) {
                    self::assertTrue(true);
                }
            }
            try {
                $operation->actions->advance(EntityAccountOperation::INITIAL_STAGE, 'forbidden');
                self::fail('A participant advanced an operation');
            } catch (WriteNotAllowedException) {
                self::assertTrue(true);
            }
            try {
                $operation->actions->finish('last-stage', AccountOperationOutcome::Completed);
                self::fail('A participant finished an operation');
            } catch (WriteNotAllowedException) {
                self::assertTrue(true);
            }
        });
        self::assertSame('one', $assignment->assignmentKey);
    }

    /** A second database session on the same schema, with no shared transaction or ORM row memory. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
        self::assertNotSame(
            Database::sql('SELECT CONNECTION_ID()')->rows(),
            Database::sql('SELECT CONNECTION_ID()', index: self::PEER_INDEX)->rows(),
        );
        self::assertSame(
            Database::sql('SELECT DATABASE()')->rows(),
            Database::sql('SELECT DATABASE()', index: self::PEER_INDEX)->rows(),
        );
    }

    /**
     * Commits B's CAS while A retains its old snapshot, without delivering ORM sync to A.
     *
     * @param int $operationId Parent observed by both sessions
     * @param string $state Open, advanced or finished state to leave in B
     */
    private function movePeerOperation(int $operationId, string $state): void
    {
        if ($state === 'open') {
            return;
        }
        if ($state === 'advanced') {
            Database::sql(
                'UPDATE `hilos_account_operation` SET `stage` = ?, `updated_at` = ?'
                . ' WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
                ['next', 2345, $operationId, EntityAccountOperation::INITIAL_STAGE],
                index: self::PEER_INDEX,
            );
        } else {
            Database::sql(
                'UPDATE `hilos_account_operation` SET `outcome` = ?, `finished_at` = ?, `updated_at` = ?'
                . ' WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
                [AccountOperationOutcome::Completed->value, 2345, 2345, $operationId, EntityAccountOperation::INITIAL_STAGE],
                index: self::PEER_INDEX,
            );
        }
        self::assertSame(1, Database::affectedRows(self::PEER_INDEX));
    }

    /** @param Closure $attempt Storage call that must refuse invalid input */
    private function assertValidationRefused(Closure $attempt): void
    {
        try {
            $attempt();
            self::fail('Storage accepted invalid or conflicting work');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    /** Creates new objects and wrappers rather than resetting the old ORM. */
    private function mountFreshContext(): void
    {
        Hilos::$db = new AccountOperationStorageIntegrationContext();
        Hilos::$db->configure();
    }

    /** @param bool $down Whether to drop child before parent */
    private function stubs(bool $down): void
    {
        $tables = [EntityAccountOperation::_table, EntityAccountOperationAssignment::_table];
        foreach ($down ? array_reverse($tables) : $tables as $table) {
            // external-boundary: an up stub has no direction suffix in its filename
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(self::STUB_PATH . 'create_' . $table . $suffix . '.sql'));
        }
    }
}

final class AccountOperationStorageIntegrationContext extends HilosDbContext
{
}

final class AccountOperationStorageCoordinator extends AbstractSessionsLibraryAgent
{
}

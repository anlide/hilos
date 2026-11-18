<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeAdmission;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCleanup;
use Hilos\Auth\AccountOperation\AccountMergeCleanupResult;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountMergeCleanupWaitsException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
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
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** Real owner frames, committed preparations/give/receive, and atomic copy deletion with its result. */
final class AccountMergeCleanupIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    private const string COORDINATOR = 'merge-cleanup-coordinator';
    private const int LOSER = 9;
    private const int SURVIVOR = 7;
    private const int PEER = 2;
    private const array TABLES = [
        'hilos_user', 'hilos_identity', 'hilos_passkey_credential', 'hilos_second_factor', 'hilos_second_factor_backup_code',
        'hilos_second_factor_setting', 'hilos_second_factor_reset', 'hilos_second_factor_trust', 'hilos_second_factor_trust_restriction',
        'hilos_user_merge', 'hilos_account_deletion', 'hilos_account_operation', 'hilos_account_operation_assignment',
        'hilos_account_operation_result', 'hilos_account_merge_copy', 'hilos_account_merge_fence',
    ];
    private ?HilosDbContext $previousDb;
    private ?SignalRouter $previousRouter;
    private int $frameToken;
    private int $identityId;

    /** Uses real commits without a rollback root, beside actual personal ownership declarations. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->stubs(true);
        $this->stubs(false);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->freshContext();
        OwnershipDeclaration::claimDb(MergeCleanupCoordinator::class, self::COORDINATOR);
        $this->personAgent(self::LOSER);
        $this->personAgent(self::SURVIVOR);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR));
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other'), (13, 'Next')");
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`,'
            . ' `password_changed_at`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [self::LOSER, IdentityType::PASSWORD, 'source@example.test', 'password-hash-original', 1, 0, 0, 0]);
        $this->identityId = Database::lastInsertId();
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?)', [self::LOSER, 'Authenticator', 'totp-secret-original', 0, 0]);
        $this->peerConnection();
    }

    /** Restores declarations, facades and the handler before removing the schema fixtures. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR));
            Hilos::$db = $this->previousDb;
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /** No receipt means no deletion, saved cleanup, announcement or stop request. */
    public function testBeforeReceiptCleanupWaitsWithoutWritingOrStopping(): void
    {
        [$operation, $give, $cleanup] = $this->given();
        $before = $this->snapshot();
        $this->drain();
        $this->refused(AccountMergeCleanupWaitsException::class, fn () => $this->cleanup($cleanup));
        self::assertSame($before, $this->snapshot());
        self::assertTrue(Hilos::$db->accountMergeCopies->hasStored($give->id));
        self::assertNull(AccountMergeCleanupResult::forOperation($operation));
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        self::assertSame([], $this->drain());
        $answer = $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => AccountOperationAsks::answer(
            new AccountOperationAskSignalData($operation->id, AccountMergeStage::Go->value, $cleanup->id, (string)self::LOSER),
            static fn (AccountOperationAssignment $assignment) => $agent->cleanupForMerge($assignment->id), $agent->getId(),
        ));
        self::assertSame(AccountOperationAnswerKind::Waits, $answer->answer);
        self::assertSame([$operation->id, AccountMergeStage::Go->value, $cleanup->id],
            [$answer->operationId, $answer->stage, $answer->assignmentId]);
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        $this->refused(DatabaseException::class, fn () => $this->asPerson(self::LOSER,
            static fn () => Hilos::$db->accountMergeCopies[$give->id]->actions->deleteAfterReceipt()));
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::LOSER, static fn () => Database::transaction(
            DatabaseNames::PRIMARY, static fn () => Hilos::$db->accountMergeCopies[$give->id]->actions->deleteAfterReceipt(),
        )));
        self::assertSame($before, $this->snapshot());
        $this->assignment($operation->id, AccountMergeDuty::Receive, self::SURVIVOR);
        $pending = $this->snapshot();
        $this->refused(AccountMergeCleanupWaitsException::class, fn () => $this->cleanup($cleanup));
        self::assertSame($pending, $this->snapshot());
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
    }

    /** A peer sees neither deletion nor result until the single commit; another source's copy stays intact. */
    public function testReceiptCommitsOnlyTheSourceCopyDeletionAndCleanResultTogether(): void
    {
        [$operation, $give, $cleanup] = $this->received();
        [, $otherGive] = $this->ready(fate: null, survivor: 13, loser: 11);
        $this->give($otherGive);
        $otherBefore = $this->copyDigest($otherGive->id);
        $checked = [];
        $this->drain();
        SourceChangeBus::subscribe(new MergeCleanupMirror(function (SourceChange $change) use ($give, $cleanup, &$checked): void {
            if (!in_array($change->sourceKey, [HilosDbContext::accountMergeCopies, HilosDbContext::accountOperationResults], true)) {
                return;
            }
            self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?',
                [$give->id], index: self::PEER)->field('n'));
            self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
                [$cleanup->id], index: self::PEER)->field('n'));
            self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
            $checked[] = [$change->sourceKey, $change->mutationType];
        }));
        $result = $this->cleanup($cleanup);
        self::assertSame([
            [HilosDbContext::accountMergeCopies, TableMutationType::Delete],
            [HilosDbContext::accountOperationResults, TableMutationType::Create],
        ], $checked);
        self::assertSame('{"cleanup":"cleaned"}', $result->payloadJson);
        self::assertSame(['cleanup' => 'cleaned'], AccountMergeCleanupResult::forOperation($operation)->toArray());
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
        self::assertNull(Hilos::$db->accountMergeCopies[$give->id] ?? null);
        self::assertSame($otherBefore, $this->copyDigest($otherGive->id));
        self::assertTrue($this->personAgent(self::LOSER)->shouldStop());
        self::assertFalse($this->personAgent(self::SURVIVOR)->shouldStop());
        self::assertFalse($this->personAgent(11)->shouldStop());
        self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?',
            [$give->id], index: self::PEER)->field('n'));
        self::assertSame((string)$result->id, Database::sql('SELECT `id` FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
            [$cleanup->id], index: self::PEER)->field('id'));
    }

    /** @return array<string, array{bool}> Both windows must restore SQL, the same Object and membership */
    public static function storageFailures(): array
    {
        return ['result insert' => [false], 'commit' => [true]];
    }

    /** @param bool $commitFailure Whether the peer kills the real connection after result insertion */
    #[DataProvider('storageFailures')]
    public function testDeleteAndResultFailuresRestorePayloadObjectsMembershipAndAnnouncements(bool $commitFailure): void
    {
        [, $give, $cleanup] = $this->received();
        $object = Hilos::$db->accountMergeCopies[$give->id]->getObject();
        $row = $object->toArray();
        $before = $this->snapshot();
        $reaction = new MergeCleanupReaction();
        SourceChangeBus::subscribe($reaction);
        $deleted = false;
        SourceChangeBus::subscribe(new MergeCleanupMirror(function (SourceChange $change) use ($cleanup, $commitFailure, &$deleted): void {
            if ($change->sourceKey === HilosDbContext::accountMergeCopies && $change->mutationType === TableMutationType::Delete) {
                $deleted = true;
                self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
            }
            if ($commitFailure && $change->sourceKey === HilosDbContext::accountOperationResults
                && $change->mutationType === TableMutationType::Create && $change->row['assignmentId'] === $cleanup->id) {
                $session = (int)Database::sql('SELECT CONNECTION_ID() AS id')->field('id');
                Database::sql('KILL ?', [$session], index: self::PEER);
            }
        }));
        if (!$commitFailure) {
            Database::sqlRun('CREATE TRIGGER `cleanup_failure` BEFORE INSERT ON `hilos_account_operation_result`'
                . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cleanup storage failure'");
        }
        $this->drain();
        try {
            $failure = $this->refused(DatabaseException::class, fn () => $this->cleanup($cleanup));
            self::assertStringContainsString($commitFailure ? 'Query: COMMIT' : 'cleanup storage failure', $failure->getMessage());
            self::assertTrue($deleted);
            self::assertSame($before, $this->snapshot());
            self::assertSame($object, Hilos::$db->accountMergeCopies[$give->id]->getObject());
            self::assertSame($row, $object->toArray());
            self::assertTrue($object->isRelated());
            self::assertFalse($object->hasChanges());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($cleanup->id));
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->drain());
            self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        } finally {
            if (!$commitFailure) {
                Database::sqlRun('DROP TRIGGER `cleanup_failure`');
            }
        }
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->cleanup($cleanup);
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
    }

    /** A lost answer replays the same result id without entering another delete or opening a transaction. */
    public function testCommittedCleanupReplaysWithoutDeletingAgain(): void
    {
        [, $give, $cleanup] = $this->received();
        $result = $this->cleanup($cleanup);
        $before = $this->snapshot();
        Database::sqlRun('CREATE TRIGGER `cleanup_no_repeat` BEFORE DELETE ON `hilos_account_merge_copy`'
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cleanup repeated delete'");
        $this->drain();
        try {
            self::assertSame($result->id, $this->cleanup($cleanup)->id);
            self::assertSame($before, $this->snapshot());
            self::assertSame([], $this->drain());
            self::assertFalse(Database::hasOpenTransactions());
            self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
        } finally {
            Database::sqlRun('DROP TRIGGER `cleanup_no_repeat`');
        }
    }

    /** A replacement personal owner can request stop from the saved fact without touching business or copy storage. */
    public function testNewOwnerAndColdContextReplaySavedCleanup(): void
    {
        [$operation, , $cleanup] = $this->received();
        $result = $this->cleanup($cleanup);
        $this->releasePersonAgents();
        $this->freshContext(true);
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge($cleanup->id)));
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::LOSER,
            static fn () => AccountMergeCleanup::run($cleanup->id, self::SURVIVOR)));
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        self::assertFalse($this->personAgent(self::SURVIVOR)->shouldStop());
        self::assertSame($result->id, $this->cleanup($cleanup)->id);
        self::assertTrue($this->personAgent(self::LOSER)->shouldStop());
        self::assertSame(['cleanup' => 'cleaned'], AccountMergeCleanupResult::forOperation(Hilos::$db->accountOperations[$operation->id])->toArray());
    }

    /** @return iterable<string, array{string}> Independent corruption at every binding boundary */
    public static function defects(): iterable
    {
        foreach (['caller', 'user', 'unknown', 'kind', 'pair', 'stage', 'closed', 'cleanup owner', 'cleanup user',
            'cleanup stage', 'cleanup duty', 'cleanup payload', 'give owner', 'give user', 'give payload', 'missing give',
            'give result owner', 'give result payload', 'give result wait', 'missing give result',
            'receipt owner', 'receipt user', 'receipt payload', 'receipt result owner', 'receipt result payload',
            'copy owner', 'saved owner', 'saved payload'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** @param string $defect One foreign or malformed immutable proof */
    #[DataProvider('defects')]
    public function testInvalidBindingsRefuseWithoutCopyDeletionResultOrStop(string $defect): void
    {
        [$operation, $give, $cleanup, $receive] = $this->received();
        if (str_starts_with($defect, 'saved ')) {
            $this->cleanup($cleanup);
            $this->releasePersonAgents();
        }
        switch ($defect) {
            case 'kind': Database::sqlRun("UPDATE `hilos_account_operation` SET `kind` = 'erasure' WHERE `id` = ?", [$operation->id]); break;
            case 'pair': Database::sqlRun('UPDATE `hilos_account_operation` SET `root_user_id` = `loser_user_id` WHERE `id` = ?',
                [$operation->id]); break;
            case 'stage': Database::sqlRun("UPDATE `hilos_account_operation` SET `stage` = 'cancelling' WHERE `id` = ?", [$operation->id]); break;
            case 'closed': $operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed); break;
            case 'cleanup owner': $this->assignmentFault($cleanup->id, 'owner_index', '7'); break;
            case 'cleanup user': $this->assignmentFault($cleanup->id, 'user_id', self::SURVIVOR); break;
            case 'cleanup stage': $this->assignmentFault($cleanup->id, 'stage', AccountMergeStage::Preparing->value); break;
            case 'cleanup duty': $this->assignmentFault($cleanup->id, 'assignment_key', 'foreign'); break;
            case 'cleanup payload': $this->assignmentFault($cleanup->id, 'payload_json', '{"extra":true}'); break;
            case 'give owner': $this->assignmentFault($give->id, 'owner_index', '7'); break;
            case 'give user': $this->assignmentFault($give->id, 'user_id', self::SURVIVOR); break;
            case 'give payload': $this->assignmentFault($give->id, 'payload_json', '{"extra":true}'); break;
            case 'missing give': $this->assignmentFault($give->id, 'assignment_key', 'foreign'); break;
            case 'give result owner': $this->resultFault($give->id, 'owner_agent_id', 'foreign'); break;
            case 'give result payload': $this->resultFault($give->id, 'payload_json', '{}'); break;
            case 'give result wait': Database::sqlRun("UPDATE `hilos_account_operation_result`"
                . " SET `payload_json` = JSON_REMOVE(`payload_json`, '$.effectiveWaitDays') WHERE `assignment_id` = ?", [$give->id]); break;
            case 'missing give result': Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
                [$give->id]); break;
            case 'receipt owner': $this->assignmentFault($receive->id, 'owner_index', '9'); break;
            case 'receipt user': $this->assignmentFault($receive->id, 'user_id', self::LOSER); break;
            case 'receipt payload': $this->assignmentFault($receive->id, 'payload_json', '{"extra":true}'); break;
            case 'receipt result owner': $this->resultFault($receive->id, 'owner_agent_id', 'foreign'); break;
            case 'receipt result payload': $this->resultFault($receive->id, 'payload_json', '{}'); break;
            case 'copy owner': Database::sqlRun("UPDATE `hilos_account_merge_copy` SET `owner_agent_id` = 'foreign'"
                . ' WHERE `assignment_id` = ?', [$give->id]); break;
            case 'saved owner': $this->resultFault($cleanup->id, 'owner_agent_id', 'foreign'); break;
            case 'saved payload': $this->resultFault($cleanup->id, 'payload_json', '{}'); break;
        }
        $this->freshContext();
        $before = $this->snapshot();
        $this->drain();
        $this->refused($defect === 'copy owner' ? WriteNotAllowedException::class : ValidationException::class, fn () => match ($defect) {
            'caller' => $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge($cleanup->id)),
            'user' => $this->asPerson(self::LOSER, static fn () => AccountMergeCleanup::run($cleanup->id, self::SURVIVOR)),
            'unknown' => $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge(PHP_INT_MAX)),
            default => $this->cleanup($cleanup),
        });
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        self::assertFalse($this->personAgent(self::SURVIVOR)->shouldStop());
        self::assertSame([], $this->drain());
    }

    /** The typed action independently refuses another source and every missing or malformed receipt. */
    public function testItemRemoveChecksItsOwnSourceAndReceiptInsteadOfTrustingTheEntry(): void
    {
        [, $give, , $receive] = $this->received();
        $this->refused(WriteNotAllowedException::class, fn () => $this->asPerson(self::SURVIVOR, static fn () => Database::transaction(
            DatabaseNames::PRIMARY, static fn () => Hilos::$db->accountMergeCopies[$give->id]->actions->deleteAfterReceipt(),
        )));
        $this->resultFault($receive->id, 'payload_json', '{}');
        $this->freshContext();
        $before = $this->snapshot();
        $this->refused(InvalidFormatException::class, fn () => $this->asPerson(self::LOSER, static fn () => Database::transaction(
            DatabaseNames::PRIMARY, static fn () => Hilos::$db->accountMergeCopies[$give->id]->actions->deleteAfterReceipt(),
        )));
        self::assertSame($before, $this->snapshot());
    }

    /** A malformed private body cannot affect cleanup, which reads only bound metadata and committed proofs. */
    public function testCleanupDoesNotReadThePrivatePayload(): void
    {
        [, $give, $cleanup] = $this->received();
        Database::sqlRun("UPDATE `hilos_account_merge_copy` SET `payload_json` = '{}' WHERE `assignment_id` = ?", [$give->id]);
        self::assertSame('{"cleanup":"cleaned"}', $this->cleanup($cleanup)->payloadJson);
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
    }

    /** A valid receipt is still necessary when an earlier committed removal already left no private copy. */
    public function testAlreadyMissingCopyCanRecordCleanOnlyAfterReceipt(): void
    {
        [$operation, $give, $cleanup] = $this->given();
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id]);
        $this->freshContext();
        $this->refused(AccountMergeCleanupWaitsException::class, fn () => $this->cleanup($cleanup));
        $receive = $this->assignment($operation->id, AccountMergeDuty::Receive, self::SURVIVOR);
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        self::assertSame('{"cleanup":"cleaned"}', $this->cleanup($cleanup)->payloadJson);
        self::assertFalse(Hilos::$db->accountMergeCopies->hasStored($give->id));
    }

    /** Old cleanup replays before all current state, including the next real source copy and changed business set. */
    public function testClosedOldOperationReplayDoesNotTouchTheNextMerge(): void
    {
        [$operation, , $cleanup] = $this->received();
        $result = $this->cleanup($cleanup);
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        [, $nextGive] = $this->ready(survivor: 13, loser: self::SURVIVOR);
        $this->give($nextGive);
        $before = $this->snapshot();
        $this->releasePersonAgents();
        $this->freshContext(true);
        self::assertSame($result->id, $this->cleanup($cleanup)->id);
        self::assertTrue($this->personAgent(self::LOSER)->shouldStop());
        self::assertSame($before, $this->snapshot());
    }

    /** The fact, receipt and fences survive cleanup; original admission cannot undo a newer survivor write. */
    public function testCleanupNeverRestoresCopyOrHandoverAndRetainsAdmissionProofs(): void
    {
        [$operation, $give, $cleanup] = $this->received();
        $handover = $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER));
        $this->cleanup($cleanup);
        $this->asPerson(self::SURVIVOR, function (): void {
            Hilos::$db->identities[$this->identityId]->getObject()->providerName = 'Current provider';
            Hilos::$db->identities[$this->identityId]->getObject()->sync();
        });
        $before = $this->snapshot();
        $this->asPerson(self::SURVIVOR, static fn () => AccountMergeAdmission::apply(self::SURVIVOR, $handover));
        self::assertSame('Current provider', Hilos::$db->identities[$this->identityId]->providerName);
        self::assertSame($before, $this->snapshot());
        self::assertNull($this->asPerson(self::LOSER, static fn () => AccountMergeGiving::copy($give->id, self::LOSER)));
        self::assertNull($this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER)));
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        self::assertSame('2', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_fence`')->field('n'));
        self::assertTrue(Hilos::$db->users[self::LOSER]->block);
        self::assertSame(self::SURVIVOR, Hilos::$db->userMerges[self::LOSER]->survivorUserId);
    }

    /** @return array<string, array{bool}> A losing transaction may recover only a strictly bound result */
    public static function collisionDefects(): array
    {
        return ['foreign owner' => [true], 'malformed payload' => [false]];
    }

    /** @param bool $foreignOwner Which part of the colliding winner violates the cleanup result contract */
    #[DataProvider('collisionDefects')]
    public function testDuplicateRollbackDoesNotReturnForeignOrMalformedWinner(bool $foreignOwner): void
    {
        [, $give, $cleanup] = $this->received();
        $copy = Hilos::$db->accountMergeCopies[$give->id]->getObject();
        $digest = $this->copyDigest($give->id);
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $injected = false;
        SourceChangeBus::subscribe(new MergeCleanupMirror(static function (SourceChange $change) use (
            $cleanup, $foreignOwner, &$injected,
        ): void {
            if (!$injected && $change->sourceKey === HilosDbContext::accountMergeCopies && $change->mutationType === TableMutationType::Delete) {
                $injected = true;
                Database::sql('INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
                    . ' VALUES (?, ?, ?, ?)', [$cleanup->id, $foreignOwner ? 'foreign' : $cleanup->owner->agentId(),
                        $foreignOwner ? '{"cleanup":"cleaned"}' : '{}', 0], index: self::PEER);
            }
        }));
        $this->drain();
        $this->refused(InvalidFormatException::class, fn () => $this->cleanup($cleanup));
        self::assertTrue($injected);
        self::assertSame($digest, $this->copyDigest($give->id));
        self::assertSame($copy, Hilos::$db->accountMergeCopies[$give->id]->getObject());
        self::assertTrue($copy->isRelated());
        self::assertFalse($this->personAgent(self::LOSER)->shouldStop());
        self::assertSame([], $this->drain());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Current metadata bypasses held absence and an already established RR snapshot without hydrating private state. */
    public function testStoredMetadataDoesNotTrustMissingMirrorOrOldSnapshot(): void
    {
        [, $give] = $this->given();
        $row = Database::sql('SELECT * FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id])->firstRow();
        Database::sql('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id], index: self::PEER);
        $this->freshContext();
        self::assertNull(Hilos::$db->accountMergeCopies[$give->id] ?? null);
        Database::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Database::transactionStart();
        try {
            self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy`')->field('n'));
            Database::sql('INSERT INTO `hilos_account_merge_copy` (`assignment_id`, `owner_agent_id`, `created_at`, `payload_json`)'
                . ' VALUES (?, ?, ?, ?)', [$row['assignment_id'], $row['owner_agent_id'], $row['created_at'], $row['payload_json']],
                index: self::PEER);
            self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_merge_copy`')->field('n'));
            self::assertTrue(Hilos::$db->accountMergeCopies->hasStored($give->id));
            self::assertNull(Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeCopies)->get($give->id));
        } finally {
            Database::transactionRollback();
        }
    }

    /**
     * @return array{AccountOperation, AccountOperationAssignment, AccountOperationAssignment} Real give and cleanup obligation
     */
    private function given(): array
    {
        [$operation, $give] = $this->ready();
        $this->give($give);

        return [$operation, $give, $this->assignment($operation->id, AccountMergeDuty::Cleanup, self::LOSER)];
    }

    /** @return array{AccountOperation, AccountOperationAssignment, AccountOperationAssignment, AccountOperationAssignment} Real receipt */
    private function received(): array
    {
        [$operation, $give, $cleanup] = $this->given();
        $receive = $this->assignment($operation->id, AccountMergeDuty::Receive, self::SURVIVOR);
        $handover = $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER));
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->receiveForMerge($receive->id, $handover));

        return [$operation, $give, $cleanup, $receive];
    }

    /**
     * @param AccountOperationAssignment $cleanup Immutable own cleanup assignment
     * @return AccountOperationResult Committed or saved cleanup, through the production entry
     */
    private function cleanup(AccountOperationAssignment $cleanup): AccountOperationResult
    {
        return $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->cleanupForMerge($cleanup->id));
    }

    /**
     * @param int $operationId Operation receiving a prepared duty
     * @param AccountMergeDuty $duty Canonical duty
     * @param int $userId Its immutable personal owner
     * @return AccountOperationAssignment New immutable go duty
     */
    private function assignment(int $operationId, AccountMergeDuty $duty, int $userId): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments->actions->record($operationId, AccountMergeStage::Go->value, $duty->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId), $userId,
            AccountOperationAssignmentPayload::fromJson('{}'));
    }

    /**
     * @param ?SecondFactorFate $fate Protection outcome for the real preparation pair
     * @param ?PasswordFate $password Password outcome from the immutable choice
     * @param int $survivor Target account of this operation
     * @param int $loser Source account of this operation
     * @return array{AccountOperation, AccountOperationAssignment} Prepared operation and give address
     */
    private function ready(
        ?SecondFactorFate $fate = SecondFactorFate::BOTH, ?PasswordFate $password = null,
        int $survivor = self::SURVIVOR, int $loser = self::LOSER,
    ): array
    {
        $operation = AccountMergeOpening::open($survivor, $loser, new AccountMergeChoice($password, $fate, null, null));
        foreach ([[$survivor, AccountMergeDuty::PrepareSurvivor], [$loser, AccountMergeDuty::PrepareLoser]] as [$user, $duty]) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            $result = $this->asPerson($user, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $give = Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$loser),
            $loser, AccountOperationAssignmentPayload::fromJson('{}'));

        return [$operation, $give];
    }

    /**
     * @param AccountOperationAssignment $give Assignment delivered to its actual person owner
     * @return AccountOperationResult Durable source fact
     */
    private function give(AccountOperationAssignment $give): AccountOperationResult
    {
        return $this->asPerson($give->userId, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($give->id));
    }

    /**
     * @param class-string<Throwable> $exception Expected failure family
     * @param callable $work Operation to refuse
     * @return Throwable Original failure
     */
    private function refused(string $exception, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());

            return $failure;
        }
        self::fail('An invalid cleanup was accepted');
    }

    /**
     * @param int $assignmentId Fault target
     * @param string $column Fixture coordinate to corrupt
     * @param int|string $value Foreign or malformed coordinate
     */
    private function assignmentFault(int $assignmentId, string $column, int|string $value): void
    {
        Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?', [$value, $assignmentId]);
    }

    /**
     * @param int $assignmentId Fault target
     * @param string $column Fixture coordinate to corrupt
     * @param string $value Foreign or malformed coordinate
     */
    private function resultFault(int $assignmentId, string $column, string $value): void
    {
        Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `assignment_id` = ?', [$value, $assignmentId]);
    }

    /** Opens an independent committed observer on the same database. */
    private function peerConnection(): void
    {
        $config = Database::getConnectionConfig(0);
        Database::configure(index: self::PEER, host: $config->host, user: $config->user, password: $config->password,
            database: $config->database, port: $config->port, charset: $config->charset, socket: $config->socket);
        Database::connect(self::PEER);
    }

    /** @return array<string, string> Persisted oracle digests, so assertions cannot print secret-bearing rows */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = hash('sha256', serialize(Database::sql('SELECT * FROM `' . $table . '` ORDER BY 1')->rows()));
        }

        return $rows;
    }

    /**
     * @param int $assignmentId Copy whose physical payload is observed independently
     * @return ?string Private payload digest, or null after removal
     */
    private function copyDigest(int $assignmentId): ?string
    {
        return Database::sql('SELECT SHA2(`payload_json`, 256) AS digest FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?',
            [$assignmentId])->firstRow()['digest'] ?? null;
    }

    /** @return list<string> Queued signal names, without private account material */
    private function drain(): array
    {
        $names = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $frame->signalName->getName();
        }

        return $names;
    }

    /** @param bool $replay Whether every mutable/business or copy access must fail */
    private function freshContext(bool $replay = false): void
    {
        Hilos::$db = $replay ? new MergeCleanupReplayContext() : new MergeCleanupContext();
        Hilos::$db->configure();
    }

    /** @param bool $down Whether to remove dependent fixtures in reverse order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Actual coordinator claims, with no synthetic result producer. */
final class MergeCleanupCoordinator extends AbstractSessionsLibraryAgent
{
}

/** The real framework mounts, with no replacement business behavior. */
final class MergeCleanupContext extends HilosDbContext
{
}

/** Saved cleanup requires only immutable operation, assignment and result metadata. */
final class MergeCleanupReplayContext extends HilosDbContext
{
    /**
     * @param string $name Requested collection
     * @return mixed Immutable metadata available on replay
     */
    public function __get(string $name): mixed
    {
        if (!in_array($name, [self::accountOperations, self::accountOperationAssignments, self::accountOperationResults], true)) {
            throw new RuntimeException('Cleanup replay read mutable state: ' . $name);
        }

        return parent::__get($name);
    }
}

/** Observes the uncommitted deletion/result window through the existing mirror seam. */
final class MergeCleanupMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe Assertion at the actual ORM write */
    public function __construct(private readonly Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Actual uncommitted or restored fact
     * @param SourceChangeProvenance $provenance Writer provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

/** Reactions see only committed facts; rollback must leave this empty. */
final class MergeCleanupReaction implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Committed changes */
    public array $seen = [];

    /**
     * @param SourceChange $change Committed fact
     * @param SourceChangeProvenance $provenance Writer provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Error;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Auth\AccountOperation\AccountMergeTransferRows;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** Real preparations, scoped item transfers, commit/replay and rollback over the mounted framework ORM. */
final class AccountMergeTransferIntegrationTest extends FrameworkIntegrationTestCase
{
    public const array TRANSFER_TABLES = [
        HilosDbContext::identities => 'hilos_identity', HilosDbContext::passkeyCredentials => 'hilos_passkey_credential',
        HilosDbContext::secondFactors => 'hilos_second_factor', HilosDbContext::secondFactorBackupCodes => 'hilos_second_factor_backup_code',
    ];

    private const string COORDINATOR_ID = 'merge-transfer-coordinator';
    private const int SURVIVOR_ID = 7;
    private const int LOSER_ID = 9;
    private const int FOREIGN_ID = 11;
    private const int PEER_INDEX = 2;
    private const array BUSINESS_TABLES = [
        'hilos_user', 'hilos_identity', 'hilos_passkey_credential', 'hilos_second_factor', 'hilos_second_factor_backup_code',
    ];
    private const array TABLES = [
        ...self::BUSINESS_TABLES, 'hilos_user_merge', 'hilos_account_deletion', 'hilos_second_factor_reset',
        'hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result',
    ];

    private ?HilosDbContext $previousDb;
    private ?SignalRouter $previousRouter;
    private int $frameToken;
    private AccountMergeTransferPerson $loser;
    private AccountMergeTransferPerson $survivor;
    private int $identityId;
    private int $passkeyId;
    private int $factorId;
    private int $unconfirmedId;
    private int $backupCodeId;

    /** Owns every stub and claim needed by preparation rather than inheriting another test's schema. */
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
        $this->mountFreshContext();
        OwnershipDeclaration::claimDb(AccountMergeTransferCoordinator::class, self::COORDINATOR_ID);
        $this->loser = new AccountMergeTransferPerson(self::LOSER_ID);
        $this->survivor = new AccountMergeTransferPerson(self::SURVIVOR_ID);
        OwnershipDeclaration::claimAll($this->loser);
        OwnershipDeclaration::claimAll($this->survivor);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        foreach ([self::SURVIVOR_ID, self::LOSER_ID, self::FOREIGN_ID] as $id) {
            Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [$id, 'Transfer fixture']);
        }
        $this->identityId = $this->seedIdentity(self::LOSER_ID, IdentityType::PASSKEY);
        $this->passkeyId = $this->seedPasskey($this->identityId, self::LOSER_ID);
        $this->factorId = $this->seedFactor(self::LOSER_ID);
        $this->unconfirmedId = $this->seedFactor(self::LOSER_ID, false);
        $this->seedFactor(self::SURVIVOR_ID);
        Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `used_at`, `created_at`) VALUES (?, ?, ?, ?)',
            [self::LOSER_ID, 'fixture-code', TimeHelper::nowMs(), TimeHelper::nowMs()]);
        $this->backupCodeId = Database::lastInsertId();
    }

    /** Closes handlers before DDL and restores context, claims and source interests on every exit. */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(0);
            Database::handlerEnd();
            $this->stubs(true);
        } finally {
            ExecutionContext::pop($this->frameToken);
            foreach ([self::COORDINATOR_ID, $this->loser->getId(), $this->survivor->getId()] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$db = $this->previousDb;
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /** A real preparation pair admits only its manifest and commits the existing typed payload without transferring rows. */
    public function testPreparedGiveCapturesTheManifestAndScopesTheRight(): void
    {
        [$operation, $give] = $this->ready();
        $other = $this->seedIdentity(self::LOSER_ID);
        $foreign = $this->seedIdentity(self::FOREIGN_ID);
        $before = $this->businessSnapshot();
        $leaked = null;
        $closedAtCommit = false;
        $closedBeforeResult = false;
        SourceChangeBus::subscribe(new AccountMergeTransferMirror(function (SourceChange $change) use (&$leaked, &$closedBeforeResult): void {
            if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === HilosDbContext::accountOperationResults
                && $change->mutationType === TableMutationType::Create) {
                self::assertTrue(Database::hasOpenTransactions());
                $this->assertClosed($leaked);
                $closedBeforeResult = true;
            }
        }));
        $result = $this->asPerson($this->loser, function () use ($operation, $give, $other, $foreign, &$leaked, &$closedAtCommit) {
            return AccountMergeTransfer::run($give->id, $this->selection(), function (AccountMergeTransfer $transfer) use (
                $operation, $give, $other, $foreign, &$leaked, &$closedAtCommit,
            ): AccountOperationResultPayload {
                $leaked = $transfer;
                $this->assertRefused(Error::class, static fn () => clone $transfer);
                self::assertSame([$give->id, $operation->id, self::LOSER_ID, self::SURVIVOR_ID],
                    [$transfer->assignmentId, $transfer->operationId, $transfer->loserUserId, $transfer->survivorUserId]);
                foreach ([HilosDbContext::identities => $this->identityId, HilosDbContext::secondFactors => $this->factorId,
                    HilosDbContext::secondFactorBackupCodes => $this->backupCodeId] as $collection => $id) {
                    $transfer->guardRow($collection, Hilos::$db->{$collection}[$id]->getObject());
                }
                $item = Hilos::$db->identities[$this->identityId]->getObject();
                $item->userId = self::SURVIVOR_ID;
                try {
                    $transfer->guardRow(HilosDbContext::identities, $item);
                } finally {
                    $item->userId = self::LOSER_ID;
                }
                foreach ([$other, $foreign] as $id) {
                    $this->assertRefused(WriteNotAllowedException::class,
                        static fn () => $transfer->guardRow(HilosDbContext::identities, Hilos::$db->identities[$id]->getObject()));
                }
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::secondFactors, $item));
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::users, $item));
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, ObjectIdentity::create()));
                $this->asPerson($this->survivor, fn () => $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, $item)));
                $transfer->guardRow(HilosDbContext::identities, $item);
                // The anchor is still in the source set: a credential cannot precede its identity.
                $this->assertRefused(WriteNotAllowedException::class, fn () => $transfer->guardRow(
                    HilosDbContext::passkeyCredentials, Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject(),
                ));
                Database::afterCommit(function () use ($transfer, $item, &$closedAtCommit): void {
                    $this->assertRefused(WriteNotAllowedException::class,
                        static fn () => $transfer->guardRow(HilosDbContext::identities, $item));
                    $closedAtCommit = true;
                }, 0);

                return self::payload();
            });
        });
        self::assertTrue($closedBeforeResult);
        self::assertTrue($closedAtCommit);
        self::assertSame(self::payload()->toJson(), $result->payload->toJson());
        self::assertSame($before, $this->businessSnapshot());
        $this->assertClosed($leaked);
    }

    /**
     * @param string $selection Independent subset admitted without selecting every source row
     */
    #[DataProvider('independentSelections')]
    public function testEmptyAndIndependentSelectionsRemainValid(string $selection): void
    {
        [, $give] = $this->ready($selection === 'empty' || $selection === 'identity' ? SecondFactorFate::SURVIVOR : SecondFactorFate::BOTH);
        $rows = match ($selection) {
            'empty' => new AccountMergeTransferRows(),
            'identity' => new AccountMergeTransferRows(identities: [$this->identityId]),
            'factor' => new AccountMergeTransferRows(secondFactors: [$this->factorId]),
            'codes' => new AccountMergeTransferRows(secondFactorBackupCodes: [$this->backupCodeId]),
        };
        $before = $this->businessSnapshot();
        $result = $this->asPerson($this->loser, static fn () => AccountMergeTransfer::run($give->id, $rows, self::payload(...)));
        self::assertSame(self::payload()->toJson(), $result->payload->toJson());
        self::assertSame($before, $this->businessSnapshot());
    }

    /** @return iterable<string, array{string}> The four lists are independent source subsets */
    public static function independentSelections(): iterable
    {
        foreach (['empty', 'identity', 'factor', 'codes'] as $selection) {
            yield $selection => [$selection];
        }
    }

    /**
     * @param bool $survivor Side whose preparation is damaged
     * @param string $damage Metadata defect to arrange at the SQL fixture boundary
     */
    #[DataProvider('preparationDefects')]
    public function testBothExactPreparationAddressesAndResultsAreRequired(bool $survivor, string $damage): void
    {
        [$operation, $give] = $this->ready();
        $duty = $survivor ? AccountMergeDuty::PrepareSurvivor : AccountMergeDuty::PrepareLoser;
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        $id = $assignment->id;
        $other = $survivor ? self::LOSER_ID : self::SURVIVOR_ID;
        match ($damage) {
            'assignment absent' => (function () use ($id): void {
                Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$id]);
                Database::sqlRun('DELETE FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$id]);
            })(),
            'result absent' => Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$id]),
            'refused' => Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                [AccountMergePreparationResult::refused(AccountMergeRefusal::Missing)->toJson(), $id]),
            'malformed' => Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                ['{"prepare":"reserved"}', $id]),
            'wrong user' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?', [$other, $id]),
            'wrong owner type' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_type` = ? WHERE `id` = ?',
                [self::COORDINATOR_ID, $id]),
            'wrong owner index' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?',
                [(string)$other, $id]),
            'wrong result owner' => Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                [$this->loser->getId() . '-foreign', $id]),
            'wrong stage' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `stage` = ? WHERE `id` = ?',
                [AccountMergeStage::Cancelling->value, $id]),
            'wrong operation' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `operation_id` = ? WHERE `id` = ?',
                [$this->otherOperation()->id, $id]),
        };
        $this->mountFreshContext();
        $this->assertAdmissionRefused($give->id, new AccountMergeTransferRows(),
            $damage === 'malformed' ? InvalidFormatException::class : ValidationException::class);
    }

    /** @return iterable<string, array{bool, string}> Each side independently owes every exact metadata condition */
    public static function preparationDefects(): iterable
    {
        foreach ([false, true] as $survivor) {
            foreach (['assignment absent', 'result absent', 'refused', 'malformed', 'wrong user', 'wrong owner type',
                'wrong owner index', 'wrong result owner', 'wrong stage', 'wrong operation'] as $damage) {
                yield ($survivor ? 'survivor ' : 'loser ') . $damage => [$survivor, $damage];
            }
        }
    }

    /**
     * @param string $damage Immutable assignment or operation defect
     */
    #[DataProvider('assignmentDefects')]
    public function testOnlyTheCanonicalLoserGiveOfAMergeCanRun(string $damage): void
    {
        [$operation, $give] = $this->ready();
        match ($damage) {
            'user' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `user_id` = ? WHERE `id` = ?',
                [self::SURVIVOR_ID, $give->id]),
            'owner index' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `id` = ?',
                [(string)self::SURVIVOR_ID, $give->id]),
            'owner components' => Database::sqlRun(
                'UPDATE `hilos_account_operation_assignment` SET `owner_type` = ?, `owner_index` = NULL WHERE `id` = ?',
                [$this->loser->getId(), $give->id]),
            'key' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `assignment_key` = ? WHERE `id` = ?',
                [AccountMergeDuty::PrepareLoser->value, $give->id]),
            'assignment stage' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `stage` = ? WHERE `id` = ?',
                [AccountMergeStage::Preparing->value, $give->id]),
            'kind' => Database::sqlRun('UPDATE `hilos_account_operation` SET `kind` = ? WHERE `id` = ?',
                [AccountOperationKind::Erasure->value, $operation->id]),
            'same accounts' => Database::sqlRun('UPDATE `hilos_account_operation` SET `root_user_id` = ? WHERE `id` = ?',
                [self::LOSER_ID, $operation->id]),
            'zero source' => Database::sqlRun('UPDATE `hilos_account_operation` SET `loser_user_id` = 0 WHERE `id` = ?', [$operation->id]),
            'zero target' => Database::sqlRun('UPDATE `hilos_account_operation` SET `root_user_id` = 0 WHERE `id` = ?', [$operation->id]),
            'other operation' => Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `operation_id` = ? WHERE `id` = ?',
                [$this->otherOperation()->id, $give->id]),
        };
        $this->mountFreshContext();
        $failure = $this->assertAdmissionRefused($give->id, new AccountMergeTransferRows(), AccountOperationStepRefusedException::class);
        self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
    }

    /** @return iterable<string, array{string}> Exact immutable identity components */
    public static function assignmentDefects(): iterable
    {
        foreach (['user', 'owner index', 'owner components', 'key', 'assignment stage', 'kind', 'same accounts',
            'zero source', 'zero target', 'other operation'] as $damage) {
            yield $damage => [$damage];
        }
    }

    /** Neither an absent executor nor another participant may consume even an already saved preparation result. */
    public function testExecutorAndUnknownOrAlreadyCompletedForeignDutyAreRefused(): void
    {
        [$operation, $give] = $this->ready();
        foreach ([null, $this->survivor->getId()] as $executor) {
            ExecutionContext::run(new ExecutionFrame(agentId: $executor), function () use ($give, $executor): void {
                $failure = $this->assertRefused(AccountOperationStepRefusedException::class,
                    static fn () => AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(), self::payload(...)));
                self::assertSame($executor === null ? AccountOperationStepRefusal::NoParticipant : AccountOperationStepRefusal::ForeignAssignment,
                    $failure->reason);
            });
        }
        $failure = $this->assertAdmissionRefused($give->id + 100, new AccountMergeTransferRows(), AccountOperationStepRefusedException::class);
        self::assertSame(AccountOperationStepRefusal::UnknownAssignment, $failure->reason);
        $prepared = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value,
        );
        $failure = $this->assertAdmissionRefused($prepared->id, new AccountMergeTransferRows(), AccountOperationStepRefusedException::class);
        self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $failure->reason);
    }

    /**
     * @param string $stage Persisted stage hidden from the local mirror
     * @param bool $finished Whether the operation was also finished
     */
    #[DataProvider('closedStages')]
    public function testFreshMetadataRefusesAStaleGoMirror(string $stage, bool $finished): void
    {
        [$operation, $give] = $this->ready();
        $this->connectPeer();
        Database::sqlRun('UPDATE `hilos_account_operation` SET `stage` = ?, `finished_at` = ? WHERE `id` = ?',
            [$stage, $finished ? TimeHelper::nowMs() : null, $operation->id], index: self::PEER_INDEX);
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
        self::assertNull($operation->finishedAt);
        $failure = $this->assertAdmissionRefused($give->id, $this->selection(), AccountOperationStepRefusedException::class);
        self::assertSame(AccountOperationStepRefusal::ClosedStage, $failure->reason);
        self::assertSame($stage, $operation->stage);
    }

    /** @return iterable<string, array{string, bool}> Closed or changed persisted metadata */
    public static function closedStages(): iterable
    {
        yield 'preparing' => [AccountMergeStage::Preparing->value, false];
        yield 'cancelling' => [AccountMergeStage::Cancelling->value, false];
        yield 'finished go' => [AccountMergeStage::Go->value, true];
    }

    /**
     * @param string $grant Replacement grant at admission
     */
    #[DataProvider('nonGivingGrants')]
    public function testAdmissionRequiresTheExecutorsExactSourceSetUpdate(string $grant): void
    {
        [, $give] = $this->ready();
        TruthSourceRegistry::unregister(HilosDbContext::identities, $this->loser->getId());
        if ($grant !== 'revoked' && $grant !== 'ambient') {
            $keys = match ($grant) {
                'whole' => TruthSourceKeys::all(),
                'row' => TruthSourceKeys::listed((string)$this->identityId),
                'foreign set' => TruthSourceKeys::set((string)self::SURVIVOR_ID),
                'remove only' => TruthSourceKeys::set((string)self::LOSER_ID),
            };
            TruthSourceRegistry::register(HilosDbContext::identities, $keys, $this->loser->getId(),
                new TruthSourceOperations($grant === 'remove only' ? TruthSourceOperation::Remove : TruthSourceOperation::Update));
        }
        // The base fixture's ambient whole grant still exists in every case.
        $this->assertAdmissionRefused($give->id, new AccountMergeTransferRows(identities: [$this->identityId]), WriteNotAllowedException::class);
    }

    /** @return iterable<string, array{string}> Grants that never substitute for the executor's exact Update set */
    public static function nonGivingGrants(): iterable
    {
        foreach (['whole', 'row', 'foreign set', 'remove only', 'revoked', 'ambient'] as $grant) {
            yield $grant => [$grant];
        }
    }

    /** Losing a grant during a callback invalidates its right immediately; restoring it does not revive a closed right. */
    public function testEveryGuardChecksTheLiveGrantAndExecutor(): void
    {
        [, $give] = $this->ready();
        $leaked = null;
        $this->asPerson($this->loser, function () use ($give, &$leaked): void {
            AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(identities: [$this->identityId]), function ($transfer) use (&$leaked) {
                $leaked = $transfer;
                $item = Hilos::$db->identities[$this->identityId]->getObject();
                $transfer->guardRow(HilosDbContext::identities, $item);
                TruthSourceRegistry::unregister(HilosDbContext::identities, $this->loser->getId());
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, $item));
                TruthSourceRegistry::register(HilosDbContext::identities, TruthSourceKeys::set((string)self::LOSER_ID), $this->loser->getId(),
                    new TruthSourceOperations(TruthSourceOperation::Remove));
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, $item));
                OwnershipDeclaration::claimAll($this->loser);
                $transfer->guardRow(HilosDbContext::identities, $item);

                return self::payload();
            });
        });
        $this->assertClosed($leaked);
    }

    /**
     * @param string $defect Source selection defect
     */
    #[DataProvider('manifestDefects')]
    public function testManifestRefusesUnfitRowsBeforeTheConsumer(string $defect): void
    {
        [, $give] = $this->ready($defect === 'fate' ? SecondFactorFate::SURVIVOR : SecondFactorFate::BOTH);
        $rows = match ($defect) {
            'dirty' => (function (): AccountMergeTransferRows {
                Hilos::$db->identities[$this->identityId]->getObject()->identifier = 'dirty@example.test';
                return new AccountMergeTransferRows(identities: [$this->identityId]);
            })(),
            'foreign' => new AccountMergeTransferRows(identities: [$this->seedIdentity(self::FOREIGN_ID)]),
            'target' => new AccountMergeTransferRows(identities: [$this->seedIdentity(self::SURVIVOR_ID)]),
            'missing' => new AccountMergeTransferRows(identities: [$this->identityId + 100]),
            'passkey without anchor' => new AccountMergeTransferRows(passkeyCredentials: [$this->passkeyId]),
            'passkey wrong anchor' => (function (): AccountMergeTransferRows {
                $other = $this->seedIdentity(self::LOSER_ID);
                return new AccountMergeTransferRows(identities: [$other], passkeyCredentials: [$this->passkeyId]);
            })(),
            'unconfirmed' => new AccountMergeTransferRows(secondFactors: [$this->unconfirmedId]),
            'not reserved' => new AccountMergeTransferRows(secondFactors: [$this->seedFactor(self::LOSER_ID)]),
            'fate' => new AccountMergeTransferRows(secondFactors: [$this->factorId]),
            'code fate' => (function () use ($give): AccountMergeTransferRows {
                Database::sqlRun('UPDATE `hilos_account_operation` SET `merge_choice` = ? WHERE `id` = ?',
                    [(new AccountMergeChoice(null, SecondFactorFate::SURVIVOR, true, true))->toJson(), $give->operationId]);
                return new AccountMergeTransferRows(secondFactorBackupCodes: [$this->backupCodeId]);
            })(),
            'no factor snapshot' => (function () use ($give): AccountMergeTransferRows {
                $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
                    $give->operationId, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value,
                );
                Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                    [AccountMergePreparationResult::reserved(null, [])->toJson(), $assignment->id]);
                $this->mountFreshContext();
                return new AccountMergeTransferRows(secondFactorBackupCodes: [$this->backupCodeId]);
            })(),
            'confirmation removed' => (function (): AccountMergeTransferRows {
                Database::sqlRun('UPDATE `hilos_second_factor` SET `confirmed_at` = NULL WHERE `id` = ?', [$this->factorId]);
                $this->mountFreshContext();
                return new AccountMergeTransferRows(secondFactors: [$this->factorId]);
            })(),
        };
        $this->assertAdmissionRefused($give->id, $rows, WriteNotAllowedException::class);
    }

    /** @return iterable<string, array{string}> Manifest conditions independent of the future save pipeline */
    public static function manifestDefects(): iterable
    {
        foreach (['dirty', 'foreign', 'target', 'missing', 'passkey without anchor', 'passkey wrong anchor', 'unconfirmed',
            'not reserved', 'fate', 'code fate', 'no factor snapshot', 'confirmation removed'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** Recreating a selected id produces another Object, not another member of the captured manifest. */
    public function testDeletedAndRecreatedIdDoesNotReplaceCapturedMembership(): void
    {
        [, $give] = $this->ready();
        $id = $this->seedIdentity(self::LOSER_ID);
        $this->asPerson($this->loser, function () use ($give, $id): void {
            AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(identities: [$id]), function ($transfer) use ($id) {
                $old = Hilos::$db->identities[$id]->getObject();
                $transfer->guardRow(HilosDbContext::identities, $old);
                $old->delete();
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, $old));
                Database::sqlRun('INSERT INTO `hilos_identity` (`id`, `user_id`, `type`, `identifier`, `created_at`, `updated_at`)'
                    . ' VALUES (?, ?, ?, ?, ?, ?)', [$id, self::LOSER_ID, IdentityType::MAGIC_LINK, 'replacement@example.test',
                        TimeHelper::nowMs(), TimeHelper::nowMs()]);
                $this->mountFreshContext();
                $replacement = Hilos::$db->identities[$id]->getObject();
                self::assertNotSame($old, $replacement);
                self::assertTrue($replacement->isRelated());
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $transfer->guardRow(HilosDbContext::identities, $replacement));
                $this->assertRefused(WriteNotAllowedException::class,
                    fn () => Hilos::$db->identities[$id]->actions->transferForMerge($transfer));
                $replacement->userId = self::SURVIVOR_ID;
                $this->assertRefused(WriteNotAllowedException::class, fn () => $replacement->syncForMerge($transfer));
                self::assertSame(self::LOSER_ID, $replacement->userId);
                self::assertFalse($replacement->hasChanges());
                $this->assertRefused(WriteNotAllowedException::class, fn () => $old->syncForMerge($transfer));

                return self::payload();
            });
        });
    }

    /** Saved results precede a closed stage and a fresh invalid selection, across a real reconnect. */
    public function testRepeatAfterCommitReconnectAndFinishSkipsAllNewWork(): void
    {
        [$operation, $give] = $this->ready();
        $runs = 0;
        $resultsPublished = 0;
        SourceChangeBus::subscribe(new AccountMergeTransferMirror(static function (SourceChange $change) use (&$resultsPublished): void {
            if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === HilosDbContext::accountOperationResults
                && $change->mutationType === TableMutationType::Create) {
                $resultsPublished++;
            }
        }));
        $work = static function () use (&$runs): AccountOperationResultPayload {
            $runs++;
            return self::payload();
        };
        $result = $this->asPerson($this->loser, fn () => AccountMergeTransfer::run($give->id, $this->selection(), $work));
        $invalid = new AccountMergeTransferRows(identities: [$this->identityId + 100]);
        self::assertSame($result->id, $this->asPerson($this->loser, static fn () => AccountMergeTransfer::run($give->id, $invalid, $work))->id);
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        Database::close();
        Database::connect();
        $this->mountFreshContext();
        $stored = $this->businessSnapshot();
        self::assertSame($result->id, $this->asPerson($this->loser, static fn () => AccountMergeTransfer::run($give->id, $invalid, $work))->id);
        self::assertSame(1, $runs);
        self::assertSame(1, $resultsPublished);
        self::assertSame($stored, $this->businessSnapshot());
        self::assertSame(3, (int)Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_result`')->firstRow()['n']);
    }

    /** An original consumer failure rolls back its result and closes the right; a fresh attempt may commit once. */
    public function testConsumerFailureClosesTheRightAndAllowsANewAttempt(): void
    {
        [, $give] = $this->ready();
        $failure = new RuntimeException('Consumer failed');
        $leaked = null;
        $before = $this->businessSnapshot();
        $caught = $this->assertRefused(RuntimeException::class, function () use ($give, $failure, &$leaked) {
            return $this->asPerson($this->loser, function () use ($give, $failure, &$leaked) {
                return AccountMergeTransfer::run($give->id, $this->selection(), static function ($transfer) use ($failure, &$leaked) {
                    $leaked = $transfer;
                    throw $failure;
                });
            });
        });
        self::assertSame($failure, $caught);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        self::assertSame($before, $this->businessSnapshot());
        $this->assertClosed($leaked);
        $this->asPerson($this->loser, fn () => AccountMergeTransfer::run($give->id, $this->selection(), self::payload(...)));
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        $this->assertClosed($leaked);
    }

    /**
     * @param bool $commit Whether the consumer improperly commits rather than rolls back
     */
    #[DataProvider('earlyEndings')]
    public function testConnectionScopedHooksCloseTheRightBeforeTheCallbackReturns(bool $commit): void
    {
        [, $give] = $this->ready();
        $this->connectPeer();
        $failure = new RuntimeException('Stop after improper transaction end');
        $this->asPerson($this->loser, function () use ($give, $commit, $failure): void {
            Database::useConnection(self::PEER_INDEX);
            try {
                $caught = $this->assertRefused(RuntimeException::class, fn () => AccountMergeTransfer::run(
                    $give->id, new AccountMergeTransferRows(identities: [$this->identityId]), function ($transfer) use ($commit, $failure) {
                        $item = Hilos::$db->identities[$this->identityId]->getObject();
                        // An ambient peer must not close an otherwise live PRIMARY right.
                        $transfer->guardRow(HilosDbContext::identities, $item);
                        $commit ? Database::transactionCommit(0) : Database::transactionRollback(0);
                        $this->assertRefused(WriteNotAllowedException::class,
                            static fn () => $transfer->guardRow(HilosDbContext::identities, $item));
                        throw $failure;
                    },
                ));
                self::assertSame($failure, $caught);
            } finally {
                Database::useConnection(0);
            }
        });
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
    }

    /** @return iterable<string, array{bool}> Each transaction exit closes the attempt-local right */
    public static function earlyEndings(): iterable
    {
        yield 'commit' => [true];
        yield 'rollback' => [false];
    }

    /** An independent SQL session cannot change the operation while give holds its metadata shared lock. */
    public function testMetadataSharedLockLastsThroughTheConsumerAndResultTransaction(): void
    {
        [$operation, $give] = $this->ready();
        $this->connectPeer();
        $this->asPerson($this->loser, function () use ($operation, $give): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function () use ($operation) {
                Database::transactionStart(self::PEER_INDEX);
                try {
                    $failure = $this->assertRefused(LockWaitTimeoutException::class, static fn () => Database::sql(
                        'SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT',
                        [$operation->id], index: self::PEER_INDEX,
                    ));
                    self::assertSame(LockWaitTimeoutException::MYSQL_ERROR_CODE, $failure->getCode());
                } finally {
                    Database::transactionRollback(self::PEER_INDEX);
                }
                // Admission does not lock a foreign business set or the accounts themselves.
                Database::transactionStart(self::PEER_INDEX);
                try {
                    self::assertCount(3, Database::sql('SELECT `id` FROM `hilos_user` FOR UPDATE NOWAIT', index: self::PEER_INDEX)->rows());
                    self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_identity` FOR UPDATE NOWAIT', index: self::PEER_INDEX)->rows());
                    self::assertCount(3, Database::sql('SELECT `id` FROM `hilos_second_factor` FOR UPDATE NOWAIT',
                        index: self::PEER_INDEX)->rows());
                    self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_passkey_credential` FOR UPDATE NOWAIT',
                        index: self::PEER_INDEX)->rows());
                    self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_second_factor_backup_code` FOR UPDATE NOWAIT',
                        index: self::PEER_INDEX)->rows());
                } finally {
                    Database::transactionRollback(self::PEER_INDEX);
                }

                return self::payload();
            });
        });
        Database::transactionStart(self::PEER_INDEX);
        try {
            self::assertSame([['id' => (string)$operation->id]], Database::sql(
                'SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT',
                [$operation->id], index: self::PEER_INDEX,
            )->rows());
        } finally {
            Database::transactionRollback(self::PEER_INDEX);
        }
    }

    /** Same primary rows carry all secret material and counters through one committed give. */
    public function testRealItemActionsTransferAllFourRowsAndPreserveTheirFullStoredData(): void
    {
        [$operation, $give] = $this->ready();
        $before = $this->businessSnapshot();
        $objects = $this->selectedObjects();
        $reaction = $this->observeAnnouncements();
        $leaked = null;
        $result = $this->asPerson($this->loser, function () use ($give, &$leaked, $reaction) {
            return AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use (&$leaked, $reaction) {
                $leaked = $transfer;
                $this->transferSelected($transfer);
                self::assertSame([], $reaction->seen);
                self::assertSame([], $this->drainTransferFrames());
                $identity = Hilos::$db->identities[$this->identityId];
                $this->assertRefused(WriteNotAllowedException::class, fn () => $identity->actions->transferForMerge($transfer));
                $this->assertRefused(WriteNotAllowedException::class, fn () => $identity->getObject()->syncForMerge($transfer));
                $this->assertRefused(WriteNotAllowedException::class, fn () => $identity->actions->rememberProviderName('foreign edit'));
                $identity->getObject()->identifier = 'forbidden';
                $this->assertRefused(WriteNotAllowedException::class, fn () => $identity->getObject()->sync());
                self::assertFalse($identity->getObject()->hasChanges());
                self::assertSame(self::SURVIVOR_ID, $identity->userId);
                return self::payload();
            });
        });
        $after = $this->businessSnapshot();
        $expected = $before;
        foreach ($this->selectedIds() as $collection => $id) {
            $table = self::TRANSFER_TABLES[$collection];
            foreach ($expected[$table] as &$row) {
                if ((int)$row['id'] !== $id) {
                    continue;
                }
                $row['user_id'] = (string)self::SURVIVOR_ID;
                if ($collection === HilosDbContext::identities) {
                    $saved = array_values(array_filter($after[$table], static fn ($candidate) => (int)$candidate['id'] === $id))[0];
                    self::assertGreaterThan((int)$row['updated_at'], (int)$saved['updated_at']);
                    $row['updated_at'] = $saved['updated_at'];
                }
            }
            unset($row);
        }
        self::assertSame($expected, $after);
        foreach ($objects as $object) {
            self::assertTrue($object->isRelated());
            self::assertFalse($object->hasChanges());
            self::assertSame(self::SURVIVOR_ID, $object->userId);
            self::assertSame((string)self::SURVIVOR_ID, $object->storedSetTop());
        }
        self::assertSame($this->expectedAnnouncements($result->id), $reaction->seen);
        self::assertSame($reaction->seen, $this->drainTransferFrames());
        $this->assertClosed($leaked);
        $this->asPerson($this->loser, fn () => $this->assertRefused(WriteNotAllowedException::class,
            fn () => Hilos::$db->secondFactors[$this->factorId]->actions->confirm('source edit')));
        $this->asPerson($this->survivor, fn () => Hilos::$db->secondFactors[$this->factorId]->actions->confirm('target edit'));
        self::assertSame('target edit', Hilos::$db->secondFactors[$this->factorId]->label);
        self::assertSame($result->id, Hilos::$db->accountOperationResults->byAssignment($give->id)->id);
        self::assertSame(AccountMergeStage::Go->value, $operation->stage);
    }

    /**
     * @param string $collection Selected secret-bearing row kind
     * @param string $marker Literal stored fixture secret
     */
    #[DataProvider('secretRows')]
    public function testMergeSecretReadersCheckTheSourceRightAtEveryRead(string $collection, string $marker): void
    {
        [, $give] = $this->ready();
        $otherId = match ($collection) {
            HilosDbContext::identities => $this->seedIdentity(self::LOSER_ID),
            HilosDbContext::secondFactors => $this->unconfirmedId,
            HilosDbContext::secondFactorBackupCodes => $this->seedBackupCode(self::LOSER_ID),
        };
        $foreignId = match ($collection) {
            HilosDbContext::identities => $this->seedIdentity(self::FOREIGN_ID),
            HilosDbContext::secondFactors => $this->seedFactor(self::FOREIGN_ID),
            HilosDbContext::secondFactorBackupCodes => $this->seedBackupCode(self::FOREIGN_ID),
        };
        $item = Hilos::$db->{$collection}[$this->selectedIds()[$collection]];
        $leaked = null;
        $this->asPerson($this->loser, function () use ($give, $collection, $marker, $otherId, $foreignId, $item, &$leaked): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use (
                $collection, $marker, $otherId, $foreignId, $item, &$leaked,
            ) {
                $leaked = $transfer;
                self::assertSame($marker, $item->readForMerge($transfer));
                self::assertStringNotContainsString($marker, json_encode($item->toArray(), JSON_THROW_ON_ERROR));
                if ($collection === HilosDbContext::identities) {
                    self::assertSame($marker, $item->getObject()->readForMerge($transfer));
                }
                foreach ([$otherId, $foreignId] as $id) {
                    $this->assertRefused(WriteNotAllowedException::class,
                        static fn () => Hilos::$db->{$collection}[$id]->readForMerge($transfer));
                }
                $this->asPerson($this->survivor, fn () => $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $item->readForMerge($transfer)));
                TruthSourceRegistry::unregister($collection, $this->loser->getId());
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $item->readForMerge($transfer));
                OwnershipDeclaration::claimAll($this->loser);
                self::assertSame($marker, $item->readForMerge($transfer));
                $this->transferSelected($transfer);
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $item->readForMerge($transfer));
                if ($collection === HilosDbContext::identities) {
                    $this->assertRefused(WriteNotAllowedException::class, static fn () => $item->getObject()->readForMerge($transfer));
                }

                return self::payload();
            });
        });
        $this->asPerson($this->loser, fn () => $this->assertRefused(WriteNotAllowedException::class,
            static fn () => $item->readForMerge($leaked)));
    }

    /** @return iterable<string, array{string, string}> Every DB-only secret primitive */
    public static function secretRows(): iterable
    {
        yield 'identity' => [HilosDbContext::identities, 'fixture-password-hash'];
        yield 'factor' => [HilosDbContext::secondFactors, 'fixture-secret'];
        yield 'backup code' => [HilosDbContext::secondFactorBackupCodes, 'fixture-code'];
    }

    /** The existing View delegate returns the entire own set, including used rows, without another finder. */
    public function testBackupCodeListByUserIncludesUsedAndUnusedOwnRows(): void
    {
        $unused = $this->seedBackupCode(self::LOSER_ID);
        $foreign = $this->seedBackupCode(self::FOREIGN_ID);
        $rows = Hilos::$db->secondFactorBackupCodes->listByUser(self::LOSER_ID);
        self::assertSame([$this->backupCodeId, $unused], array_map(static fn ($row) => $row->id, $rows));
        self::assertNotNull($rows[0]->usedAt);
        self::assertNull($rows[1]->usedAt);
        self::assertSame([$foreign], array_map(static fn ($row) => $row->id,
            Hilos::$db->secondFactorBackupCodes->listByUser(self::FOREIGN_ID)));
        self::assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::SURVIVOR_ID));
    }

    /**
     * @param string $branch Existing demotion outcome
     * @param bool $rollback Whether the same objects and stored secrets must be restored
     */
    #[DataProvider('passwordDemotions')]
    public function testOwnPasswordDemotionUsesTheExistingRulesAndRollsBack(string $branch, bool $rollback): void
    {
        $id = $this->seedPassword($branch !== 'unverified');
        if ($branch === 'collision') {
            Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `created_at`, `updated_at`)'
                . ' SELECT ?, ?, `identifier`, ?, ? FROM `hilos_identity` WHERE `id` = ?',
                [self::SURVIVOR_ID, IdentityType::MAGIC_LINK, 1000, 1000, $id]);
        }
        [, $give] = $this->ready(passwordFate: PasswordFate::NONE);
        $password = Hilos::$db->identities[$id];
        $object = $password->getObject();
        $saved = $object->toArray();
        $savedUpdatedAt = $object->valueForColumn(EntityIdentity::updated_at);
        $before = $this->businessSnapshot();
        $reaction = $this->observeAnnouncements();
        $attempt = function () use ($give, $id, $password, $object, $branch, $rollback, $reaction): void {
            $this->asPerson($this->loser, function () use ($give, $id, $password, $object, $branch, $rollback, $reaction): void {
                // A password that will be deleted is deliberately absent from the manifest.
                $rows = new AccountMergeTransferRows(identities: $branch === 'verified' ? [$id] : []);
                AccountMergeTransfer::run($give->id, $rows, function ($transfer) use ($password, $object, $branch, $rollback, $reaction) {
                    $password->actions->demoteForMerge($transfer);
                    if ($branch === 'verified') {
                        self::assertSame(IdentityType::MAGIC_LINK, $password->type);
                        self::assertNull($password->passwordChangedAt);
                        self::assertNull($password->readForMerge($transfer));
                        self::assertFalse($object->hasChanges());
                        self::assertSame((string)self::LOSER_ID, $object->storedSetTop());
                        $password->actions->transferForMerge($transfer);
                        self::assertSame(self::SURVIVOR_ID, $password->userId);
                    } else {
                        self::assertFalse($object->isRelated());
                        self::assertFalse(isset(Hilos::$db->identities[$object->id]));
                    }
                    $this->assertRefused(WriteNotAllowedException::class, static fn () => $password->actions->demoteForMerge($transfer));
                    self::assertSame([], $reaction->seen);
                    self::assertSame([], $this->drainTransferFrames());
                    if ($rollback) {
                        throw new RuntimeException('rollback password demotion');
                    }

                    return self::payload();
                });
            });
        };
        if ($rollback) {
            $failure = $this->assertRefused(RuntimeException::class, $attempt);
            self::assertSame('rollback password demotion', $failure->getMessage());
            self::assertSame($before, $this->businessSnapshot());
            self::assertSame($saved, $object->toArray());
            self::assertSame($savedUpdatedAt, $object->valueForColumn(EntityIdentity::updated_at));
            self::assertTrue($object->isRelated());
            self::assertFalse($object->hasChanges());
            self::assertSame((string)self::LOSER_ID, $object->storedSetTop());
            self::assertSame($object, Hilos::$db->identities[$id]->getObject());
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->drainTransferFrames());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        } else {
            $attempt();
            $row = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$id])->firstRow();
            if ($branch === 'verified') {
                self::assertSame(IdentityType::MAGIC_LINK, $row['type']);
                self::assertNull($row['secret']);
                self::assertNull($row['password_changed_at']);
                self::assertSame(self::SURVIVOR_ID, (int)$row['user_id']);
            } else {
                self::assertNull($row);
            }
        }
    }

    /** @return iterable<string, array{string, bool}> All old helper branches at commit and rollback */
    public static function passwordDemotions(): iterable
    {
        foreach (['verified', 'unverified', 'collision'] as $branch) {
            yield $branch . ' commit' => [$branch, false];
            yield $branch . ' rollback' => [$branch, true];
        }
    }

    /** The own-password door checks the live executor, claim, exact anchor and clean source before ordinary writes. */
    public function testOwnPasswordGuardDoesNotBorrowManifestMembership(): void
    {
        $id = $this->seedPassword(false);
        [, $give] = $this->ready(passwordFate: PasswordFate::SURVIVOR);
        $other = $this->seedPassword(true);
        $foreign = $this->seedIdentity(self::FOREIGN_ID, IdentityType::PASSWORD);
        $password = Hilos::$db->identities[$id];
        $before = $this->businessSnapshot();
        $leaked = null;
        $this->asPerson($this->loser, function () use ($give, $id, $other, $foreign, $password, &$leaked): void {
            AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(), function ($transfer) use (
                $id, $other, $foreign, $password, &$leaked,
            ) {
                $leaked = $transfer;
                $transfer->guardPassword($password->getObject());
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $password->readForMerge($transfer));
                foreach ([$other, $foreign, $this->identityId] as $wrong) {
                    $this->assertRefused(WriteNotAllowedException::class,
                        static fn () => Hilos::$db->identities[$wrong]->actions->demoteForMerge($transfer));
                }
                $this->asPerson($this->survivor, fn () => $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => $password->actions->demoteForMerge($transfer)));
                $password->getObject()->identifier = 'dirty@example.test';
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $password->actions->demoteForMerge($transfer));
                $password->getObject()->revert();
                TruthSourceRegistry::unregister(HilosDbContext::identities, $this->loser->getId());
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $password->actions->demoteForMerge($transfer));
                TruthSourceRegistry::register(HilosDbContext::identities, TruthSourceKeys::set((string)self::LOSER_ID), $this->loser->getId(),
                    new TruthSourceOperations(TruthSourceOperation::Update));
                // The capability does not lend Remove to the helper's deletion branch.
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $password->actions->demoteForMerge($transfer));
                OwnershipDeclaration::claimAll($this->loser);

                return self::payload();
            });
        });
        $this->asPerson($this->loser, fn () => $this->assertRefused(WriteNotAllowedException::class,
            static fn () => $password->actions->demoteForMerge($leaked)));
        self::assertSame($before, $this->businessSnapshot());
    }

    /**
     * @param ?PasswordFate $fate A kept or unspecified password cannot be demoted
     */
    #[DataProvider('keptPasswords')]
    public function testOwnPasswordDemotionRequiresAnExplicitDiscardFate(?PasswordFate $fate): void
    {
        $id = $this->seedPassword(true);
        [, $give] = $this->ready(passwordFate: $fate);
        $before = $this->businessSnapshot();
        $this->asPerson($this->loser, function () use ($give, $id): void {
            AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(identities: [$id]), function ($transfer) use ($id) {
                $this->assertRefused(WriteNotAllowedException::class,
                    static fn () => Hilos::$db->identities[$id]->actions->demoteForMerge($transfer));

                return self::payload();
            });
        });
        self::assertSame($before, $this->businessSnapshot());
    }

    /** @return iterable<string, array{?PasswordFate}> Choices without own demotion */
    public static function keptPasswords(): iterable
    {
        yield 'unspecified' => [null];
        yield 'source kept' => [PasswordFate::LOSER];
    }

    /**
     * @param bool $beforeAdmission Whether deletion precedes the attempt-local capture
     */
    #[DataProvider('anchorReplacementTimes')]
    public function testDeletedPasswordAnchorCannotBeReplacedByAddressOrObject(bool $beforeAdmission): void
    {
        $id = $this->seedPassword(true);
        [, $give] = $this->ready(passwordFate: PasswordFate::NONE);
        $password = Hilos::$db->identities[$id];
        $identifier = $password->identifier;
        if ($beforeAdmission) {
            $this->asPerson($this->loser, static fn () => $password->getObject()->delete());
        }
        $this->asPerson($this->loser, function () use ($give, $id, $password, $identifier, $beforeAdmission): void {
            AccountMergeTransfer::run($give->id, new AccountMergeTransferRows(), function ($transfer) use (
                $id, $password, $identifier, $beforeAdmission,
            ) {
                if (!$beforeAdmission) {
                    $password->getObject()->delete();
                }
                $replacementId = $beforeAdmission ? $id + 100 : $id;
                Database::sql('INSERT INTO `hilos_identity` (`id`, `user_id`, `type`, `identifier`, `verified`, `secret`,'
                    . ' `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$replacementId, self::LOSER_ID, IdentityType::PASSWORD, $identifier, true, 'replacement-hash', 1000, 1000]);
                $this->mountFreshContext();
                $replacement = Hilos::$db->identities[$replacementId];
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $password->actions->demoteForMerge($transfer));
                $this->assertRefused(WriteNotAllowedException::class, static fn () => $replacement->actions->demoteForMerge($transfer));
                self::assertSame(IdentityType::PASSWORD, $replacement->type);
                self::assertSame('replacement-hash', Database::sql('SELECT `secret` FROM `hilos_identity` WHERE `id` = ?',
                    [$replacementId])->firstRow()['secret']);

                return self::payload();
            });
        });
    }

    /** @return iterable<string, array{bool}> Address replacement and same-id object substitution */
    public static function anchorReplacementTimes(): iterable
    {
        yield 'before admission, new id at old address' => [true];
        yield 'after capture, same id in new object' => [false];
    }

    /** Every extra mapped column is refused inside the shared save failure boundary. */
    #[DataProvider('foreignColumnEdits')]
    public function testScopedSaveRefusesForeignColumnsAndRestoresTheSameObject(string $collection, string $field, mixed $value): void
    {
        [, $give] = $this->ready();
        $this->asPerson($this->loser, function () use ($give, $collection, $field, $value): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use ($collection, $field, $value) {
                if ($collection === HilosDbContext::passkeyCredentials) {
                    Hilos::$db->identities[$this->identityId]->actions->transferForMerge($transfer);
                }
                $before = $this->businessSnapshot();
                $item = Hilos::$db->{$collection}[$this->selectedIds()[$collection]];
                $object = $item->getObject();
                $saved = $object->toArray();
                $object->userId = self::SURVIVOR_ID;
                $object->{$field} = $value;
                $this->assertRefused(WriteNotAllowedException::class, fn () => $object->syncForMerge($transfer));
                self::assertSame($saved, $object->toArray());
                self::assertSame($object, $item->getObject());
                self::assertTrue($object->isRelated());
                self::assertFalse($object->hasChanges());
                self::assertSame($before, $this->businessSnapshot());
                return self::payload();
            });
        });
    }

    /** @return iterable<string, array{string, string, mixed}> Extra edits excluded from the transfer diff */
    public static function foreignColumnEdits(): iterable
    {
        yield 'identity identifier' => [HilosDbContext::identities, ObjectIdentity::identifier, 'changed'];
        yield 'identity verification' => [HilosDbContext::identities, ObjectIdentity::verified, true];
        yield 'credential label' => [HilosDbContext::passkeyCredentials, 'label', 'changed'];
        yield 'credential crypto' => [HilosDbContext::passkeyCredentials, 'publicKey', 'changed'];
        yield 'factor label' => [HilosDbContext::secondFactors, 'label', 'changed'];
        yield 'backup creation' => [HilosDbContext::secondFactorBackupCodes, 'createdAt', 42];
    }

    /** Actions reject dirty inputs before mutation; direct saves reject new, foreign and unmounted rows and id edits. */
    #[DataProvider('saveDefects')]
    public function testScopedSaveRejectsAnythingOutsideTheCapturedMountedRow(string $defect): void
    {
        [, $give] = $this->ready();
        $unselected = $this->seedIdentity(self::LOSER_ID);
        $foreign = $this->seedIdentity(self::FOREIGN_ID);
        $target = $this->seedIdentity(self::SURVIVOR_ID);
        $this->asPerson($this->loser, function () use ($give, $defect, $unselected, $foreign, $target): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use ($defect, $unselected, $foreign, $target) {
                $before = $this->businessSnapshot();
                $item = Hilos::$db->identities[$this->identityId];
                $object = match ($defect) {
                    'unselected' => Hilos::$db->identities[$unselected]->getObject(),
                    'foreign source' => Hilos::$db->identities[$foreign]->getObject(),
                    'target source' => Hilos::$db->identities[$target]->getObject(),
                    'new' => ObjectIdentity::create(),
                    'other collection' => Hilos::$db->users[self::LOSER_ID]->getObject(),
                    'manual object' => ObjectIdentity::fromEntity(EntityIdentity::fromRow(
                        Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$this->identityId])->firstRow())),
                    default => $item->getObject(),
                };
                if ($defect === 'new') {
                    $object->userId = self::LOSER_ID;
                    $object->type = IdentityType::MAGIC_LINK;
                    $object->identifier = 'new-transfer@example.test';
                }
                $saved = $object->toArray();
                if ($defect === 'dirty action') {
                    $object->identifier = 'preexisting draft';
                    $this->assertRefused(WriteNotAllowedException::class, fn () => $item->actions->transferForMerge($transfer));
                    self::assertSame(self::LOSER_ID, $object->userId);
                    $object->revert();
                } else {
                    if ($defect !== 'other collection') {
                        $object->userId = $defect === 'third target' ? self::FOREIGN_ID : self::SURVIVOR_ID;
                    }
                    if ($defect === 'changed id') {
                        (function (): void { $this->entity->id = 99999; })->call($object);
                    }
                    $db = Hilos::$db;
                    try {
                        if ($defect === 'unmounted' || $defect === 'foreign mount') {
                            Hilos::$db = new AccountMergeTransferContext();
                            if ($defect === 'foreign mount') {
                                Hilos::$db->configure();
                                (function (): void {
                                    $this->_objectCollections[HilosDbContext::identities]
                                        = $this->_objectCollections[HilosDbContext::passkeyCredentials];
                                })->call(Hilos::$db);
                            }
                        }
                        $this->assertRefused(WriteNotAllowedException::class, fn () => $object->syncForMerge($transfer));
                    } finally {
                        Hilos::$db = $db;
                    }
                }
                if ($defect !== 'new') {
                    self::assertSame($saved, $object->toArray());
                    self::assertFalse($object->hasChanges());
                    self::assertTrue($object->isRelated());
                } else {
                    self::assertFalse($object->isRelated());
                    self::assertNull($object->id);
                }
                self::assertSame($before, $this->businessSnapshot());
                return self::payload();
            });
        });
    }

    /** @return iterable<string, array{string}> Save and action boundary failures */
    public static function saveDefects(): iterable
    {
        foreach (['unselected', 'foreign source', 'target source', 'new', 'manual object', 'unmounted', 'foreign mount',
            'other collection', 'third target', 'changed id', 'dirty action'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** A passkey's unchanged captured anchor must already be clean and persisted at the target. */
    public function testPasskeyFollowsOnlyItsCleanPersistedCapturedIdentity(): void
    {
        [, $give] = $this->ready();
        $other = $this->seedIdentity(self::LOSER_ID, IdentityType::PASSKEY);
        $this->asPerson($this->loser, function () use ($give, $other): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use ($other) {
                $identity = Hilos::$db->identities[$this->identityId];
                $passkey = Hilos::$db->passkeyCredentials[$this->passkeyId];
                $before = $this->businessSnapshot();
                $this->assertRefused(WriteNotAllowedException::class, fn () => $passkey->actions->transferForMerge($transfer));
                $identity->getObject()->userId = self::SURVIVOR_ID;
                $this->assertRefused(WriteNotAllowedException::class, fn () => $passkey->actions->transferForMerge($transfer));
                $identity->getObject()->revert();
                self::assertSame($before, $this->businessSnapshot());
                self::assertFalse($passkey->getObject()->hasChanges());
                $identity->actions->transferForMerge($transfer);
                $identity->getObject()->identifier = 'dirty anchor';
                $this->assertRefused(WriteNotAllowedException::class, fn () => $passkey->actions->transferForMerge($transfer));
                $identity->getObject()->revert();
                $passkey->getObject()->identityId = $other;
                $passkey->getObject()->userId = self::SURVIVOR_ID;
                $this->assertRefused(WriteNotAllowedException::class, fn () => $passkey->getObject()->syncForMerge($transfer));
                self::assertSame($this->identityId, $passkey->identityId);
                self::assertFalse($passkey->getObject()->hasChanges());
                $passkey->actions->transferForMerge($transfer);
                return self::payload();
            });
        });
        self::assertSame(self::SURVIVOR_ID, Hilos::$db->passkeyCredentials[$this->passkeyId]->userId);
    }

    /** The ordinary door never consumes the capability, before, during or after a scoped save. */
    public function testOrdinarySaveCannotMoveARowEvenInsideTheGiveCallback(): void
    {
        [, $give] = $this->ready();
        $object = Hilos::$db->identities[$this->identityId]->getObject();
        $attempt = function () use ($object): void {
            $object->userId = self::SURVIVOR_ID;
            $this->assertRefused(WriteNotAllowedException::class, fn () => $object->sync());
            self::assertFalse($object->hasChanges());
            self::assertSame(self::LOSER_ID, $object->userId);
        };
        $this->asPerson($this->loser, $attempt);
        $this->asPerson($this->loser, function () use ($give, $attempt): void {
            AccountMergeTransfer::run($give->id, $this->selection(), static function () use ($attempt) {
                $attempt();
                return self::payload();
            });
        });
        $this->asPerson($this->loser, $attempt);
    }

    /** Live grant and executor checks in the save guard revert the proposed mutation. */
    #[DataProvider('lostSaveRights')]
    public function testSaveRechecksTheLiveSourceRightAndExecutor(string $loss): void
    {
        [, $give] = $this->ready();
        $this->asPerson($this->loser, function () use ($give, $loss): void {
            AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use ($loss) {
                $object = Hilos::$db->identities[$this->identityId]->getObject();
                $before = $object->toArray();
                $object->userId = self::SURVIVOR_ID;
                if ($loss === 'grant') {
                    TruthSourceRegistry::unregisterAgent($this->loser->getId());
                }
                try {
                    if ($loss === 'executor') {
                        $this->asPerson($this->survivor, fn () => $this->assertRefused(WriteNotAllowedException::class,
                            fn () => $object->syncForMerge($transfer)));
                    } else {
                        $this->assertRefused(WriteNotAllowedException::class, fn () => $object->syncForMerge($transfer));
                    }
                    self::assertSame($before, $object->toArray());
                    self::assertFalse($object->hasChanges());
                } finally {
                    if ($loss === 'grant') {
                        OwnershipDeclaration::claimAll($this->loser);
                    }
                }
                return self::payload();
            });
        });
    }

    /** @return iterable<string, array{string}> A right can disappear between proposal and save */
    public static function lostSaveRights(): iterable
    {
        yield 'revoked source grant' => ['grant'];
        yield 'foreign executor' => ['executor'];
    }

    /**
     * Rollback restores every transferred Object and drops result, frames and reactions; retry commits once.
     *
     * @param bool $atResult Whether the failure follows the durable result INSERT instead of the last business UPDATE
     */
    #[DataProvider('transferFailurePoints')]
    public function testFailureAfterSeveralTransfersRestoresRowsMemoryAndAnnouncements(bool $atResult): void
    {
        [, $give] = $this->ready();
        $objects = $this->selectedObjects();
        $saved = array_map(static fn ($object) => $object->toArray(), $objects);
        $before = $this->businessSnapshot();
        $reaction = $this->observeAnnouncements();
        $failure = new RuntimeException('fail after the four real updates');
        $resultFailed = false;
        SourceChangeBus::subscribe(new AccountMergeTransferMirror(static function ($change) use ($atResult, $failure, &$resultFailed): void {
            if ($atResult && !$resultFailed && $change->sourceKey === HilosDbContext::accountOperationResults
                && $change->mutationType === TableMutationType::Create) {
                $resultFailed = true;
                throw $failure;
            }
        }));
        $leaked = null;
        $caught = $this->assertRefused($atResult ? SourceChangeSubscriberException::class : RuntimeException::class,
            function () use ($give, $failure, $atResult, &$leaked) {
                return $this->asPerson($this->loser, function () use ($give, $failure, $atResult, &$leaked) {
                    return AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) use ($failure, $atResult, &$leaked) {
                        $leaked = $transfer;
                        $this->transferSelected($transfer);
                        if (!$atResult) {
                            throw $failure;
                        }
                        return self::payload();
                    });
                });
            });
        self::assertSame($failure, $atResult ? $caught->getPrevious() : $caught);
        self::assertSame($atResult, $resultFailed);
        self::assertSame($before, $this->businessSnapshot());
        foreach ($objects as $key => $object) {
            self::assertSame($saved[$key], $object->toArray());
            self::assertTrue($object->isRelated());
            self::assertFalse($object->hasChanges());
            self::assertSame((string)self::LOSER_ID, $object->storedSetTop());
        }
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        self::assertSame([], $reaction->seen);
        self::assertSame([], $this->drainTransferFrames());
        $this->assertClosed($leaked);
        $this->asPerson($this->loser, function () use ($leaked, $objects): void {
            $objects[HilosDbContext::identities]->userId = self::SURVIVOR_ID;
            $this->assertRefused(WriteNotAllowedException::class,
                fn () => $objects[HilosDbContext::identities]->syncForMerge($leaked));
            self::assertFalse($objects[HilosDbContext::identities]->hasChanges());
        });
        $result = $this->asPerson($this->loser, function () use ($give) {
            return AccountMergeTransfer::run($give->id, $this->selection(), function ($transfer) {
                $this->transferSelected($transfer);
                return self::payload();
            });
        });
        self::assertSame($this->expectedAnnouncements($result->id), $reaction->seen);
        self::assertSame($reaction->seen, $this->drainTransferFrames());
    }

    /** @return iterable<string, array{bool}> Both failure boundaries must undo the same business rows */
    public static function transferFailurePoints(): iterable
    {
        yield 'consumer failure' => [false];
        yield 'result insert failure' => [true];
    }

    /** A stored result answers replay after reconnect and stage change without callback, UPDATE or new announcements. */
    public function testRealTransferRepeatAfterReconnectAndFinishDoesNotWriteAgain(): void
    {
        [$operation, $give] = $this->ready();
        $reaction = $this->observeAnnouncements();
        $runs = 0;
        $leaked = null;
        $work = function ($transfer) use (&$runs, &$leaked) {
            $runs++;
            $leaked = $transfer;
            $this->transferSelected($transfer);
            return self::payload();
        };
        $result = $this->asPerson($this->loser, fn () => AccountMergeTransfer::run($give->id, $this->selection(), $work));
        $expected = $this->expectedAnnouncements($result->id);
        self::assertSame($expected, $this->drainTransferFrames());
        $stored = $this->businessSnapshot();
        $updates = $this->sessionUpdates();
        self::assertSame($result->id,
            $this->asPerson($this->loser, fn () => AccountMergeTransfer::run($give->id, $this->selection(), $work))->id);
        self::assertSame($updates, $this->sessionUpdates());
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        Database::close();
        Database::connect();
        $this->mountFreshContext();
        $updates = $this->sessionUpdates();
        self::assertSame($result->id,
            $this->asPerson($this->loser, fn () => AccountMergeTransfer::run($give->id, $this->selection(), $work))->id);
        self::assertSame($updates, $this->sessionUpdates());
        self::assertSame($stored, $this->businessSnapshot());
        self::assertSame(1, $runs);
        self::assertSame($expected, $reaction->seen);
        self::assertSame([], $this->drainTransferFrames());
        $this->assertClosed($leaked);
        $this->asPerson($this->loser, function () use ($leaked): void {
            $object = Hilos::$db->identities[$this->identityId]->getObject();
            $object->identifier = 'closed right';
            $this->assertRefused(WriteNotAllowedException::class, fn () => $object->syncForMerge($leaked));
            self::assertFalse($object->hasChanges());
        });
    }

    /** @return array<string, int> Exact four row addresses in dependency order */
    private function selectedIds(): array
    {
        return [HilosDbContext::identities => $this->identityId, HilosDbContext::passkeyCredentials => $this->passkeyId,
            HilosDbContext::secondFactors => $this->factorId, HilosDbContext::secondFactorBackupCodes => $this->backupCodeId];
    }

    /** @return array<string, Object_> Held Objects, retained through writes and rollback */
    private function selectedObjects(): array
    {
        $objects = [];
        foreach ($this->selectedIds() as $collection => $id) {
            $objects[$collection] = Hilos::$db->{$collection}[$id]->getObject();
        }
        return $objects;
    }

    /** @param AccountMergeTransfer $transfer Right used by the four concrete public item actions */
    private function transferSelected(AccountMergeTransfer $transfer): void
    {
        foreach ($this->selectedIds() as $collection => $id) {
            Hilos::$db->{$collection}[$id]->actions->transferForMerge($transfer);
        }
    }

    /** @return AccountMergeTransferReaction Records committed business updates and the give result */
    private function observeAnnouncements(): AccountMergeTransferReaction
    {
        $this->drainTransferFrames();
        $reaction = new AccountMergeTransferReaction();
        SourceChangeBus::subscribe($reaction);
        return $reaction;
    }

    /** @return list<string> Only transfer updates and result creation frames, in publication order */
    private function drainTransferFrames(): array
    {
        $seen = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncSignalDataInterface
                && ((isset(self::TRANSFER_TABLES[$signal->data->collectionKey])
                        && $signal->signalType->getType() === SignalConstants::DB_SYNC_UPDATED)
                    || ($signal->data->collectionKey === HilosDbContext::accountOperationResults
                        && $signal->signalType->getType() === SignalConstants::DB_SYNC_CREATED))) {
                $seen[] = $signal->data->collectionKey . ':' . $signal->data->idString;
            }
        }
        return $seen;
    }

    /**
     * @param int $resultId Give result inserted after the four updates
     * @return list<string> Exact commit announcements, each once
     */
    private function expectedAnnouncements(int $resultId): array
    {
        $expected = [];
        foreach ($this->selectedIds() as $collection => $id) {
            $expected[] = $collection . ':' . $id;
        }
        $expected[] = HilosDbContext::accountOperationResults . ':' . $resultId;
        return $expected;
    }

    /** @return int Physical UPDATE work in this MariaDB session, independent of ORM cache state */
    private function sessionUpdates(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Handler_update'")->firstRow()['Value'];
    }

    /**
     * @param SecondFactorFate $fate Choice to record before the real preparation pair
     * @param ?PasswordFate $passwordFate Password choice to record with the preparation pair
     * @return array{AccountOperation, AccountOperationAssignment} Prepared operation and loser's give
     */
    private function ready(SecondFactorFate $fate = SecondFactorFate::BOTH, ?PasswordFate $passwordFate = null): array
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR_ID, self::LOSER_ID, new AccountMergeChoice($passwordFate, $fate, true, true));
        foreach ([[$this->survivor, self::SURVIVOR_ID, AccountMergeDuty::PrepareSurvivor],
            [$this->loser, self::LOSER_ID, AccountMergeDuty::PrepareLoser]] as [$person, $userId, $duty]) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            $result = $this->asPerson($person, static fn () => AccountMergePreparation::run($assignment->id, $userId));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $give = Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER_ID), self::LOSER_ID,
            AccountOperationAssignmentPayload::fromJson('{}'),
        );

        return [$operation, $give];
    }

    /** @return AccountMergeTransferRows All four existing source row kinds */
    private function selection(): AccountMergeTransferRows
    {
        return new AccountMergeTransferRows([$this->identityId], [$this->passkeyId], [$this->factorId], [$this->backupCodeId]);
    }

    /** @return AccountOperation Another durable identity, without conflicting preparation assignments */
    private function otherOperation(): AccountOperation
    {
        return Hilos::$db->accountOperations->actions->recordMerge(
            self::SURVIVOR_ID, self::FOREIGN_ID, new AccountMergeChoice(null, null, null, null),
        );
    }

    /** @return AccountOperationResultPayload Test-only result using the existing payload boundary */
    private static function payload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson('{"admitted":true}');
    }

    /**
     * @param AccountMergeTransferPerson $person Owner whose frame executes the work
     * @param Closure $work Synchronous test step
     * @return mixed Work result
     */
    private function asPerson(AccountMergeTransferPerson $person, Closure $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $person->getId()), $work);
    }

    /**
     * @param int $assignmentId Give under test
     * @param AccountMergeTransferRows $rows Selection that must not reach the consumer
     * @param class-string<Throwable> $exception Expected refusal
     * @return Throwable Actual refusal for precise reason assertions
     */
    private function assertAdmissionRefused(int $assignmentId, AccountMergeTransferRows $rows, string $exception): Throwable
    {
        $runs = 0;
        $failure = $this->assertRefused($exception, function () use ($assignmentId, $rows, &$runs) {
            return $this->asPerson($this->loser, static function () use ($assignmentId, $rows, &$runs) {
                return AccountMergeTransfer::run($assignmentId, $rows, static function () use (&$runs) {
                    $runs++;
                    return self::payload();
                });
            });
        });
        self::assertSame(0, $runs);
        self::assertFalse(Database::hasOpenTransactions());

        return $failure;
    }

    /**
     * @param class-string<Throwable> $exception Expected exception family
     * @param Closure $attempt Work that must fail
     * @return Throwable The exact failure for additional assertions
     */
    private function assertRefused(string $exception, Closure $attempt): Throwable
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());
            return $failure;
        }
        self::fail('Forbidden merge transfer was accepted');
    }

    /** @param ?AccountMergeTransfer $transfer Right captured by the consumer */
    private function assertClosed(?AccountMergeTransfer $transfer): void
    {
        self::assertNotNull($transfer);
        $this->asPerson($this->loser, function () use ($transfer): void {
            $object = Hilos::$db->identities[$this->identityId]->getObject();
            $saved = $object->toArray();
            $this->assertRefused(WriteNotAllowedException::class, fn () => $transfer->guardRow(HilosDbContext::identities, $object));
            $object->userId = self::SURVIVOR_ID;
            $this->assertRefused(WriteNotAllowedException::class, fn () => $object->syncForMerge($transfer));
            self::assertSame($saved, $object->toArray());
            self::assertFalse($object->hasChanges());
        });
    }

    /** Mounts genuinely new wrappers without forgetting the previous ORM context. */
    private function mountFreshContext(): void
    {
        Hilos::$db = new AccountMergeTransferContext();
        Hilos::$db->configure();
    }

    /** Opens a distinct physical connection to the same schema for nonwaiting lock assertions. */
    private function connectPeer(): void
    {
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::PEER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(self::PEER_INDEX);
        self::assertNotSame(Database::sql('SELECT CONNECTION_ID()')->rows(),
            Database::sql('SELECT CONNECTION_ID()', index: self::PEER_INDEX)->rows());
        self::assertSame(Database::sql('SELECT DATABASE()')->rows(), Database::sql('SELECT DATABASE()', index: self::PEER_INDEX)->rows());
    }

    /**
     * @param int $userId Source of the fixture row
     * @param string $type Sign-in method kind
     * @return int Persisted identity id
     */
    private function seedIdentity(int $userId, string $type = IdentityType::MAGIC_LINK): int
    {
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $type, 'transfer-' . $userId . '-' . Database::sql('SELECT COUNT(*) AS n FROM `hilos_identity`')->firstRow()['n'],
                'fixture-password-hash', 1000, 1000]);

        return Database::lastInsertId();
    }

    /**
     * @param int $identityId Existing passkey anchor
     * @param int $userId Credential source
     * @return int Persisted credential id
     */
    private function seedPasskey(int $identityId, int $userId): int
    {
        Database::sql('INSERT INTO `hilos_passkey_credential` (`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`,'
            . ' `user_handle`, `sign_count`, `last_used_at`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$identityId, $userId, 'fixture-credential', 'fixture-public-key', PasskeyAlgorithm::Es256->value,
                'fixture-handle', 17, 1234, TimeHelper::nowMs()]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Factor source
     * @param bool $confirmed Whether this factor is confirmed
     * @return int Persisted factor id
     */
    private function seedFactor(int $userId, bool $confirmed = true): int
    {
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`,'
            . ' `last_used_step`, `last_used_at`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'Transfer factor', 'fixture-secret', $confirmed ? TimeHelper::nowMs() : null, TimeHelper::nowMs(), 23, 1234]);

        return Database::lastInsertId();
    }

    /**
     * @param bool $verified Whether the password address is verified
     * @return int Persisted own password anchor
     */
    private function seedPassword(bool $verified): int
    {
        $id = $this->seedIdentity(self::LOSER_ID, IdentityType::PASSWORD);
        Database::sql('UPDATE `hilos_identity` SET `verified` = ?, `password_changed_at` = ? WHERE `id` = ?', [(int)$verified, 1234, $id]);

        return $id;
    }

    /**
     * @param int $userId Backup code owner
     * @return int Persisted unused backup code id
     */
    private function seedBackupCode(int $userId): int
    {
        Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `created_at`) VALUES (?, ?, ?)',
            [$userId, 'other-code', 1234]);

        return Database::lastInsertId();
    }

    /** @return array<string, list<array<string, mixed>>> Persisted business rows in primary order */
    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (self::BUSINESS_TABLES as $table) {
            $snapshot[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`')->rows();
        }

        return $snapshot;
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

/** Uses the real framework collection chains. */
final class AccountMergeTransferContext extends HilosDbContext
{
}

/** Observes real source writes synchronously, including the interval between consumer return and commit. */
final class AccountMergeTransferMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe Test assertion or counter over real source changes */
    public function __construct(private readonly Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Published source fact
     * @param SourceChangeProvenance $provenance Write provenance supplied by the source bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

/** Uses the production coordinator's storage declarations without changing its behavior. */
final class AccountMergeTransferCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Real source-set grants, with results addressed by the participant's durable agent id. */
final class AccountMergeTransferPerson extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array OWNS_DB_SET = [
        HilosDbContext::identities => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactors => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::secondFactorBackupCodes => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::accountOperationResults => [TruthSourceOperation::Add],
    ];
    public const array READS_DB = [
        HilosDbContext::users, HilosDbContext::userMerges, HilosDbContext::accountDeletions, HilosDbContext::secondFactorResets,
        HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
    ];

    /** @param int $userId Account represented by the participant */
    public function __construct(private readonly int $userId)
    {
        $this->agentIndex = (string)$userId;
    }

    /**
     * @param string $collection Declared set collection
     * @return string Canonical result owner or business source set
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $collection === HilosDbContext::accountOperationResults ? $this->getId() : (string)$this->userId;
    }

    /** The fixture has no background work. */
    public function onStop(): void
    {
    }
}

/** A reaction hears only committed transfers and give-result creation, never rollback compensation. */
final class AccountMergeTransferReaction implements SourceChangeSubscriberInterface
{
    /** @var list<string> Committed row addresses in publication order */
    public array $seen = [];

    /**
     * @param SourceChange $change Published fact
     * @param SourceChangeProvenance $provenance Origin supplied by the bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB
            && ((isset(AccountMergeTransferIntegrationTest::TRANSFER_TABLES[$change->sourceKey])
                    && $change->mutationType === TableMutationType::Update)
                || ($change->sourceKey === HilosDbContext::accountOperationResults && $change->mutationType === TableMutationType::Create))) {
            $this->seen[] = $change->sourceKey . ':' . $change->sourceId;
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeAdmission;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergeHandover;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountMergeReceiving;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountMergeReceiveWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Actions\Item\IdentityActions;
use Hilos\Database\Entity\Collection\Identities as EntityIdentities;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\View\Collection\Identities as ViewIdentities;
use Hilos\Database\View\Item\Identity as ViewIdentity;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** Survivor protection and receipt use actual person claims, two preparations and real commits. */
final class AccountMergeReceivingIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    private const string COORDINATOR = 'merge-receiving-coordinator';
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
    private int $passkeyId;
    private int $factorId;
    private int $unfinishedId;
    private int $codeId;
    private int $usedCodeId;
    private int $targetFactorId;

    /** A complete schema and the production person's claims belong to each case. */
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
        OwnershipDeclaration::claimDb(MergeReceivingCoordinator::class, self::COORDINATOR);
        $this->personAgent(self::LOSER);
        $this->personAgent(self::SURVIVOR);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR));
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other')");
        $this->identityId = $this->identity(self::LOSER, IdentityType::PASSKEY);
        Database::sql('INSERT INTO `hilos_passkey_credential` (`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`,'
            . ' `sign_count`, `transports`, `aaguid`, `user_handle`, `label`, `last_used_at`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->identityId, self::LOSER, 'credential-original', 'key-original', PasskeyAlgorithm::Es256->value, 17,
                '["usb", "internal"]', 'aaguid-original', "\xff\0\x80", 'Key', -1, 0]);
        $this->passkeyId = Database::lastInsertId();
        $this->factorId = $this->factor(self::LOSER);
        $this->unfinishedId = $this->factor(self::LOSER, false);
        $this->targetFactorId = $this->factor(self::SURVIVOR);
        $this->codeId = $this->code(self::LOSER);
        $this->usedCodeId = $this->code(self::LOSER, -1);
        $this->code(self::SURVIVOR);
        foreach ([self::LOSER, self::SURVIVOR] as $user) {
            Database::sqlRun('INSERT INTO `hilos_second_factor_setting` (`user_id`, `reset_wait_days`, `updated_at`) VALUES (?, ?, ?)',
                [$user, 21, 0]);
            Database::sqlRun('INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES (?, ?, ?, ?, ?)', [$user, $user, 10000, 0, 0]);
        }
    }

    /** DDL follows handler cleanup; every declaration and facade is restored. */
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

    /** @return list<array{?PasswordFate, bool, bool, bool, bool, bool}> Choices, order and captured password presence */
    public static function passwords(): array
    {
        return [
            [PasswordFate::LOSER, false, true, true, true, false],
            [PasswordFate::LOSER, true, true, true, true, false],
            [PasswordFate::NONE, false, true, true, true, false],
            [PasswordFate::NONE, true, true, true, true, false],
            [PasswordFate::SURVIVOR, false, true, true, true, false],
            [PasswordFate::SURVIVOR, true, true, true, true, false],
            [PasswordFate::LOSER, false, true, true, false, false],
            [PasswordFate::NONE, false, true, true, true, true],
            [null, false, true, false, true, false],
            [null, false, false, true, true, false],
            [null, false, false, false, true, false],
            [PasswordFate::LOSER, false, false, true, true, false],
            [PasswordFate::SURVIVOR, false, true, false, true, false],
        ];
    }

    /**
     * @param ?PasswordFate $fate Chosen password protection
     * @param bool $ownFirst Whether the survivor's id sorts first
     * @param bool $hasSource Whether source captured a password
     * @param bool $hasOwn Whether survivor captured a password
     * @param bool $verified Whether the survivor password is verified
     * @param bool $collision Whether its magic-link address already exists
     */
    #[DataProvider('passwords')]
    public function testOnlyTheCapturedOwnPasswordIsSettled(
        ?PasswordFate $fate, bool $ownFirst, bool $hasSource, bool $hasOwn, bool $verified, bool $collision,
    ): void {
        $passwords = [];
        foreach ($ownFirst ? [self::SURVIVOR, self::LOSER] : [self::LOSER, self::SURVIVOR] as $user) {
            if (($user === self::LOSER && $hasSource) || ($user === self::SURVIVOR && $hasOwn)) {
                $passwords[$user] = $this->identity($user, IdentityType::PASSWORD, $user === self::LOSER || $verified);
            }
        }
        if ($collision) {
            $this->identity(11, IdentityType::MAGIC_LINK, identifier: IdentityType::PASSWORD . '-' . self::SURVIVOR . '@example.test');
        }
        $unfinishedOwn = $this->factor(self::SURVIVOR, false);
        [$operation, $give] = $this->ready(password: $fate);
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $before = $this->snapshot();
        $revisions = Database::sql('SELECT * FROM `hilos_account_merge_fence` ORDER BY 1, 2')->rows();
        $result = $this->receive($receive, $handover);
        self::assertSame('{"receive":"received"}', $result->payload->toJson());
        self::assertSame($receive->id, $result->assignmentId);
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        if ($hasOwn) {
            $id = $passwords[self::SURVIVOR];
            $demote = in_array($fate, [PasswordFate::LOSER, PasswordFate::NONE], true);
            if ($demote && (!$verified || $collision)) {
                self::assertNull(Hilos::$db->identities[$id] ?? null);
            } else {
                self::assertSame($demote ? IdentityType::MAGIC_LINK : IdentityType::PASSWORD, Hilos::$db->identities[$id]->type);
                $row = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$id])->firstRow();
                self::assertSame($demote ? null : 'password-hash-original', $row['secret']);
                self::assertSame($demote ? null : '0', $row['password_changed_at']);
            }
        }
        $after = $this->snapshot();
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            $table = Hilos::$db->mountedObjectCollection($key)->getTableName();
            foreach ($handover->copy->{$key} as $row) {
                $select = static fn (array $record): bool => (int)$record['id'] === $row->id;
                self::assertSame(array_values(array_filter($before[$table], $select)), array_values(array_filter($after[$table], $select)));
            }
        }
        self::assertSame($revisions, Database::sql('SELECT * FROM `hilos_account_merge_fence` ORDER BY 1, 2')->rows());
        self::assertSame(self::SURVIVOR, Hilos::$db->secondFactors[$this->targetFactorId]->userId);
        self::assertNull(Hilos::$db->secondFactors[$unfinishedOwn]->confirmedAt);
        self::assertNull(AccountMergePreparation::heldBy(self::SURVIVOR));
    }

    public function testWarmMemoryAndGenericAskAnswerOnlyAfterReceiptCommit(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->drain();
        $answer = $this->deliver($receive);
        self::assertSame(AccountOperationAnswerKind::Done, $answer->answer);
        self::assertSame([$operation->id, AccountMergeStage::Go->value, $receive->id],
            [$answer->operationId, $answer->stage, $answer->assignmentId]);
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        self::assertSame('0', Database::sql('SELECT COUNT(*) AS n FROM `hilos_second_factor_trust` WHERE `user_id` = ?',
            [self::SURVIVOR])->firstRow()['n']);
    }

    public function testMissingMemoryWaitsWithoutLoadingBusinessRowsThenHandoverCommits(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->freshContext();
        $this->drain();
        self::assertSame(AccountOperationAnswerKind::Waits, $this->deliver($receive)->answer);
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            foreach ($handover->copy->{$key} as $row) {
                self::assertFalse(isset(Hilos::$db->mountedObjectCollection($key)[$row->id]));
            }
        }
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        $this->receive($receive, $handover);
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    /** @return list<array{bool}> Applied warm memory and the original handover in a fresh context */
    public static function warmAdmissionPaths(): array
    {
        return [[false], [true]];
    }

    /** @param bool $handover Whether the original batch must first populate missing memory */
    #[DataProvider('warmAdmissionPaths')]
    public function testWarmReceiveUsesMemoryAndNeverUpdatesTransferredBusinessRows(bool $handover): void
    {
        Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `user_id` = ?', [self::SURVIVOR]);
        Hilos::$db = new MergeReceivingContext();
        Hilos::$db->configure();
        [$operation, $give] = $this->ready();
        $this->give($give);
        $copy = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        if ($handover) {
            $this->freshContext();
        }
        $context = Hilos::$db;
        $triggers = [];
        try {
            foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
                $table = $context->mountedObjectCollection($key)->getTableName();
                $trigger = 'receive_no_update_' . $key;
                Database::sqlRun('CREATE TRIGGER `' . $trigger . '` BEFORE UPDATE ON `' . $table . '`'
                    . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receive repeated a business update'");
                $triggers[] = $trigger;
            }
            $context->denyTransferredReads = true;
            $before = $this->snapshot();
            $this->receive($receive, $handover ? $copy : null);
            $after = $this->snapshot();
            foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
                $table = $context->mountedObjectCollection($key)->getTableName();
                self::assertSame($before[$table], $after[$table]);
            }
            self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        } finally {
            $context->denyTransferredReads = false;
            foreach ($triggers as $trigger) {
                Database::sqlRun('DROP TRIGGER `' . $trigger . '`');
            }
        }
    }

    /** Applied progress for only part of the batch cannot release the reservation. */
    public function testPartialAppliedMemoryCannotConfirmTheWholeManifest(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        unset(Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactorBackupCodes)[$this->usedCodeId]);
        $before = $this->snapshot();
        self::assertSame(self::SURVIVOR, Hilos::$db->identities[$this->identityId]->userId);
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->receive($receive));
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        self::assertSame($operation->id, AccountMergePreparation::heldBy(self::SURVIVOR)->id);
        $this->receive($receive, $handover);
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
    }

    public function testHeldRowsAndFreshMetadataCannotSubstituteForAppliedProgress(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->freshContext();
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            foreach ($handover->copy->{$key} as $row) {
                self::assertSame(self::SURVIVOR, Hilos::$db->{$key}[$row->id]->userId);
                self::assertSame(0, Hilos::$db->accountMergeFences[$key . ':' . $row->id]->getObject()->appliedRevision());
            }
        }
        $before = $this->snapshot();
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->receive($receive));
        self::assertSame($before, $this->snapshot());
        $this->receive($receive, $handover);
    }

    public function testEmptyValidBatchStillRecordsTheReceiptWithoutOwnProtectionWrites(): void
    {
        foreach (['hilos_passkey_credential', 'hilos_identity', 'hilos_second_factor', 'hilos_second_factor_backup_code'] as $table) {
            Database::sqlRun('DELETE FROM `' . $table . '` WHERE `user_id` = ?', [self::LOSER]);
        }
        [$operation, $give] = $this->ready(null);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $before = $this->snapshot();
        $this->receive($receive);
        $after = $this->snapshot();
        foreach (self::TABLES as $table) {
            if ($table !== 'hilos_account_operation_result') {
                self::assertSame($before[$table], $after[$table], $table);
            }
        }
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
    }

    public function testOwnDemotionActionRequiresTheExactReservedPasswordAndSourceFact(): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $demote = static fn () => Hilos::$db->identities[$own]->actions->demoteOwnForMerge($operation->id);
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::SURVIVOR, $demote));
        $this->give($give);
        $other = $this->identity(self::SURVIVOR, IdentityType::PASSWORD, identifier: 'other@example.test');
        $before = $this->snapshot();
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::LOSER, $demote));
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn () => Hilos::$db->identities[$other]->actions->demoteOwnForMerge($operation->id)));
        self::assertSame($before, $this->snapshot());
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $this->refused(ValidationException::class, fn () => $this->asPerson(self::SURVIVOR, $demote));
        self::assertSame(IdentityType::PASSWORD, Hilos::$db->identities[$own]->type);
    }

    public function testAlreadyCurrentAdmissionIsAcceptedWithoutAnotherTransferWrite(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->asPerson(self::SURVIVOR, static fn () => AccountMergeAdmission::apply(self::SURVIVOR, $handover));
        $this->receive($receive, $handover);
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
    }

    public function testNewerPendingMemoryWaitsAndOldHandoverCannotOverwriteIt(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->drain();
        $this->asPerson(self::SURVIVOR, function (): void {
            $factor = Hilos::$db->secondFactors[$this->factorId]->getObject();
            $factor->label = 'current label';
            $factor->sync();
        });
        $current = null;
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncUpdatedSignalData && $frame->data->collectionKey === HilosDbContext::secondFactors) {
                $current = $frame->data;
            }
        }
        self::assertNotNull($current);
        $this->freshContext();
        $before = $this->snapshot();
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->receive($receive));
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->receive($receive, $handover));
        self::assertSame($before, $this->snapshot());
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors)[$this->factorId]));
        self::assertTrue(DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData($current->collectionKey, $current->idString,
            $current->admission->snapshot, writerAgentId: $current->writerAgentId, admission: $current->admission), false)->accepted);
        $this->receive($receive, $handover);
        self::assertSame('current label', Hilos::$db->secondFactors[$this->factorId]->label);
    }

    public function testCurrentTombstoneIsNotResurrectedByTheOriginalHandover(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->asPerson(self::SURVIVOR, function (): void {
            Hilos::$db->secondFactorBackupCodes->actions->deleteForUser(self::SURVIVOR);
        });
        $this->freshContext();
        $this->receive($receive, $handover);
        self::assertNull(Hilos::$db->secondFactorBackupCodes[$this->usedCodeId] ?? null);
    }

    /** @return list<array{string}> Rejected admission and immutable source defects */
    public static function defects(): array
    {
        return [['handover'], ['giveOwner'], ['preparationOwner'], ['wait'], ['fence'], ['ownAnchor'], ['ownMissing'],
            ['ownFactor'], ['payload']];
    }

    /** @param string $defect Source or own anchor defect */
    #[DataProvider('defects')]
    public function testInvalidFactsAndAnchorsRefuseWithoutReceipt(string $defect): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        if ($defect === 'handover') {
            $copy = $handover->copy->toArray();
            $copy['effectiveWaitDays'] = 999;
            $handover = new AccountMergeHandover(AccountMergeHandoverCopy::fromArray($copy), $handover->admission);
        } elseif ($defect === 'giveOwner') {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                ['other', $give->id]);
        } elseif ($defect === 'preparationOwner') {
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `owner_index` = ? WHERE `operation_id` = ? AND `assignment_key` = ?',
                ['11', $operation->id, AccountMergeDuty::PrepareSurvivor->value]);
        } elseif ($defect === 'wait') {
            $payload = json_decode(Hilos::$db->accountOperationResults->byAssignment($give->id)->payload->toJson(), true);
            $payload['effectiveWaitDays'] = null;
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                [json_encode($payload, JSON_THROW_ON_ERROR), $give->id]);
        } elseif ($defect === 'fence') {
            Database::sqlRun('UPDATE `hilos_account_merge_fence` SET `assignment_id` = 999 WHERE `collection_key` = ? AND `row_id` = ?',
                [HilosDbContext::identities, $this->identityId]);
        } elseif ($defect === 'ownAnchor') {
            Database::sqlRun('UPDATE `hilos_identity` SET `type` = ? WHERE `id` = ?', [IdentityType::MAGIC_LINK, $own]);
        } elseif ($defect === 'ownMissing') {
            Database::sqlRun('DELETE FROM `hilos_identity` WHERE `id` = ?', [$own]);
            $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        } elseif ($defect === 'ownFactor') {
            Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `id` = ?', [$this->targetFactorId]);
        } else {
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `payload_json` = ? WHERE `id` = ?', ['{"foreign":true}', $receive->id]);
        }
        $this->freshContext();
        $before = $this->snapshot();
        $this->refused(ValidationException::class, fn () => $this->receive($receive, $handover));
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    /** @return iterable<string, array{AccountMergeDuty, string}> Both preparations retain their exact address and captured anchors */
    public static function preparationDefects(): iterable
    {
        foreach ([AccountMergeDuty::PrepareLoser, AccountMergeDuty::PrepareSurvivor] as $duty) {
            foreach (['missing', 'owner', 'user', 'payload', 'resultOwner', 'password', 'factors', 'pair'] as $defect) {
                yield $duty->value . ' ' . $defect => [$duty, $defect];
            }
        }
    }

    /**
     * @param AccountMergeDuty $duty Preparation changed after the real give
     * @param string $defect One altered immutable coordinate or anchor
     */
    #[DataProvider('preparationDefects')]
    public function testBothPreparationsRemainBoundToTheGivenPairAndAnchors(AccountMergeDuty $duty, string $defect): void
    {
        $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        $this->identity(self::LOSER, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $preparation = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Preparing->value, $duty->value,
        );
        if ($defect === 'missing') {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$preparation->id]);
        } elseif ($defect === 'owner' || $defect === 'user' || $defect === 'payload') {
            $column = match ($defect) {
                'owner' => 'owner_index', 'user' => 'user_id', 'payload' => 'payload_json',
            };
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?',
                [$defect === 'payload' ? '{"foreign":true}' : 11, $preparation->id]);
        } elseif ($defect === 'resultOwner') {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                ['foreign-owner', $preparation->id]);
        } elseif ($defect === 'pair') {
            $column = $duty === AccountMergeDuty::PrepareLoser ? 'loser_user_id' : 'root_user_id';
            Database::sqlRun('UPDATE `hilos_account_operation` SET `' . $column . '` = 11 WHERE `id` = ?', [$operation->id]);
        } else {
            $payload = json_decode(Hilos::$db->accountOperationResults->byAssignment($preparation->id)->payload->toJson(), true);
            $payload[$defect === 'password' ? 'passwordIdentityId' : 'secondFactorIds'] = $defect === 'password' ? 999 : [999];
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                [json_encode($payload, JSON_THROW_ON_ERROR), $preparation->id]);
        }
        $this->freshContext();
        $before = $this->snapshot();
        $this->refused(ValidationException::class, fn () => $this->receive($receive, $handover));
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    public function testCallerAndAssignmentAreCheckedBeforeAnySavedReceiptIsReturned(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->receive($receive);
        $this->refused(AccountOperationStepRefusedException::class, static fn () => AccountMergeReceiving::run($receive->id, self::SURVIVOR));
        $this->refused(AccountOperationStepRefusedException::class, fn () => $this->asPerson(self::LOSER,
            static fn () => AccountMergeReceiving::run($receive->id, self::SURVIVOR)));
        $this->refused(AccountOperationStepRefusedException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn () => AccountMergeReceiving::run($give->id, self::SURVIVOR)));
    }

    public function testExactReplayAfterFinishAndCopyCleanupReadsNoBusinessState(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        $result = $this->receive($receive);
        self::assertTrue($operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $next = AccountMergeOpening::open(self::SURVIVOR, 11, new AccountMergeChoice(null, null, null, null));
        $preparation = Hilos::$db->accountOperationAssignments->byIdentity(
            $next->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value,
        );
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($preparation->id));
        self::assertSame($next->id, AccountMergePreparation::heldBy(self::SURVIVOR)->id);
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id]);
        $this->freshContext(true);
        $counters = Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_delete', 'Com_insert')")->rows();
        $replay = $this->receive($receive, $handover);
        self::assertSame($result->id, $replay->id);
        self::assertSame($result->payload->toJson(), $replay->payload->toJson());
        self::assertSame($counters, Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_delete', 'Com_insert')")->rows());
        self::assertSame($next->id, AccountMergePreparation::heldBy(self::SURVIVOR)->id);
    }

    /** @return list<array{bool}> A foreign owner or a malformed receipt cannot be replayed */
    public static function savedReceiptDefects(): array
    {
        return [[true], [false]];
    }

    /** @param bool $foreignOwner Whether saved ownership rather than the exact payload is corrupt */
    #[DataProvider('savedReceiptDefects')]
    public function testSavedReceiptRemainsStrictWithoutBusinessReads(bool $foreignOwner): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->receive($receive);
        if ($foreignOwner) {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                ['other', $receive->id]);
        } else {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                ['{"receive":"received","extra":true}', $receive->id]);
        }
        $this->freshContext(true);
        $before = $this->snapshot();
        $this->refused(ValidationException::class, fn () => $this->receive($receive));
        self::assertSame($before, $this->snapshot());
    }

    /** @return list<array{?SecondFactorFate, bool, int, bool}> Fate, prepared source factor and own effective wait */
    public static function waits(): array
    {
        return [[SecondFactorFate::BOTH, true, 14, false], [SecondFactorFate::BOTH, true, 30, false],
            [SecondFactorFate::SURVIVOR, true, 30, false], [null, false, 14, false],
            [SecondFactorFate::BOTH, true, 14, true], [SecondFactorFate::SURVIVOR, true, 30, true], [null, false, 14, true]];
    }

    /**
     * @param ?SecondFactorFate $fate Chosen factor policy
     * @param bool $sourceProtected Whether source preparation captured confirmed factors
     * @param int $ownDays Own effective wait at receive time
     * @param bool $absentSetting Whether own settings must be created
     */
    #[DataProvider('waits')]
    public function testWaitUsesImmutableSourceAndCurrentOwnPolicyWithoutChangingMisses(
        ?SecondFactorFate $fate, bool $sourceProtected, int $ownDays, bool $absentSetting,
    ): void {
        if (!$sourceProtected) {
            Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `user_id` = ?', [self::LOSER]);
        }
        Database::sqlRun('UPDATE `hilos_second_factor_setting` SET `reset_wait_days` = ?, `pending_reset_wait_days` = 7,'
            . ' `pending_reset_wait_from` = ?, `app_code_misses` = 3, `app_code_misses_from` = -1,'
            . ' `app_code_lock_step` = 2, `app_code_locked_until` = 1 WHERE `user_id` = ?', [
                $ownDays, PHP_INT_MAX, self::SURVIVOR,
            ]);
        if ($absentSetting) {
            Database::sqlRun('DELETE FROM `hilos_second_factor_setting` WHERE `user_id` = ?', [self::SURVIVOR]);
        }
        [$operation, $give] = $this->ready($fate);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $before = Database::sql('SELECT * FROM `hilos_second_factor_setting` WHERE `user_id` = ?', [self::SURVIVOR])->firstRow();
        $this->receive($receive);
        $after = Database::sql('SELECT * FROM `hilos_second_factor_setting` WHERE `user_id` = ?', [self::SURVIVOR])->firstRow();
        if ($fate === SecondFactorFate::BOTH) {
            self::assertSame((string)max(21, $absentSetting ? 21 : $ownDays), $after['reset_wait_days']);
            self::assertNull($after['pending_reset_wait_days']);
            self::assertNull($after['pending_reset_wait_from']);
            foreach (['app_code_misses', 'app_code_misses_from', 'app_code_lock_step', 'app_code_locked_until'] as $key) {
                if ($before !== null) {
                    self::assertSame($before[$key], $after[$key]);
                }
            }
        } else {
            self::assertSame($before, $after);
        }
        self::assertSame($sourceProtected ? '0' : '1', Database::sql('SELECT COUNT(*) AS n FROM `hilos_second_factor_trust` WHERE `user_id` = ?',
            [self::SURVIVOR])->firstRow()['n']);
    }

    /** @return list<array{bool}> A fresh context and a new owner in the same stale context */
    public static function startupContexts(): array
    {
        return [[false], [true]];
    }

    /** @param bool $held Whether the new owner inherits stale held rows and View wrappers */
    #[DataProvider('startupContexts')]
    public function testActualStartupResumesCurrentOwnRowsWithoutAHandover(bool $held): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        Hilos::$db = new MergeReceivingProbeContext();
        Hilos::$db->configure();
        $reader = Hilos::$db;
        $identity = Hilos::$db->identities[$this->identityId];
        $password = Hilos::$db->identities[$own];
        $settings = Hilos::$db->secondFactorSettings[self::SURVIVOR];
        $code = Hilos::$db->secondFactorBackupCodes[$this->usedCodeId];
        $this->freshContext();
        [$operation, $give] = $this->ready(password: PasswordFate::SURVIVOR);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->asPerson(self::SURVIVOR, function (): void {
            Hilos::$db->identities[$this->identityId]->getObject()->identifier = 'current-passkey';
            Hilos::$db->identities[$this->identityId]->getObject()->sync();
            Hilos::$db->secondFactorBackupCodes[$this->usedCodeId]->getObject()->delete();
        });
        Database::sqlRun('UPDATE `hilos_second_factor_setting` SET `reset_wait_days` = 30, `app_code_misses` = 4 WHERE `user_id` = ?',
            [self::SURVIVOR]);
        Database::sqlRun('UPDATE `hilos_identity` SET `provider_name` = ? WHERE `id` = ?', ['current own name', $own]);
        if ($held) {
            Hilos::$db = $reader;
        } else {
            Hilos::$db = new MergeReceivingProbeContext();
            Hilos::$db->configure();
        }
        $startupContext = Hilos::$db;
        $this->releasePersonAgents();
        $newOwner = $this->personAgent(self::SURVIVOR);
        $this->drain();
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->onStart());
        self::assertSame([null, null, null, null, null], $startupContext->rowReadTransactions);
        $receipt = Hilos::$db->accountOperationResults->byAssignment($receive->id);
        self::assertNotNull($receipt);
        self::assertSame([], $newOwner->errors);
        self::assertSame('current-passkey', Hilos::$db->identities[$this->identityId]->identifier);
        self::assertSame(self::SURVIVOR, Hilos::$db->identities[$this->identityId]->userId);
        self::assertSame('current own name', Hilos::$db->identities[$own]->providerName);
        self::assertSame(30, Hilos::$db->secondFactorSettings[self::SURVIVOR]->resetWaitDays);
        self::assertSame(4, Hilos::$db->secondFactorSettings[self::SURVIVOR]->appCodeMisses);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactorBackupCodes)[$this->usedCodeId]));
        self::assertSame('key-original', Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject()->publicKey);
        self::assertSame("\xff\0\x80", Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject()->userHandle);
        if ($held) {
            self::assertSame($identity->getObject(), Hilos::$db->identities[$this->identityId]->getObject());
            self::assertSame('current-passkey', $identity->identifier);
            self::assertSame($password->getObject(), Hilos::$db->identities[$own]->getObject());
            self::assertSame($settings->getObject(), Hilos::$db->secondFactorSettings[self::SURVIVOR]->getObject());
            self::assertSame(30, $settings->resetWaitDays);
            self::assertSame(self::LOSER, $code->userId);
        }
        self::assertNull(AccountMergePreparation::heldBy(self::SURVIVOR));
        self::assertSame($operation->id, AccountMergePreparation::heldBy(self::LOSER)->id);
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->onStart());
        self::assertSame($receipt->id, Hilos::$db->accountOperationResults->byAssignment($receive->id)->id);
        self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
            [$receive->id])->firstRow()['n']);
    }

    public function testReceiptAndOwnProtectionRemainInvisibleToAPeerUntilTheSingleCommit(): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $this->peerConnection();
        $observed = [];
        SourceChangeBus::subscribe(new MergeReceivingCommitMirror(static function (SourceChange $change) use (
            $receive, $own, &$observed,
        ): void {
            if ($change->sourceKey === HilosDbContext::accountOperationResults) {
                $observed[] = [
                    Database::runtimeTransactionLevel(0) !== null,
                    Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
                        [$receive->id], index: self::PEER)->firstRow()['n'],
                    Database::sql('SELECT `type` FROM `hilos_identity` WHERE `id` = ?', [$own], index: self::PEER)->firstRow()['type'],
                ];
            }
        }));
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
        self::assertSame([[true, '0', IdentityType::PASSWORD]], $observed);
        self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
            [$receive->id], index: self::PEER)->firstRow()['n']);
        self::assertSame(IdentityType::MAGIC_LINK,
            Database::sql('SELECT `type` FROM `hilos_identity` WHERE `id` = ?', [$own], index: self::PEER)->firstRow()['type']);
        self::assertNull(AccountMergePreparation::heldBy(self::SURVIVOR));
    }

    public function testSnapshotUsesMountedRowConnectionsIndependentlyOfTheAmbientSqlConnection(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $this->receiveAssignment($operation->id);
        $this->peerConnection();
        Database::useConnection(self::PEER);
        try {
            $before = Database::sql("SHOW SESSION STATUS LIKE 'Com_select'", index: self::PEER)->firstRow()['Value'];
            $snapshot = $this->asPerson(self::SURVIVOR,
                static fn () => Hilos::$db->accountMergeFences->receiptSnapshot($give->id, self::SURVIVOR));
            $after = Database::sql("SHOW SESSION STATUS LIKE 'Com_select'", index: self::PEER)->firstRow()['Value'];
            self::assertSame($before, $after, 'All snapshot SQL follows the actual mounted Entity connection');
            self::assertNotEmpty($snapshot->rows[HilosDbContext::identities]);
        } finally {
            Database::useConnection(0);
        }
    }

    public function testLiveFenceWithoutItsCurrentRowWaitsInsteadOfRestoringTheBirthCopy(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $held = Hilos::$db->secondFactorBackupCodes[$this->codeId]->getObject();
        Database::sqlRun('DELETE FROM `hilos_second_factor_backup_code` WHERE `id` = ?', [$this->codeId]);
        $before = $this->snapshot();
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id)));
        self::assertSame($before, $this->snapshot());
        self::assertSame($held, Hilos::$db->secondFactorBackupCodes[$this->codeId]->getObject());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    public function testSnapshotNeedsOwnObligationAndAutocommitAndCarriesOnlyMappedColumns(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $read = static fn () => Hilos::$db->accountMergeFences->receiptSnapshot($give->id, self::SURVIVOR);
        $this->refused(InvalidFormatException::class, fn () => $this->asPerson(self::SURVIVOR, $read));
        $receive = $this->receiveAssignment($operation->id);
        $this->refused(AccountOperationStepRefusedException::class, fn () => $this->asPerson(self::LOSER, $read));
        Database::transactionStart();
        try {
            Database::sqlRun('SELECT `id` FROM `hilos_identity`');
            $this->refused(InvalidFormatException::class, fn () => $this->asPerson(self::SURVIVOR, $read));
        } finally {
            Database::transactionRollback();
        }
        $snapshot = $this->asPerson(self::SURVIVOR, $read);
        foreach ($snapshot->rows as $key => $rows) {
            $entityClass = Hilos::$db->mountedObjectCollection($key)::OBJECT_CLASS::ENTITY_CLASS;
            foreach ($rows as $row) {
                self::assertSame($entityClass::_columns, array_keys($row));
                self::assertSame(self::SURVIVOR, $row['user_id']);
                foreach (['secret', 'code', 'private_key'] as $private) {
                    self::assertArrayNotHasKey($private, $row);
                }
            }
        }
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
        $this->freshContext(true);
        $replay = $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
        self::assertSame($receive->id, $replay->assignmentId);
    }

    /** @return list<array{bool}> Advance inside the bracket or after snapshot, before the participant */
    public static function snapshotRaces(): array
    {
        return [[false], [true]];
    }

    /** @param bool $afterSnapshot Whether to advance after all snapshot SELECTs */
    #[DataProvider('snapshotRaces')]
    public function testFenceAdvanceWaitsBeforeAnyHydrationOrReceipt(bool $afterSnapshot): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $context = new MergeReceivingProbeContext();
        Hilos::$db = $context;
        $context->configure();
        $advance = function (): void {
            Database::sqlRun('UPDATE `hilos_account_merge_fence` SET `revision` = `revision` + 1'
                . ' WHERE `collection_key` = ? AND `row_id` = ?', [HilosDbContext::identities, $this->identityId]);
        };
        if ($afterSnapshot) {
            $context->afterSnapshot = $advance;
        } else {
            $context->beforeLastRows = $advance;
        }
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id)));
        self::assertSame([null, null, null, null, null], $context->rowReadTransactions);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[$this->identityId]));
        self::assertSame(IdentityType::PASSWORD, Hilos::$db->identities[$own]->type);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        self::assertSame($operation->id, AccountMergePreparation::heldBy(self::SURVIVOR)->id);
        self::assertFalse(Database::hasOpenTransactions());
    }

    public function testStartupSkipsAWholePageAndKeepsFailedObligationForTheNextStart(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        for ($i = 0; $i < 101; ++$i) {
            Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
                'unrelated-' . $i, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::SURVIVOR),
                self::SURVIVOR, AccountOperationAssignmentPayload::fromJson('{}'));
        }
        $receive = $this->receiveAssignment($operation->id);
        Database::sqlRun("CREATE TRIGGER `receiving_failure` BEFORE INSERT ON `hilos_account_operation_result`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'private storage diagnostics'");
        $this->freshContext();
        $agent = $this->personAgent(self::SURVIVOR);
        try {
            $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $owner) => $owner->onStart());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
            self::assertSame($operation->id, AccountMergePreparation::heldBy(self::SURVIVOR)->id);
            self::assertStringContainsString('remains pending at start', implode(' ', $agent->errors));
            self::assertStringNotContainsString('private storage diagnostics', implode(' ', $agent->errors));
            self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM `hilos_second_factor_trust` WHERE `user_id` = ?',
                [self::SURVIVOR])->firstRow()['n']);
        } finally {
            Database::sqlRun('DROP TRIGGER `receiving_failure`');
        }
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $owner) => $owner->onStart());
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    public function testMountedMappedSnapshotKeepsHeldIdentityAndInvalidatesDerivedCacheThroughRollback(): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        $reader = new MergeReceiptMountedContext();
        Hilos::$db = $reader;
        $reader->configure();
        $held = Hilos::$db->identities[$own];
        $object = $held->getObject();
        $before = $object->derivedName();
        $source = Hilos::$db->identities[$this->identityId];
        $this->freshContext();
        [$operation, $give] = $this->ready(password: PasswordFate::SURVIVOR);
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        Database::sqlRun('UPDATE `hilos_identity` SET `provider_name` = ? WHERE `id` = ?', ['current-derived-name', $own]);
        Hilos::$db = $reader;
        MergeReceiptIdentityEntity::$projectedOnly = true;
        MergeReceiptIdentityEntity::$tableReads = 0;
        Database::sqlRun("CREATE TRIGGER `receiving_failure` BEFORE INSERT ON `hilos_account_operation_result`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receipt storage failure'");
        try {
            $this->refused(DatabaseException::class, fn () => $this->asPerson(self::SURVIVOR,
                static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id)));
            self::assertSame($before, $object->derivedName());
            self::assertSame($object, Hilos::$db->identities[$own]->getObject());
            self::assertSame(self::LOSER, $source->userId);
        } finally {
            Database::sqlRun('DROP TRIGGER `receiving_failure`');
            MergeReceiptIdentityEntity::$projectedOnly = false;
        }
        MergeReceiptIdentityEntity::$projectedOnly = true;
        try {
            $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
            self::assertGreaterThan(0, MergeReceiptIdentityEntity::$tableReads);
            self::assertSame('current-derived-name', $object->derivedName());
            self::assertSame($object, Hilos::$db->identities[$own]->getObject());
            self::assertSame(self::SURVIVOR, $source->userId);
            self::assertSame($source->getObject(), Hilos::$db->identities[$this->identityId]->getObject());
        } finally {
            MergeReceiptIdentityEntity::$projectedOnly = false;
        }
    }

    public function testCurrentForeignOwnershipCannotMoveOrRemoveAHeldRow(): void
    {
        [$operation, $give] = $this->ready();
        $this->give($give);
        $receive = $this->receiveAssignment($operation->id);
        $held = Hilos::$db->identities[$this->identityId];
        $before = $held->toArray();
        Database::sqlRun('UPDATE `hilos_identity` SET `user_id` = 11 WHERE `id` = ?', [$this->identityId]);
        Database::sqlRun('UPDATE `hilos_account_merge_fence` SET `owner_user_id` = 11, `revision` = `revision` + 1'
            . ' WHERE `collection_key` = ? AND `row_id` = ?', [HilosDbContext::identities, $this->identityId]);
        $this->refused(AccountMergeReceiveWaitsException::class, fn () => $this->asPerson(self::SURVIVOR,
            static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id)));
        self::assertSame($before, $held->toArray());
        self::assertSame($held->getObject(), Hilos::$db->identities[$this->identityId]->getObject());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
    }

    public function testStartupWithNoReceiveObligationTakesNoBusinessSnapshot(): void
    {
        $context = new MergeReceivingProbeContext();
        Hilos::$db = $context;
        $context->configure();
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->onStart());
        self::assertSame([], $context->rowReadTransactions);
        self::assertSame([], $this->personAgent(self::SURVIVOR)->errors);
    }

    /** @return list<array{bool, bool, bool}> Demotion/deletion beside held or missing rows through warm receive and cold resume */
    public static function rollbackPasswords(): array
    {
        return [[true, false, false], [false, false, false], [true, true, false], [false, true, false],
            [true, false, true], [false, false, true], [true, true, true], [false, true, true]];
    }

    /**
     * @param bool $verified Whether own password is demoted rather than deleted
     * @param bool $held Whether the receiving context already holds old source objects
     * @param bool $resume Whether current startup snapshots replace the original handover
     */
    #[DataProvider('rollbackPasswords')]
    public function testReceiptFailureRollsBackOwnWritesHydrationProgressAndAnnouncements(bool $verified, bool $held, bool $resume): void
    {
        $own = $this->identity(self::SURVIVOR, IdentityType::PASSWORD, $verified);
        Database::sqlRun('UPDATE `hilos_identity` SET `secret` = ? WHERE `id` = ?',
            [ObjectIdentity::hashPassword('receipt-password'), $own]);
        $reader = Hilos::$db;
        $objects = [];
        if ($held) {
            foreach ([HilosDbContext::identities => [$this->identityId], HilosDbContext::passkeyCredentials => [$this->passkeyId],
                HilosDbContext::secondFactors => [$this->factorId],
                HilosDbContext::secondFactorBackupCodes => [$this->codeId, $this->usedCodeId]] as $key => $ids) {
                foreach ($ids as $id) {
                    $object = Hilos::$db->{$key}[$id]->getObject();
                    $objects[$key . ':' . $id] = [$key, $id, $object, $object->toArray()];
                }
            }
            $this->freshContext();
        }
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $handover = $this->handover($give);
        $receive = $this->receiveAssignment($operation->id);
        if ($held) {
            Hilos::$db = $reader;
        } else {
            $this->freshContext();
        }
        $password = Hilos::$db->identities[$own];
        $passwordObject = $password->getObject();
        self::assertTrue($passwordObject->verifyPassword('receipt-password'));
        $trustId = Database::sql('SELECT `id` FROM `hilos_second_factor_trust` WHERE `user_id` = ?', [self::SURVIVOR])->firstRow()['id'];
        $trust = Hilos::$db->secondFactorTrusts[$trustId]->getObject();
        $setting = Hilos::$db->secondFactorSettings[self::SURVIVOR];
        $settingBefore = $setting->toArray();
        $before = $this->snapshot();
        $reaction = new MergeReceivingReaction();
        SourceChangeBus::subscribe($reaction);
        $this->drain();
        Database::sqlRun("CREATE TRIGGER `receiving_failure` BEFORE INSERT ON `hilos_account_operation_result`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receiving storage failure'");
        try {
            $failure = $this->refused(DatabaseException::class, fn () => $resume
                ? $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id))
                : $this->receive($receive, $handover));
            self::assertStringContainsString('receiving storage failure', $failure->getMessage());
            self::assertSame($before, $this->snapshot());
            self::assertSame($passwordObject, Hilos::$db->identities[$own]->getObject());
            self::assertSame(IdentityType::PASSWORD, $password->type);
            self::assertTrue($passwordObject->verifyPassword('receipt-password'));
            self::assertFalse($passwordObject->hasChanges());
            self::assertSame($settingBefore, $setting->toArray());
            self::assertTrue($trust->isRelated());
            foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
                foreach ($handover->copy->{$key} as $row) {
                    if (isset($objects[$key . ':' . $row->id])) {
                        [, , $object, $values] = $objects[$key . ':' . $row->id];
                        self::assertSame($object, Hilos::$db->{$key}[$row->id]->getObject());
                        self::assertSame($values, $object->toArray());
                        self::assertFalse($object->hasChanges());
                    } else {
                        self::assertFalse(isset(Hilos::$db->mountedObjectCollection($key)[$row->id]));
                    }
                    $fences = Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeFences);
                    self::assertFalse(isset($fences[$key . ':' . $row->id]));
                }
            }
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->drain());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
        } finally {
            Database::sqlRun('DROP TRIGGER `receiving_failure`');
        }
        $this->receive($receive, $handover);
    }

    /**
     * @param int $operationId Prepared open merge
     * @return AccountOperationAssignment Survivor's separate receive obligation
     */
    private function receiveAssignment(int $operationId): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments->actions->record($operationId, AccountMergeStage::Go->value,
            AccountMergeDuty::Receive->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::SURVIVOR),
            self::SURVIVOR, AccountOperationAssignmentPayload::fromJson('{}'));
    }

    /**
     * @param AccountOperationAssignment $receive Assignment sent to the canonical survivor
     * @param ?AccountMergeHandover $handover Optional original source envelope
     * @return AccountOperationResult Durable receipt
     */
    private function receive(AccountOperationAssignment $receive, ?AccountMergeHandover $handover = null): AccountOperationResult
    {
        return $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->receiveForMerge($receive->id, $handover));
    }

    /**
     * @param AccountOperationAssignment $receive Existing generic ask with no secret payload
     * @return AccountOperationAnswerSignalData Actual correlated answer
     */
    private function deliver(AccountOperationAssignment $receive): AccountOperationAnswerSignalData
    {
        $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData(new AccountOperationAskSignalData($receive->operationId, $receive->stage, $receive->id, (string)self::SURVIVOR)),
            self::COORDINATOR, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
        ));
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                return $frame->data->data;
            }
        }
        self::fail('The receiving owner did not answer');
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
        return $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($give->id));
    }

    /**
     * @param AccountOperationAssignment $give Assignment whose original envelope is read
     * @return ?AccountMergeHandover Stored copy and original admission, never current business metadata
     */
    private function handover(AccountOperationAssignment $give): ?AccountMergeHandover
    {
        return $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::handover($give->id, self::LOSER));
    }

    /**
     * @param int $userId Owner of the new sign-in row
     * @param string $type Method kind under test
     * @param bool $verified Whether the address was verified
     * @param ?string $identifier Optional collision address
     * @return int Persisted identity id
     */
    private function identity(int $userId, string $type = IdentityType::MAGIC_LINK, bool $verified = true, ?string $identifier = null): int
    {
        Database::sql('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, '
            . '`password_changed_at`, `provider`, `provider_name`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $type, $identifier ?? $type . '-' . $userId . '@example.test',
                $type === IdentityType::PASSWORD ? 'password-hash-original' : null, (int)$verified,
                $type === IdentityType::PASSWORD ? 0 : null, null, null, -1001, 0]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Factor owner
     * @param bool $confirmed Whether enrollment finished
     * @return int Persisted factor id
     */
    private function factor(int $userId, bool $confirmed = true): int
    {
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `last_used_step`, `confirmed_at`, '
            . '`last_used_at`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'Authenticator', 'totp-secret-original', 23, $confirmed ? -1 : null, 0, -1001]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Backup code owner
     * @param ?int $usedAt Nullable original spend moment
     * @return int Persisted backup-code id
     */
    private function code(int $userId, ?int $usedAt = null): int
    {
        Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `used_at`, `created_at`) VALUES (?, ?, ?, ?)',
            [$userId, 'backup-code', $usedAt, -1]);

        return Database::lastInsertId();
    }

    /**
     * @param class-string<Throwable> $exception Expected original exception family
     * @param callable $work Operation to refuse
     * @return Throwable Actual failure for storage diagnostics
     */
    private function refused(string $exception, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($exception, $failure, $failure->getMessage());
            return $failure;
        }
        self::fail('An invalid receive was accepted');
    }

    /** Opens an independent real session on the same database, without changing the ORM binding. */
    private function peerConnection(): void
    {
        $config = Database::getConnectionConfig(0);
        Database::configure(index: self::PEER, host: $config->host, user: $config->user, password: $config->password,
            database: $config->database, port: $config->port, charset: $config->charset, socket: $config->socket);
        Database::connect(self::PEER);
    }

    /** @return array<string, list<array<string, mixed>>> All physical state, including private copy storage */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY 1')->rows();
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> Real queued DB-sync payloads */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame->data->toArray();
        }

        return $frames;
    }

    /**
     * @param string $userId Canonical personal owner address
     * @return AbstractUserAgent Real startup behavior with captured safe diagnostics
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeReceivingUserAgent($userId);
    }

    /** @param bool $denyBusiness Whether every business collection access must fail, even before SELECT */
    private function freshContext(bool $denyBusiness = false): void
    {
        Hilos::$db = $denyBusiness ? new MergeReceivingReplayContext() : new MergeReceivingContext();
        Hilos::$db->configure();
    }

    /** @param bool $down Whether to drop dependent tables in reverse order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }

}

final class MergeReceivingCoordinator extends AbstractSessionsLibraryAgent
{
}

class MergeReceivingContext extends HilosDbContext
{
    public bool $denyTransferredReads = false;

    /**
     * @param string $name Requested framework facade
     * @return mixed Actual facade, with transferred business reads forbidden while the warm port runs
     */
    public function __get(string $name): mixed
    {
        if ($this->denyTransferredReads && in_array($name, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true)) {
            throw new RuntimeException('Warm receive read a transferred business collection: ' . $name);
        }

        return parent::__get($name);
    }
}

/** A receipt replay must not touch either person's business state or private copy. */
final class MergeReceivingReplayContext extends HilosDbContext
{
    /**
     * @param string $name Requested framework collection
     * @return mixed Only immutable metadata is available to replay
     */
    public function __get(string $name): mixed
    {
        if (in_array($name, [self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes,
            self::secondFactorSettings, self::secondFactorTrusts, self::secondFactorResets, self::accountDeletions,
            self::users, self::accountMergeFences, self::accountMergeCopies], true)) {
            throw new RuntimeException('Replay read a business collection: ' . $name);
        }

        return parent::__get($name);
    }
}

final class MergeReceivingReaction implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Committed reactions */
    public array $seen = [];

    /**
     * @param SourceChange $change Committed source fact
     * @param SourceChangeProvenance $provenance Publisher provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change;
    }
}

/** Instrumentation only: the mounted ORM chains and SQL reader remain real. */
final class MergeReceivingProbeContext extends MergeReceivingContext
{
    public ?Closure $beforeLastRows = null;
    public ?Closure $afterSnapshot = null;
    /** @var list<?int> Transaction state at each mapped own row read */
    public array $rowReadTransactions = [];
    private bool $ownRowsRead = false;

    /**
     * @param string $collectionKey Actual mounted collection
     * @return ?Objects Unmodified mounted chain
     */
    public function mountedObjectCollection(string $collectionKey): ?Objects
    {
        if (in_array($collectionKey, [self::identities, self::passkeyCredentials, self::secondFactors,
            self::secondFactorBackupCodes, self::secondFactorSettings], true) && !$this->ownRowsRead) {
            $this->rowReadTransactions[] = Database::runtimeTransactionLevel(0);
            if ($collectionKey === self::secondFactorSettings) {
                $this->ownRowsRead = true;
                ($this->beforeLastRows ?? static function (): void {})();
            }
        }
        return parent::mountedObjectCollection($collectionKey);
    }

    /**
     * @param string $name Requested collection
     * @return mixed Actual inherited facade
     */
    public function __get(string $name): mixed
    {
        if ($name === self::accountOperationAssignments && $this->ownRowsRead && $this->afterSnapshot !== null) {
            $advance = $this->afterSnapshot;
            $this->afterSnapshot = null;
            $advance();
        }
        return parent::__get($name);
    }
}

final class MergeReceivingUserAgent extends AbstractUserAgent
{
    /** @var list<string> Safe startup diagnostics */
    public array $errors = [];

    /** @param string $message Safe failure without private diagnostics */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }

    /** @param string $message Startup information not needed by the case */
    protected function logAgentInfo(string $message): void
    {
    }
}

/** A whole inherited chain exercises the actual mounted Entity rather than a hardcoded framework class. */
final class MergeReceiptMountedContext extends HilosDbContext
{
    /** @return array<string, FrameworkExtension> Complete identity chain with derived cache instrumentation */
    protected function frameworkExtensions(): array
    {
        return [...parent::frameworkExtensions(), self::identities => new FrameworkExtension(
            MergeReceiptIdentitiesView::class, itemActions: MergeReceiptIdentityActions::class,
        )];
    }
}

final class MergeReceiptIdentityEntity extends EntityIdentity
{
    public static bool $projectedOnly = false;
    public static int $tableReads = 0;

    /**
     * @param array<string, mixed> $row Actual SQL projection at the Entity boundary
     * @return static Same mounted Entity contract
     */
    public static function fromRow(array $row): static
    {
        if (self::$projectedOnly && array_keys($row) !== static::_columns) {
            throw new RuntimeException('Receipt snapshot read columns outside the mounted mapping.');
        }
        return parent::fromRow($row);
    }

    /** @return string Physical table from the mounted Entity binding */
    public static function getSqlTableName(): string
    {
        ++self::$tableReads;
        return parent::getSqlTableName();
    }
}

final class MergeReceiptIdentityEntities extends EntityIdentities
{
    public const string ENTITY_CLASS = MergeReceiptIdentityEntity::class;
}

final class MergeReceiptIdentityObject extends ObjectIdentity
{
    public const string ENTITY_CLASS = MergeReceiptIdentityEntity::class;
    public const string OBJECT_COLLECTION_CLASS = MergeReceiptIdentitiesObjects::class;
    private ?string $derived = null;

    /** @return string Value derived from a mapped field, retained until full snapshot invalidation */
    public function derivedName(): string
    {
        return $this->derived ??= $this->providerName ?? 'original-derived-name';
    }

    /** Invalidates the fixture's derived value on snapshot hydration and rollback. */
    public function applyDbSyncUnmappedUpdate(): void
    {
        $this->derived = null;
    }
}

final class MergeReceiptIdentitiesObjects extends ObjectIdentities
{
    public const string OBJECT_CLASS = MergeReceiptIdentityObject::class;
    public const string ENTITY_COLLECTION_CLASS = MergeReceiptIdentityEntities::class;
}

final class MergeReceiptIdentityView extends ViewIdentity
{
}

final class MergeReceiptIdentitiesView extends ViewIdentities
{
    public const string DB_ITEM_CLASS = MergeReceiptIdentityView::class;
    public const string OBJECT_COLLECTION_CLASS = MergeReceiptIdentitiesObjects::class;
}

final class MergeReceiptIdentityActions extends IdentityActions
{
}

/** A mirror observes the instant of receipt insertion before the participant commits. */
final class MergeReceivingCommitMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe Peer observation during the write */
    public function __construct(private readonly Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Immediate memory fact
     * @param SourceChangeProvenance $provenance Publication origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

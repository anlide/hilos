<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\AccountMergeCopy as EntityAccountMergeCopy;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use Hilos\Auth\AccountOperation\AccountMergeGiving;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeCancellation;
use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Users\Agent\AbstractUserAgent;


/** The complete source handover, using real owner frames and real commits rather than a rollback root. */
final class AccountMergeGivingIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    private const string COORDINATOR = 'merge-giving-coordinator';
    private const int LOSER = 9;
    private const int SURVIVOR = 7;
    private const int PEER = 2;
    private const array TABLES = [
        'hilos_user', 'hilos_identity', 'hilos_passkey_credential', 'hilos_second_factor', 'hilos_second_factor_backup_code',
        'hilos_second_factor_setting', 'hilos_second_factor_reset', 'hilos_second_factor_trust',
        'hilos_user_merge', 'hilos_account_deletion', 'hilos_account_operation', 'hilos_account_operation_assignment',
        'hilos_account_operation_result', 'hilos_account_merge_copy',
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
        OwnershipDeclaration::claimDb(MergeGivingCoordinator::class, self::COORDINATOR);
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

    /** All four lists preserve the physical rows, cryptographic material, counters and nullable signed moments. */
    public function testFullHandoverCommitsCopyFactTombstoneAndBlockTogether(): void
    {
        $sourcePassword = $this->identity(self::LOSER, IdentityType::PASSWORD);
        $targetPassword = $this->identity(self::SURVIVOR, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::LOSER);
        $before = $this->snapshot();
        $result = $this->give($give);
        $copy = $this->copy($give);
        self::assertSame([$operation->id, $give->id, self::LOSER, self::SURVIVOR, $sourcePassword, $targetPassword, 21],
            [$copy->operationId, $copy->assignmentId, $copy->loserUserId, $copy->survivorUserId,
                $copy->loserPasswordIdentityId, $copy->survivorPasswordIdentityId, $copy->effectiveWaitDays]);
        self::assertSame([$this->identityId, $sourcePassword], array_column($copy->identities, 'id'));
        self::assertSame('password-hash-original', $copy->identities[1]->secret);
        self::assertSame('passkey', $copy->identities[0]->type);
        self::assertNull($copy->identities[0]->secret);
        self::assertSame([$this->passkeyId, $this->identityId, 'credential-original', 'key-original', -7, 17,
            '["usb", "internal"]', 'aaguid-original', "\xff\0\x80", 'Key', -1, 0],
            [$copy->passkeyCredentials[0]->id, $copy->passkeyCredentials[0]->identityId, $copy->passkeyCredentials[0]->credentialId,
                $copy->passkeyCredentials[0]->publicKey, $copy->passkeyCredentials[0]->algorithm, $copy->passkeyCredentials[0]->signCount,
                $copy->passkeyCredentials[0]->transports, $copy->passkeyCredentials[0]->aaguid, $copy->passkeyCredentials[0]->userHandle,
                $copy->passkeyCredentials[0]->label, $copy->passkeyCredentials[0]->lastUsedAt, $copy->passkeyCredentials[0]->createdAt]);
        self::assertSame([$this->factorId], array_column($copy->secondFactors, 'id'));
        self::assertSame(['totp-secret-original', 23, -1, 0, -1001], [$copy->secondFactors[0]->secret,
            $copy->secondFactors[0]->lastUsedStep, $copy->secondFactors[0]->confirmedAt,
            $copy->secondFactors[0]->lastUsedAt, $copy->secondFactors[0]->createdAt]);
        self::assertSame([$this->codeId, $this->usedCodeId], array_column($copy->secondFactorBackupCodes, 'id'));
        self::assertSame([null, -1], array_column($copy->secondFactorBackupCodes, 'usedAt'));
        self::assertSame('backup-code', $copy->secondFactorBackupCodes[0]->code);
        self::assertSame(PasswordFate::LOSER, AccountMergeGiveResult::of($result->payload)->passwordKept);
        self::assertSame(AccountMergeGiveResult::SECOND_FACTOR_BOTH, AccountMergeGiveResult::of($result->payload)->secondFactorKept);
        self::assertSame($give->id, $operation->mergeFact()->assignmentId);
        self::assertSame(self::SURVIVOR, Hilos::$db->userMerges[self::LOSER]->survivorUserId);
        self::assertTrue(Hilos::$db->users[self::LOSER]->block);
        self::assertArrayNotHasKey($this->unfinishedId, Hilos::$db->secondFactors);
        $this->assertTargetUnchanged($before);
        foreach ([$copy->identities, $copy->passkeyCredentials, $copy->secondFactors, $copy->secondFactorBackupCodes] as $rows) {
            foreach ($rows as $row) {
                self::assertSame(self::SURVIVOR, $row->userId);
            }
        }
        self::assertSame($copy->toJson(), AccountMergeHandoverCopy::fromJson($copy->toJson())->toJson());
    }

    /**
     * @param ?PasswordFate $fate Chosen survivor, loser, neither or automatic retention
     * @param bool $source Whether the source has a password
     * @param bool $target Whether the target has a password
     * @param bool $reverse Whether target or source id is minted first
     */
    #[DataProvider('passwordFates')]
    public function testPasswordFatesUsePreparationAnchorsWithoutWritingTheSurvivor(
        ?PasswordFate $fate, bool $source, bool $target, bool $reverse,
    ): void {
        $sourceId = null;
        $targetId = null;
        foreach ($reverse ? [self::SURVIVOR, self::LOSER] : [self::LOSER, self::SURVIVOR] as $user) {
            if ($user === self::LOSER && $source) {
                $sourceId = $this->identity($user, IdentityType::PASSWORD);
            } elseif ($user === self::SURVIVOR && $target) {
                $targetId = $this->identity($user, IdentityType::PASSWORD);
            }
        }
        [, $give] = $this->ready(password: $fate);
        $before = $this->snapshot();
        if ($fate === null && $source && $target) {
            $this->refused(LogicException::class, fn () => $this->give($give));
            self::assertSame($before, $this->snapshot());
            return;
        }
        $result = AccountMergeGiveResult::of($this->give($give)->payload);
        $expected = match ($fate) {
            PasswordFate::SURVIVOR => $target ? 'survivor' : 'none',
            PasswordFate::LOSER => $source ? 'loser' : 'none',
            PasswordFate::NONE => 'none',
            null => $target ? 'survivor' : ($source ? 'loser' : 'none'),
        };
        self::assertSame($expected, $result->passwordKept->value);
        if ($sourceId !== null) {
            $demoted = $fate !== null && $fate !== PasswordFate::LOSER;
            self::assertSame($demoted ? IdentityType::MAGIC_LINK : IdentityType::PASSWORD, Hilos::$db->identities[$sourceId]->type);
            $row = Database::sql('SELECT `secret`, `password_changed_at` FROM `hilos_identity` WHERE `id` = ?', [$sourceId])->firstRow();
            self::assertSame($demoted ? null : 'password-hash-original', $row['secret']);
            self::assertSame($demoted ? null : '0', $row['password_changed_at']);
        }
        self::assertSame($sourceId, $this->copy($give)->loserPasswordIdentityId);
        self::assertSame($targetId, $this->copy($give)->survivorPasswordIdentityId);
        $this->assertTargetUnchanged($before);
    }

    /** @return iterable<string, array{?PasswordFate, bool, bool, bool}> Every fate, cardinality and id order */
    public static function passwordFates(): iterable
    {
        foreach ([PasswordFate::SURVIVOR, PasswordFate::LOSER, PasswordFate::NONE, null] as $fate) {
            foreach ([false, true] as $source) {
                foreach ([false, true] as $target) {
                    foreach ([false, true] as $reverse) {
                        yield ($fate?->value ?? 'automatic') . '-' . (int)$source . (int)$target . (int)$reverse
                            => [$fate, $source, $target, $reverse];
                    }
                }
            }
        }
    }

    /** @param string $branch Existing own demotion outcome */
    #[DataProvider('demotions')]
    public function testOwnPasswordDemotionOrDeletionPrecedesTheCleanTransfer(string $branch): void
    {
        $id = $this->identity(self::LOSER, IdentityType::PASSWORD, $branch !== 'unverified');
        if ($branch === 'collision') {
            $this->identity(self::SURVIVOR, IdentityType::MAGIC_LINK, true, 'password-9@example.test');
        }
        [, $give] = $this->ready(password: PasswordFate::NONE);
        $this->give($give);
        $copy = $this->copy($give);
        if ($branch === 'verified') {
            self::assertSame([$this->identityId, $id], array_column($copy->identities, 'id'));
            self::assertSame([IdentityType::MAGIC_LINK, null, null],
                [$copy->identities[1]->type, $copy->identities[1]->secret, $copy->identities[1]->passwordChangedAt]);
            self::assertSame(2, AccountMergeGiveResult::of(Hilos::$db->accountOperationResults->byAssignment($give->id)->payload)->identitiesMoved);
        } else {
            self::assertArrayNotHasKey($id, Hilos::$db->identities);
            self::assertSame([$this->identityId], array_column($copy->identities, 'id'));
            self::assertNull($copy->loserPasswordIdentityId);
            self::assertSame(1, AccountMergeGiveResult::of(Hilos::$db->accountOperationResults->byAssignment($give->id)->payload)->identitiesMoved);
        }
    }

    /** @return iterable<string, array{string}> Existing verified, unverified and collision rules */
    public static function demotions(): iterable
    {
        foreach (['verified', 'unverified', 'collision'] as $branch) {
            yield $branch => [$branch];
        }
    }

    /** @param bool $both Whether confirmed source factors and all backup codes survive */
    #[DataProvider('factorFates')]
    public function testFactorFatesRemoveOnlySourceEnrollmentResetsSettingsAndTrusts(bool $both): void
    {
        [, $give] = $this->ready($both ? SecondFactorFate::BOTH : SecondFactorFate::SURVIVOR);
        $this->resetRow(self::LOSER);
        $this->resetRow(self::SURVIVOR);
        $before = $this->snapshot();
        $fact = AccountMergeGiveResult::of($this->give($give)->payload);
        $copy = $this->copy($give);
        self::assertSame($both ? [$this->factorId] : [], $fact->secondFactors);
        self::assertSame($both ? [$this->codeId, $this->usedCodeId] : [], $fact->secondFactorBackupCodes);
        self::assertSame($both ? 'both' : 'survivor', $fact->secondFactorKept);
        self::assertSame($both ? 21 : null, $copy->effectiveWaitDays);
        foreach (['hilos_second_factor_reset', 'hilos_second_factor_setting', 'hilos_second_factor_trust'] as $table) {
            self::assertSame([], Database::sql('SELECT * FROM `' . $table . '` WHERE `user_id` = ?', [self::LOSER])->rows());
        }
        self::assertArrayNotHasKey($this->unfinishedId, Hilos::$db->secondFactors);
        $this->assertTargetUnchanged($before);
    }

    /** @return iterable<string, array{bool}> Both versus survivor-only */
    public static function factorFates(): iterable
    {
        yield 'both' => [true];
        yield 'survivor' => [false];
    }

    /**
     * @param bool $source Whether the source preparation has confirmed protection
     * @param bool $target Whether the target preparation has confirmed protection
     * @param bool $both Whether source protection is preserved
     * @param string $expected Actual protection outcome from the two preparation anchors
     */
    #[DataProvider('protectionOutcomes')]
    public function testProtectionOutcomeUsesBothPreparationAnchors(bool $source, bool $target, bool $both, string $expected): void
    {
        foreach ([[self::LOSER, $source], [self::SURVIVOR, $target]] as [$user, $present]) {
            if (!$present) {
                Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `user_id` = ? AND `confirmed_at` IS NOT NULL', [$user]);
                Database::sqlRun('DELETE FROM `hilos_second_factor_backup_code` WHERE `user_id` = ?', [$user]);
            }
        }
        [, $give] = $this->ready($source ? ($both ? SecondFactorFate::BOTH : SecondFactorFate::SURVIVOR) : null);
        $result = AccountMergeGiveResult::of($this->give($give)->payload);
        self::assertSame($expected, $result->secondFactorKept);
        self::assertSame($source && $both ? [$this->factorId] : [], $result->secondFactors);
        self::assertSame($source && $both ? [$this->codeId, $this->usedCodeId] : [], $result->secondFactorBackupCodes);
    }

    /** @return iterable<string, array{bool, bool, bool, string}> Both, loser, survivor and none outcomes */
    public static function protectionOutcomes(): iterable
    {
        yield 'both retained' => [true, true, true, 'both'];
        yield 'source discarded' => [true, true, false, 'survivor'];
        yield 'only source retained' => [true, false, true, 'loser'];
        yield 'only target' => [false, true, false, 'survivor'];
        yield 'neither' => [false, false, false, 'none'];
    }

    /** A survivor-only choice requires actual survivor protection before either owner may give. */
    public function testDiscardingOnlySourceProtectionRefusesPreparationWithoutGivingRows(): void
    {
        Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `user_id` = ?', [self::SURVIVOR]);
        $operation = AccountMergeOpening::open(self::SURVIVOR, self::LOSER,
            new AccountMergeChoice(null, SecondFactorFate::SURVIVOR, null, null));
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value,
        );
        $before = $this->snapshot();
        $result = $this->asPerson(self::SURVIVOR, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
        $preparation = AccountMergePreparationResult::of($result->payload);
        self::assertFalse($preparation->isReserved());
        self::assertSame(AccountMergeRefusal::SurvivorWithoutSecondFactor, $preparation->refusal);
        self::assertNull($operation->mergeFact());
        self::assertArrayNotHasKey(self::LOSER, Hilos::$db->userMerges);
        self::assertFalse(Hilos::$db->users[self::LOSER]->block);
        $after = $this->snapshot();
        unset($before['hilos_account_operation_result'], $after['hilos_account_operation_result']);
        self::assertSame($before, $after);
    }

    /**
     * @param ?int $days Own days or default
     * @param ?int $pending Own pending shortening
     * @param ?int $from Signed boundary set in fixture data, never waited for
     * @param int $expected Expected saved effective days
     */
    #[DataProvider('waits')]
    public function testOwnWaitIsBoundedAndSnapshottedBeforeDeletion(?int $days, ?int $pending, ?int $from, int $expected): void
    {
        Database::sqlRun('UPDATE `hilos_second_factor_setting` SET `reset_wait_days` = ?, `pending_reset_wait_days` = ?, '
            . '`pending_reset_wait_from` = ? WHERE `user_id` = ?', [$days, $pending, $from, self::LOSER]);
        [, $give] = $this->ready();
        $before = $this->snapshot();
        $this->give($give);
        self::assertSame($expected, $this->copy($give)->effectiveWaitDays);
        $this->assertTargetUnchanged($before);
        $json = $this->copy($give)->toJson();
        Database::sqlRun('UPDATE `hilos_second_factor_setting` SET `reset_wait_days` = 88 WHERE `user_id` = ?', [self::SURVIVOR]);
        $this->freshContext(true);
        self::assertSame($json, $this->copy($give)->toJson());
        self::assertSame($give->id, $this->give($give)->assignmentId);
    }

    /** @return iterable<string, array{?int, ?int, ?int, int}> Defaults, bounds and pending moments around the transfer */
    public static function waits(): iterable
    {
        yield 'default' => [null, null, null, SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS];
        yield 'lower bound' => [0, null, null, SecondFactorSettings::DEFAULT_RESET_WAIT_MIN_DAYS];
        yield 'upper bound' => [65535, null, null, SecondFactorSettings::DEFAULT_RESET_WAIT_MAX_DAYS];
        yield 'pending future' => [21, 2, PHP_INT_MAX, 21];
        yield 'pending epoch boundary' => [21, 2, 0, 2];
        yield 'pending past' => [21, 2, -1, 2];
    }

    /** No own setting row still means the current default, captured durably without reading target settings. */
    public function testMissingOwnSettingsUseTheCurrentDefaultWait(): void
    {
        Database::sqlRun('DELETE FROM `hilos_second_factor_setting` WHERE `user_id` = ?', [self::LOSER]);
        [, $give] = $this->ready();
        $this->give($give);
        self::assertSame(SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS, $this->copy($give)->effectiveWaitDays);
    }

    /** Existing proof primitives may advance reserved rows, whereas account mutations remain closed. */
    public function testAcceptedProofStateBeforeTheFactIsCapturedWithoutReopeningReservedMutations(): void
    {
        [, $give] = $this->ready();
        $this->asPerson(self::LOSER, function (): void {
            Hilos::$db->passkeyCredentials[$this->passkeyId]->getObject()->recordUse(18);
            self::assertTrue(Hilos::$db->secondFactors[$this->factorId]->actions->acceptStep(24));
            self::assertTrue(Hilos::$db->secondFactorBackupCodes[$this->codeId]->actions->spend());
            $this->refused(ValidationException::class, static fn () => AccountMergeAccess::requireProtectionChange(self::LOSER));
        });
        $this->give($give);
        $copy = $this->copy($give);
        self::assertSame(18, $copy->passkeyCredentials[0]->signCount);
        self::assertNotNull($copy->passkeyCredentials[0]->lastUsedAt);
        self::assertSame(24, $copy->secondFactors[0]->lastUsedStep);
        self::assertNotNull($copy->secondFactorBackupCodes[0]->usedAt);
    }

    /** Synchronizing an accepted stored use must preserve the existing no-op for an unstored credential. */
    public function testUnpersistedPasskeyUseDoesNotCreateACredential(): void
    {
        $credential = ObjectPasskeyCredential::create();
        $before = $this->snapshot();
        $writes = $this->writeCounters();
        $credential->recordUse(0);
        self::assertSame($writes, $this->writeCounters());
        self::assertNull($credential->id);
        self::assertFalse($credential->isRelated());
        self::assertSame($before, $this->snapshot());
    }

    /**
     * @param string $table Physical write that fails after earlier handover work
     * @param string $event Insert or update refused by storage
     * @param bool $deletePassword Whether own password deletion must also roll back
     */
    #[DataProvider('storageFailures')]
    public function testStorageFailuresRestoreDatabaseSameObjectsMembershipAndAnnouncements(
        string $table, string $event, bool $deletePassword,
    ): void {
        $password = $this->identity(self::LOSER, IdentityType::PASSWORD, !$deletePassword);
        [, $give] = $this->ready(SecondFactorFate::SURVIVOR, PasswordFate::NONE);
        $resetId = $this->resetRow(self::LOSER);
        $trustId = (int)Database::sql('SELECT `id` FROM `hilos_second_factor_trust` WHERE `user_id` = ?', [self::LOSER])->firstRow()['id'];
        $objects = [];
        foreach ([HilosDbContext::identities => [$this->identityId, $password], HilosDbContext::passkeyCredentials => [$this->passkeyId],
            HilosDbContext::secondFactors => [$this->factorId, $this->unfinishedId],
            HilosDbContext::secondFactorBackupCodes => [$this->codeId, $this->usedCodeId],
            HilosDbContext::users => [self::LOSER], HilosDbContext::secondFactorSettings => [self::LOSER],
            HilosDbContext::secondFactorResets => [$resetId], HilosDbContext::secondFactorTrusts => [$trustId]] as $collection => $ids) {
            foreach ($ids as $id) {
                $object = Hilos::$db->{$collection}[$id]->getObject();
                $objects[] = [$collection, $id, $object, $object->toArray()];
            }
        }
        $before = $this->snapshot();
        $reaction = new MergeGivingReaction();
        SourceChangeBus::subscribe($reaction);
        $this->drain();
        Database::sqlRun('CREATE TRIGGER `giving_failure` BEFORE ' . $event . ' ON `' . $table
            . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'giving storage failure'");
        try {
            $failure = $this->refused(DatabaseException::class, fn () => $this->give($give));
            self::assertStringContainsString('giving storage failure', $failure->getMessage());
            self::assertSame($before, $this->snapshot());
            foreach ($objects as [$collection, $id, $object, $row]) {
                self::assertSame($object, Hilos::$db->{$collection}[$id]->getObject());
                self::assertSame($row, $object->toArray());
                self::assertTrue($object->isRelated());
                self::assertFalse($object->hasChanges());
            }
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->drain());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
            self::assertArrayNotHasKey($give->id, Hilos::$db->accountMergeCopies);
            self::assertArrayNotHasKey(self::LOSER, Hilos::$db->userMerges);
        } finally {
            Database::sqlRun('DROP TRIGGER `giving_failure`');
        }
        $this->give($give);
        self::assertNotNull($this->copy($give));
    }

    /** @return iterable<string, array{string, string, bool}> Failure cuts from the first transfer through result recording */
    public static function storageFailures(): iterable
    {
        foreach ([false, true] as $deleted) {
            foreach ([['hilos_passkey_credential', 'UPDATE'], ['hilos_user_merge', 'INSERT'], ['hilos_user', 'UPDATE'],
                ['hilos_account_merge_copy', 'INSERT'], ['hilos_account_merge_copy', 'UPDATE'],
                ['hilos_account_operation_result', 'INSERT']] as [$table, $event]) {
                yield $table . '-' . $event . '-' . (int)$deleted => [$table, $event, $deleted];
            }
        }
    }

    /** Source reactions and sync leave only after commit, with the tombstone preceding block. */
    public function testCommitPublicationOrderAndOrdinarySerializersNeverCarryThePrivateCopy(): void
    {
        $this->identity(self::LOSER, IdentityType::PASSWORD);
        [, $give] = $this->ready(password: PasswordFate::NONE);
        $reaction = new MergeGivingReaction();
        SourceChangeBus::subscribe($reaction);
        $this->drain();
        $sawCopy = false;
        $sawResult = false;
        SourceChangeBus::subscribe(new MergeGivingMirror(function (SourceChange $change) use ($reaction, &$sawCopy, &$sawResult): void {
            self::assertTrue(Database::hasOpenTransactions());
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->drain());
            $sawCopy = $sawCopy || $change->sourceKey === HilosDbContext::accountMergeCopies;
            $sawResult = $sawResult || $change->sourceKey === HilosDbContext::accountOperationResults;
            $this->assertNonsecret($change->toArray());
        }));
        $this->give($give);
        self::assertTrue($sawCopy);
        self::assertTrue($sawResult);
        $storedCopy = Hilos::$db->accountMergeCopies[$give->id];
        self::assertSame(['assignmentId', 'ownerAgentId', 'createdAt'], array_keys($storedCopy->toArray()));
        self::assertSame($storedCopy->toArray(), $storedCopy->toArray(toFrontend: true));
        $keys = array_column($reaction->seen, 0);
        self::assertLessThan(array_search(HilosDbContext::users, $keys, true), array_search(HilosDbContext::userMerges, $keys, true));
        self::assertContains(HilosDbContext::accountMergeCopies, $keys);
        self::assertSame(HilosDbContext::accountOperationResults, $keys[array_key_last($keys)]);
        foreach ($reaction->seen as [, $payload]) {
            $this->assertNonsecret($payload);
        }
        $frames = $this->drain();
        self::assertNotEmpty($frames);
        foreach ($frames as $frame) {
            $this->assertNonsecret($frame);
        }
        foreach ([
            Hilos::$db->identities->whereColumnIs(EntityIdentity::user_id, self::SURVIVOR),
            Hilos::$db->secondFactors->whereColumnIs(EntitySecondFactor::user_id, self::SURVIVOR),
            Hilos::$db->secondFactorBackupCodes->whereColumnIs(EntitySecondFactorBackupCode::user_id, self::SURVIVOR),
            Hilos::$db->accountMergeCopies->whereColumnIs(EntityAccountMergeCopy::assignment_id, $give->id),
            Hilos::$db->accountOperationResults->whereColumnIs(EntityAccountOperationResult::assignment_id, $give->id),
        ] as $collection) {
            $this->assertNonsecret($collection->toArray());
            foreach ($collection as $item) {
                $this->assertNonsecret($item->toArray());
                $this->assertNonsecret($item->getObject()->toArray());
            }
        }
    }

    /** Replay survives stage changes, a folded source, reconnect and a context that refuses every business read. */
    public function testExactReplayAfterCommitAdvanceFinishAndColdReconnectDoesNotTouchBusinessSets(): void
    {
        [, $give] = $this->ready();
        $fact = $this->give($give)->payload->toJson();
        $copy = $this->copy($give)->toJson();
        $before = $this->snapshot();
        self::assertSame($fact, $this->give($give)->payload->toJson());
        Database::sqlRun('UPDATE `hilos_account_operation` SET `stage` = ?, `finished_at` = ? WHERE `id` = ?',
            [AccountMergeStage::Go->value . '-later', 1, $give->operationId]);
        $this->drain();
        Database::close();
        Database::connect();
        $this->freshContext(true);
        $writes = $this->writeCounters();
        self::assertSame($fact, $this->give($give)->payload->toJson());
        self::assertSame($copy, $this->copy($give)->toJson());
        self::assertSame($writes, $this->writeCounters());
        self::assertSame([], $this->drain());
        $after = $this->snapshot();
        unset($before['hilos_account_operation'], $after['hilos_account_operation']);
        self::assertSame($before, $after);
    }

    /** @param string $defect Absent or corrupt secret copy, fact, owner or assignment */
    #[DataProvider('copyDefects')]
    public function testCopyRefusesForeignOrCorruptStorageAndNeverRebuilds(string $defect): void
    {
        [, $give] = $this->ready();
        $this->give($give);
        if ($defect === 'owner') {
            $this->asPerson(self::SURVIVOR,
                fn () => $this->refused(AccountOperationStepRefusedException::class, fn () => AccountMergeGiving::copy($give->id, self::LOSER)));
            return;
        }
        if ($defect === 'assignment') {
            $this->asPerson(self::LOSER,
                fn () => $this->refused(AccountOperationStepRefusedException::class, fn () => AccountMergeGiving::copy($give->id, self::SURVIVOR)));
            return;
        }
        if ($defect === 'missing') {
            Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?', [$give->id]);
        } elseif ($defect === 'missing fact') {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$give->id]);
        } elseif ($defect === 'payload') {
            Database::sqlRun('UPDATE `hilos_account_merge_copy` SET `payload_json` = ? WHERE `assignment_id` = ?', ['{}', $give->id]);
        } elseif ($defect === 'operation') {
            $copy = $this->copy($give)->toArray();
            $copy[AccountMergeHandoverCopy::operationId]++;
            Database::sqlRun('UPDATE `hilos_account_merge_copy` SET `payload_json` = ? WHERE `assignment_id` = ?',
                [json_encode($copy, JSON_THROW_ON_ERROR), $give->id]);
        } else {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?', ['{}', $give->id]);
        }
        $this->freshContext(true);
        $before = $this->snapshot();
        if ($defect === 'missing') {
            self::assertNull($this->copy($give));
            self::assertSame($give->id, $this->give($give)->assignmentId);
            self::assertSame($give->id, Hilos::$db->accountOperations[$give->operationId]->mergeFact()->assignmentId);
        } else {
            $this->refused(HilosException::class, fn () => $this->copy($give));
            if ($defect === 'fact') {
                $this->refused(InvalidFormatException::class, fn () => $this->give($give));
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{string}> Immutable address and private storage defects */
    public static function copyDefects(): iterable
    {
        foreach (['owner', 'assignment', 'missing', 'missing fact', 'payload', 'operation', 'fact'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** @param bool $cancelFirst Which durable boundary wins first */
    #[DataProvider('cancellationOrders')]
    public function testCancelAndGiveRespectTheFirstDurableBoundary(bool $cancelFirst): void
    {
        [$operation, $give] = $this->ready();
        if ($cancelFirst) {
            self::assertSame(AccountMergeCancellationState::WaitingOwners, AccountMergeCancellation::request($operation));
            $before = $this->snapshot();
            $this->refused(AccountOperationStepRefusedException::class, fn () => $this->give($give));
            self::assertNull($operation->mergeFact());
            self::assertSame($before, $this->snapshot());
        } else {
            $this->give($give);
            $before = $this->snapshot();
            self::assertSame(AccountMergeCancellationState::TooLate, AccountMergeCancellation::request($operation));
            self::assertSame($give->id, $operation->mergeFact()->assignmentId);
            self::assertSame($before, $this->snapshot());
        }
    }

    /** @return iterable<string, array{bool}> Cancellation before fact and after fact */
    public static function cancellationOrders(): iterable
    {
        yield 'cancel first' => [true];
        yield 'give first' => [false];
    }

    /** A real NOWAIT peer proves the metadata shared lock survives through copy and result recording. */
    public function testMetadataLockLastsThroughTheGiveTransactionAndFreshMetadataRefusesLateWork(): void
    {
        [$operation, $give] = $this->ready();
        $config = Database::getConnectionConfig(0);
        Database::configure(self::PEER, $config->host, $config->user, $config->password,
            $config->database, $config->port, $config->charset, $config->socket);
        Database::connect(self::PEER);
        $checked = [];
        SourceChangeBus::subscribe(new MergeGivingMirror(function (SourceChange $change) use ($operation, &$checked): void {
            if (!in_array($change->sourceKey, [HilosDbContext::accountMergeCopies, HilosDbContext::accountOperationResults], true)) {
                return;
            }
            Database::transactionStart(self::PEER);
            try {
                $this->refused(LockWaitTimeoutException::class, static fn () => Database::sql(
                    'SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT', [$operation->id], index: self::PEER,
                ));
                $checked[] = $change->sourceKey;
            } finally {
                Database::transactionRollback(self::PEER);
            }
        }));
        $this->give($give);
        self::assertContains(HilosDbContext::accountMergeCopies, $checked);
        self::assertContains(HilosDbContext::accountOperationResults, $checked);
        Database::transactionStart(self::PEER);
        try {
            self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation` WHERE `id` = ? FOR UPDATE NOWAIT',
                [$operation->id], index: self::PEER)->rows());
        } finally {
            Database::transactionRollback(self::PEER);
        }
    }

    /** @param string $defect Caller, preparation, stage or anchor defect */
    #[DataProvider('admissionDefects')]
    public function testAdmissionRefusesForeignExecutorMissingPreparationStageAndChangedCapturedIdWithoutWrites(string $defect): void
    {
        $id = $this->identity(self::LOSER, IdentityType::PASSWORD);
        [$operation, $give] = $this->ready(password: PasswordFate::NONE);
        if ($defect === 'preparation') {
            Database::sqlRun('DELETE r FROM `hilos_account_operation_result` r JOIN `hilos_account_operation_assignment` a'
                . ' ON a.id = r.assignment_id WHERE a.operation_id = ? AND a.assignment_key = ?',
                [$operation->id, AccountMergeDuty::PrepareSurvivor->value]);
            $this->freshContext();
        } elseif ($defect === 'stage') {
            Database::sqlRun('UPDATE `hilos_account_operation` SET `stage` = ? WHERE `id` = ?',
                [AccountMergeStage::Cancelling->value, $operation->id]);
        } elseif ($defect === 'factor anchor') {
            Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `id` = ?', [$this->factorId]);
            $this->factor(self::LOSER);
            $this->freshContext();
        } elseif ($defect === 'anchor') {
            Database::sqlRun('DELETE FROM `hilos_identity` WHERE `id` = ?', [$id]);
            $this->identity(self::LOSER, IdentityType::PASSWORD);
            $this->freshContext();
        }
        $before = $this->snapshot();
        if ($defect === 'executor') {
            $this->asPerson(self::SURVIVOR,
                fn () => $this->refused(AccountOperationStepRefusedException::class, fn () => AccountMergeGiving::run($give->id, self::LOSER)));
        } elseif ($defect === 'target update') {
            $this->asPerson(self::LOSER, function (): void {
                $object = Hilos::$db->secondFactors[$this->targetFactorId]->getObject();
                $object->label = 'Forbidden target edit';
                $this->refused(WriteNotAllowedException::class, fn () => $object->sync());
                self::assertFalse($object->hasChanges());
            });
        } else {
            $this->refused(HilosException::class, fn () => $this->give($give));
        }
        self::assertSame($before, $this->snapshot());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
    }

    /** @return iterable<string, array{string}> Every admission boundary precedes source writes */
    public static function admissionDefects(): iterable
    {
        foreach (['executor', 'preparation', 'stage', 'anchor', 'factor anchor', 'target update'] as $defect) {
            yield $defect => [$defect];
        }
    }

    /** An empty source still folds atomically; unrelated reference rows keep naming the original person. */
    public function testEmptyHandoverStillRecordsTheFactWithoutRepointingReferenceRows(): void
    {
        foreach (['hilos_passkey_credential', 'hilos_identity', 'hilos_second_factor_backup_code', 'hilos_second_factor'] as $table) {
            Database::sqlRun('DELETE FROM `' . $table . '` WHERE `user_id` = ?', [self::LOSER]);
        }
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)', [11, self::LOSER, 0]);
        $reference = Database::sql('SELECT * FROM `hilos_user_merge` WHERE `user_id` = 11')->rows();
        [, $give] = $this->ready(null);
        $result = AccountMergeGiveResult::of($this->give($give)->payload);
        self::assertSame([[], [], [], [], 0, 'none', 'survivor'], [$result->identities, $result->passkeyCredentials,
            $result->secondFactors, $result->secondFactorBackupCodes, $result->identitiesMoved,
            $result->passwordKept->value, $result->secondFactorKept]);
        self::assertSame([], $this->copy($give)->identities);
        self::assertTrue(Hilos::$db->users[self::LOSER]->block);
        self::assertSame(self::SURVIVOR, Hilos::$db->userMerges[self::LOSER]->survivorUserId);
        self::assertSame($reference, Database::sql('SELECT * FROM `hilos_user_merge` WHERE `user_id` = 11')->rows());
    }

    /** A lost generic answer is harmless; retry answers with the durable fact and never carries its private copy. */
    public function testLostAnswerAfterCommitReplaysOnlyTheDurableResult(): void
    {
        $this->identity(self::LOSER, IdentityType::PASSWORD, false);
        [, $give] = $this->ready(password: PasswordFate::NONE);
        $first = $this->deliver($give);
        self::assertSame(AccountOperationAnswerKind::Done, $first->answer);
        $fact = Hilos::$db->accountOperationResults->byAssignment($give->id)->payload->toJson();
        $before = $this->snapshot();
        $this->freshContext(true);
        $writes = $this->writeCounters();
        $second = $this->deliver($give);
        self::assertSame($writes, $this->writeCounters());
        self::assertSame(AccountOperationAnswerKind::Done, $second->answer);
        self::assertSame($first->toArray(), $second->toArray());
        $this->assertNonsecret($second->toArray());
        self::assertStringNotContainsString('identitiesMoved', json_encode($second->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($fact, Hilos::$db->accountOperationResults->byAssignment($give->id)->payload->toJson());
        self::assertSame($before, $this->snapshot());
    }

    /**
     * @param ?SecondFactorFate $fate Protection outcome for the real preparation pair
     * @param ?PasswordFate $password Password outcome from the immutable choice
     * @return array{AccountOperation, AccountOperationAssignment} Prepared operation and give address
     */
    private function ready(?SecondFactorFate $fate = SecondFactorFate::BOTH, ?PasswordFate $password = null): array
    {
        $operation = AccountMergeOpening::open(self::SURVIVOR, self::LOSER, new AccountMergeChoice($password, $fate, null, null));
        foreach ([[self::SURVIVOR, AccountMergeDuty::PrepareSurvivor], [self::LOSER, AccountMergeDuty::PrepareLoser]] as [$user, $duty]) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
            $result = $this->asPerson($user, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
            self::assertTrue(AccountMergePreparationResult::of($result->payload)->isReserved());
        }
        self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));
        $give = Hilos::$db->accountOperationAssignments->actions->record($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value, new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::LOSER),
            self::LOSER, AccountOperationAssignmentPayload::fromJson('{}'));

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
     * @param AccountOperationAssignment $give Assignment whose closed copy is read
     * @return ?AccountMergeHandoverCopy Stored copy, never built from current business rows
     */
    private function copy(AccountOperationAssignment $give): ?AccountMergeHandoverCopy
    {
        return $this->asPerson(self::LOSER, static fn () => AccountMergeGiving::copy($give->id, self::LOSER));
    }

    /**
     * @param AccountOperationAssignment $give Coordinator's existing ask to its source owner
     * @return AccountOperationAnswerSignalData Correlated generic confirmation, without a copy
     */
    private function deliver(AccountOperationAssignment $give): AccountOperationAnswerSignalData
    {
        $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData(new AccountOperationAskSignalData($give->operationId, $give->stage, $give->id, (string)self::LOSER)),
            self::COORDINATOR, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
        ));
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
                self::assertInstanceOf(AccountOperationAnswerSignalData::class, $frame->data->data);
                return $frame->data->data;
            }
        }
        self::fail('The source owner did not answer the give');
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
     * @param int $userId Person whose expired reset must be removed only on the source
     * @return int Created reset id for same-object rollback assertions
     */
    private function resetRow(int $userId): int
    {
        Database::sql('INSERT INTO `hilos_second_factor_reset` (`user_id`, `requested_at`, `effective_at`, `notified_at`, `cancel_token`)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$userId, -1, 0, 0, 'cancel-' . $userId]);

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
        self::fail('An invalid give was accepted');
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

    /** @param array<string, list<array<string, mixed>>> $before Target rows from before the give */
    private function assertTargetUnchanged(array $before): void
    {
        $after = $this->snapshot();
        foreach (['hilos_identity', 'hilos_passkey_credential', 'hilos_second_factor', 'hilos_second_factor_backup_code',
            'hilos_second_factor_reset', 'hilos_second_factor_setting', 'hilos_second_factor_trust'] as $table) {
            $target = static fn (array $row): bool => (int)$row['user_id'] === self::SURVIVOR;
            $old = array_values(array_filter($before[$table], $target));
            $oldIds = array_column($old, array_key_exists('id', $old[0] ?? []) ? 'id' : 'user_id');
            $current = array_values(array_filter($after[$table], static fn (array $row): bool => $target($row)
                && in_array($row[array_key_exists('id', $row) ? 'id' : 'user_id'], $oldIds, true)));
            self::assertSame($old, $current, $table);
        }
    }

    /** @return list<array<string, mixed>> Session counts catch even a redundant write of unchanged values */
    private function writeCounters(): array
    {
        return Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_update', 'Com_delete', 'Com_insert')")->rows();
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

    /** @param mixed $payload Generic serialization that must not contain secrets or private copy data */
    private function assertNonsecret(mixed $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['password-hash-original', 'totp-secret-original', 'backup-code', '"secret":', '"code":',
            'loserPasswordIdentityId', 'survivorPasswordIdentityId', 'effectiveWaitDays'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        if (is_array($payload) && (($payload['collectionKey'] ?? null) === HilosDbContext::accountMergeCopies
            || ($payload[SourceChange::FIELD_SOURCE_KEY] ?? null) === HilosDbContext::accountMergeCopies)) {
            self::assertStringNotContainsString('payload_json', $json);
            self::assertStringNotContainsString('payloadJson', $json);
        }
    }

    /** @param bool $denyBusiness Whether every business collection access must fail, even before SELECT */
    private function freshContext(bool $denyBusiness = false): void
    {
        Hilos::$db = $denyBusiness ? new MergeGivingReplayContext() : new MergeGivingContext();
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

/** The actual coordinator's claims, with no replacement business behavior. */
final class MergeGivingCoordinator extends AbstractSessionsLibraryAgent
{
}

/** A cold context that cannot load, enumerate or look up either person's business rows. */
final class MergeGivingContext extends HilosDbContext
{
}

/** Refuses all business reads on a cold replay. */
final class MergeGivingReplayContext extends HilosDbContext
{
    /**
     * @param string $name Requested framework collection
     * @return mixed Only metadata and private copy storage remain available to replay
     */
    public function __get(string $name): mixed
    {
        if (in_array($name, [self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes,
            self::secondFactorSettings, self::secondFactorTrusts, self::secondFactorResets, self::accountDeletions, self::users], true)) {
            throw new RuntimeException('Replay read a business collection: ' . $name);
        }

        return parent::__get($name);
    }
}

/** Observes only committed source reactions. */
final class MergeGivingReaction implements SourceChangeSubscriberInterface
{
    /** @var list<array{string, array<string, mixed>}> Committed source facts in publication order */
    public array $seen = [];

    /**
     * @param SourceChange $change Source fact whose delivery follows the real commit
     * @param SourceChangeProvenance $provenance Real publisher provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = [$change->sourceKey, $change->toArray()];
    }
}

/** Uses the existing synchronous mirror seam for transaction assertions without a production observer hook. */
final class MergeGivingMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $observe Assertion made during a real ORM write */
    public function __construct(private readonly Closure $observe)
    {
    }

    /**
     * @param SourceChange $change Synchronous source fact inside the transaction
     * @param SourceChangeProvenance $provenance Real publisher provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->observe)($change);
    }
}

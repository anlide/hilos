<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Users\DTO\UserBrowserTrustRenewDoneSignalData;
use Closure;
use Hilos\Auth\Library\Command\PasskeyCommands;
use Hilos\Auth\Library\Command\PasswordCommands;
use Hilos\Auth\Library\Command\StepUpCommands;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\StepUpMethodResolver;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Constants\HilosAgentType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\SecondFactorLockPolicy;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;

/** Saved facts, late owner frames and permitted proof over actual account-operation carriers. */
final class AccountMergeAccessIntegrationTest extends AccountMergeGuardIntegrationTestCase
{
    private const string BACKUP = 'abcdefghjk';
    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    private const array PROTECTION_TABLES = [
        'hilos_identity', 'hilos_second_factor', 'hilos_second_factor_backup_code', 'hilos_second_factor_setting',
        'hilos_second_factor_trust', 'hilos_second_factor_reset', 'hilos_step_up', 'hilos_passkey_credential',
    ];

    /** The genuine give proof owns private copy storage locally; older gate fixtures keep their marker helper. */
    protected function setUp(): void
    {
        parent::setUp();
        self::copyStub(true);
        self::copyStub(false);
    }

    /** Drop the local copy before the shared assignment carriers. */
    protected function tearDown(): void
    {
        try {
            self::copyStub(true);
        } finally {
            parent::tearDown();
        }
    }

    /** @return list<array{bool}> Old survivor address and the transferred password address */
    public static function receiptAddresses(): array
    {
        return [[true], [false]];
    }

    /** @param bool $oldAddress Whether sign-in uses the survivor's preexisting magic-link address */
    #[DataProvider('receiptAddresses')]
    public function testGenuineReceiveOpensOldAddressAndLateGrantOnlyAfterItsCommit(bool $oldAddress): void
    {
        $this->withProfile(function () use ($oldAddress): void {
            $survivor = ProfileIntegrationTestCase::USER_ID;
            $loser = self::person();
            $receive = $this->genuineReceive($survivor, $loser);
            $operation = $receive->operation;
            $preparations = Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();
            $login = static fn () => new PasswordCommands(new ProfileIntegrationLibrary())->login(
                ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                new LoginActionDTO($oldAddress ? 'old-address@example.test' : 'incoming@example.test', 'chosen-password'),
            );
            $this->expectMerging($login);
            $beforeReceiptSession = Hilos::$db->sessions->findByToken(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN);
            $beforeReceiptHolder = new MergeAccessTestSessionsHolder();
            $this->asHolder(static fn () => $beforeReceiptHolder->onSignalAgent(new AgentSignalData(new AuthSessionGrantSignalData(
                sessionToken: ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN, userId: $survivor,
                acceptKey: ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY, provenBy: StepUpMethod::PASSWORD,
            )), 'test', HilosSignalConstants::HILOS_AUTH_SESSION_GRANT));
            self::assertNull($beforeReceiptSession->userId);
            $this->asPerson($survivor, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
            self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
            self::assertNull(AccountMergePreparation::heldBy($survivor));
            self::assertSame($operation->id, AccountMergePreparation::heldBy($loser)->id);
            self::assertSame($preparations, array_slice(Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows(), 0, 3));
            self::drain();
            $login();
            $grants = array_values(array_filter($this->signals(), static fn (SignalDTO $signal): bool =>
                $signal->data instanceof AgentSignalData && $signal->data->data instanceof AuthSessionGrantSignalData));
            self::assertCount(1, $grants);
            self::assertSame($survivor, $grants[0]->data->data->userId);
            self::assertSame(StepUpMethod::PASSWORD, $grants[0]->data->data->provenBy);
            $session = Hilos::$db->sessions->findByToken(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN);
            $holder = new MergeAccessTestSessionsHolder();
            RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
            try {
                $holder->onSignalAgent($grants[0]->data, 'test', HilosSignalConstants::HILOS_AUTH_SESSION_GRANT);
            } finally {
                RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            }
            self::assertSame($survivor, $session->userId);
            self::assertNull($operation->finishedAt);
        });
    }

    /** @return list<array{string}> A different owner, malformed payload and a rolled-back result all retain the reservation */
    public static function receiptDefects(): array
    {
        return [['owner'], ['payload'], ['marker'], ['rollback']];
    }

    /** @param string $defect Invalid receipt condition */
    #[DataProvider('receiptDefects')]
    public function testAnInvalidOrRolledBackReceiptNeverUnlocksProtection(string $defect): void
    {
        $survivor = self::person();
        $loser = self::person();
        $receive = $this->genuineReceive($survivor, $loser);
        if ($defect === 'rollback') {
            Database::sqlRun("CREATE TRIGGER `receiving_failure` BEFORE INSERT ON `hilos_account_operation_result`"
                . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receipt storage failure'");
            try {
                try {
                    $this->asPerson($survivor, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
                    self::fail('A failing receipt committed');
                } catch (DatabaseException) {
                }
            } finally {
                Database::sqlRun('DROP TRIGGER `receiving_failure`');
            }
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->id));
            self::assertSame($receive->operationId, AccountMergePreparation::heldBy($survivor)->id);
            $this->assertProtectionRefused($survivor);
            $this->expectMerging(static fn () => AccountMergeAccess::requireSignIn($survivor));
        } else {
            $this->asPerson($survivor, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ?, `payload_json` = ? WHERE `assignment_id` = ?',
                [$defect === 'owner' ? 'foreign-owner' : $receive->owner->agentId(),
                    match ($defect) {
                        'payload' => '{"receive":"received","extra":true}',
                        'marker' => '{}',
                        default => '{"receive":"received"}',
                    }, $receive->id]);
            $savedContext = Hilos::$db;
            Hilos::$db = new HilosSessionTestDbContext();
            Hilos::$db->configure();
            try {
                try {
                    AccountMergeAccess::requireProtectionChange($survivor);
                    self::fail('A corrupt receipt unlocked protection');
                } catch (InvalidFormatException) {
                }
                try {
                    AccountMergeAccess::requireSignIn($survivor);
                    self::fail('A corrupt receipt unlocked sign-in');
                } catch (InvalidFormatException) {
                }
            } finally {
                Hilos::$db = $savedContext;
            }
        }
    }

    public function testAnOldGenuineReceiptDoesNotReleaseTheNextReservation(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $receive = $this->genuineReceive($survivor, $loser);
        $this->asPerson($survivor, static fn (AbstractUserAgent $agent) => $agent->resumeMergeReceipt($receive->id));
        $this->asHolder(static fn () => $receive->operation->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed));
        $next = $this->open($survivor, self::person());
        $this->reserve($next, $survivor);
        self::assertSame($next->id, AccountMergePreparation::heldBy($survivor)->id);
        $this->assertProtectionRefused($survivor);
    }

    /** A stage alone never reserves protection; a persisted preparation closes only its own side. */
    public function testPreparingNeedsASavedReservationAndGoNeedsAGiveFact(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        foreach ([$survivor, $loser] as $userId) {
            $this->asPerson($userId, static function () use ($userId): void {
                self::assertNull(AccountMergeAccess::reservation($userId));
                AccountMergeAccess::requireProtectionChange($userId);
                AccountMergeAccess::requireSignIn($userId);
            });
        }
        $this->reserve($operation, $survivor);
        $this->asPerson($loser, static fn () => AccountMergeAccess::requireProtectionChange($loser));
        $this->reserve($operation, $loser);
        foreach ([$survivor, $loser] as $userId) {
            $this->assertProtectionRefused($userId);
            $this->asPerson($userId, static fn () => AccountMergeAccess::requireSignIn($userId));
        }
        $this->go($operation);
        $this->asPerson($survivor, static fn () => AccountMergeAccess::requireSignIn($survivor));
    }

    /** A reader's earlier result miss cannot hide a give committed through another real carrier. */
    public function testPersistedGiveIsSeenWithoutDbSyncAfterAResultMiss(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $survivor);
        $this->reserve($operation, $loser);
        $assignment = $this->go($operation);
        $this->asPerson($survivor, static function () use ($survivor, $operation): void {
            self::assertNull($operation->mergeFact());
            self::assertFalse(AccountMergeAccess::blocksSignIn($survivor));
        });
        $reader = Hilos::$db;
        try {
            Hilos::$db = new HilosSessionTestDbContext();
            Hilos::$db->configure();
            $this->give($loser, $assignment);
        } finally {
            Hilos::$db = $reader;
        }
        $this->asPerson($survivor, static function () use ($survivor): void {
            self::assertTrue(AccountMergeAccess::blocksSignIn($survivor));
            try {
                AccountMergeAccess::requireSignIn($survivor);
                self::fail('A survivor with a saved give was signed in');
            } catch (ValidationException $failure) {
                self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $failure->getMessage());
            }
        });
        $this->asPerson($loser, static fn () => AccountMergeAccess::requireSignIn($loser));
        $this->assertProtectionRefused($survivor);
        $this->assertProtectionRefused($loser);
    }

    /** A merge without preparation, even at go, supplies no restriction of its own. */
    public function testGoWithoutPreparationIsInert(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->give($loser, $this->go($operation));
        foreach ([$survivor, $loser] as $userId) {
            $this->asPerson($userId, static function () use ($userId): void {
                AccountMergeAccess::requireProtectionChange($userId);
                AccountMergeAccess::requireSignIn($userId);
            });
        }
    }

    /** Only the saved release of this account opens its protection, including before its peer releases. */
    public function testReleaseIsPerSideAndOldReleaseDoesNotOpenANewReservation(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $survivor);
        $this->reserve($operation, $loser);
        $this->release($operation, $survivor);
        $this->asPerson($survivor, static fn () => AccountMergeAccess::requireProtectionChange($survivor));
        $this->assertProtectionRefused($loser);
        $next = $this->open($survivor, self::person());
        $this->reserve($next, $survivor);
        $this->release($operation, $survivor);
        $this->assertProtectionRefused($survivor);
    }

    /** A release with no preparation is inert for itself and does not open its reserved peer. */
    public function testReleaseWithoutPreparationAndRolledBackReleaseCannotOpenTheOtherSide(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $loser);
        $this->release($operation, $survivor);
        $this->asPerson($survivor, static fn () => AccountMergeAccess::requireProtectionChange($survivor));
        $this->assertProtectionRefused($loser);
        $assignment = self::assignment($operation, AccountMergeDuty::ReleaseLoser, AccountMergeStage::Cancelling);
        Database::sqlRun('ALTER TABLE `hilos_account_operation_result` ADD CONSTRAINT `merge_access_reject_release`'
            . ' CHECK (`assignment_id` <> ' . $assignment->id . ')');
        try {
            try {
                $this->asPerson($loser, static fn (AbstractUserAgent $agent) => $agent->releaseMergePreparation($assignment->id));
                self::fail('The fixture must roll the release back');
            } catch (DatabaseException) {
                $this->assertProtectionRefused($loser);
                self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
            }
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_result` DROP CONSTRAINT `merge_access_reject_release`');
        }
        $this->release($operation, $loser);
        $this->asPerson($loser, static fn () => AccountMergeAccess::requireProtectionChange($loser));
    }

    /** An opaque but invalid preparation payload never becomes permission to change protection. */
    public function testMalformedSavedReservationFailsClosed(): void
    {
        $survivor = self::person();
        $operation = $this->open($survivor, self::person());
        $assignment = self::assignment($operation, AccountMergeDuty::PrepareSurvivor);
        $this->asPerson($survivor, static fn () => AccountOperationParticipantStep::run(
            $assignment->id, static fn (): AccountOperationResultPayload => AccountOperationResultPayload::fromJson('{}'),
        ));
        $this->expectException(InvalidFormatException::class);
        $this->asPerson($survivor, static fn () => AccountMergeAccess::requireProtectionChange($survivor));
    }

    /**
     * @param bool $loserSide Whether the frame names the losing account
     */
    #[DataProvider('sides')]
    public function testLateProtectionFramesRefuseBeforeWritesOrProof(bool $loserSide): void
    {
        $survivor = self::person();
        $loser = self::person();
        $userId = $loserSide ? $loser : $survivor;
        self::seedIdentity($userId, IdentityType::PASSWORD, "merge{$userId}@example.test");
        $identityId = (int)Database::sql('SELECT `id` FROM `hilos_identity` WHERE `user_id` = ?', [$userId])->rows()[0]['id'];
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $userId);
        $now = TimeHelper::nowMs();
        self::seedSession('merge-trust-session', $userId, $now, null);
        $sessionId = (int)Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` = ?', ['merge-trust-session'])->rows()[0]['id'];
        Database::sql('INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
            . ' VALUES (?, ?, ?, ?, ?)', [$sessionId, $userId, $now + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, $now, $now]);
        self::seedProofRows($userId);
        $resetId = (int)Database::sql('SELECT `id` FROM `hilos_second_factor_reset` WHERE `user_id` = ?', [$userId])->rows()[0]['id'];
        $factorId = (int)Database::sql('SELECT `id` FROM `hilos_second_factor` WHERE `user_id` = ?', [$userId])->rows()[0]['id'];
        $tail = [self::REPLY, self::ACCEPT_KEY, null, HilosSignalConstants::PROFILE_CHANGE_PASSWORD, null];
        $frames = [
            [HilosSignalConstants::HILOS_USER_PASSWORD_REHASH, new UserPasswordRehashSignalData($userId, $identityId, 'rehashed', ...$tail)],
            [HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE,
                new UserPasswordChangeSignalData($userId, $identityId, 'changed', false, false, 0, ...$tail)],
            [HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY, new UserAddressVerifySignalData($userId, $identityId, ...$tail)],
            [HilosSignalConstants::HILOS_USER_EMAIL_CHANGE,
                new UserEmailChangeSignalData($userId, "merge{$userId}@example.test", 'moved@example.test', ...$tail)],
            [HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK, new UserIdentityUnlinkSignalData($userId, $identityId, ...$tail)],
            [HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE, new UserBrowserTrustRevokeSignalData($userId, $sessionId, true, self::REPLY)],
            [HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW,
                new UserBrowserTrustRenewSignalData($userId, $sessionId, $now + TimeConstants::MS_PER_SECOND, $now, self::REPLY)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM,
                new UserSecondFactorEnrollConfirmSignalData(
                    $userId, $factorId, Totp::codeAt(Base32::decode(self::SECRET), Totp::stepAt(time())), 'new label', ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE,
                new UserSecondFactorRemoveSignalData($userId, $factorId, self::BACKUP, true, ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL,
                new UserSecondFactorResetCancelSignalData($userId, $resetId, ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE, new UserSecondFactorWaitWriteSignalData($userId, 7, ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START, new UserSecondFactorEnrollStartSignalData($userId, ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE, new UserSecondFactorCodesIssueSignalData($userId, ...$tail)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE, new UserSecondFactorResetDueSignalData($userId, $resetId, self::REPLY)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND,
                new UserSecondFactorResetRemindSignalData($userId, $resetId, $now, self::REPLY)],
            [HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK, new UserSecondFactorUnlockSignalData($userId, self::REPLY, 'merge-unlock')],
        ];
        $before = self::protectionRows();
        foreach ($frames as [$name, $ask]) {
            $answer = $this->answer($userId, $name, $ask);
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $answer->error, $name);
            self::assertSame($before, self::protectionRows(), $name . ' changed protection before refusing');
            self::assertSame([], $this->otherSignals, $name . ' sent a notice while refusing');
        }
    }

    /** @return iterable<string, array{bool}> Both reserved accounts */
    public static function sides(): iterable
    {
        yield 'survivor' => [false];
        yield 'loser' => [true];
    }

    /** Proof still spends and rejects replay, but possession cannot cancel a reserved reset. */
    public function testPreFactProofSpendsWithoutAutoCancelAndStillCountsWrongAppCodes(): void
    {
        $survivor = self::person();
        $operation = $this->open($survivor, self::person());
        $this->reserve($operation, $survivor);
        self::seedProofRows($survivor);
        $tail = [self::REPLY, self::ACCEPT_KEY, null, HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR, null];
        $ask = new UserSecondFactorProveSignalData($survivor, self::BACKUP, true, true, false, null, null, ...$tail);
        $done = $this->answer($survivor, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $ask);
        self::assertNull($done->error);
        self::assertFalse($done->missed);
        self::assertFalse($done->resetCanceled);
        self::assertNotNull(Database::sql('SELECT `used_at` FROM `hilos_second_factor_backup_code` WHERE `user_id` = ?', [$survivor])
            ->rows()[0]['used_at']);
        self::assertNull(Database::sql('SELECT `canceled_at` FROM `hilos_second_factor_reset` WHERE `user_id` = ?', [$survivor])
            ->rows()[0]['canceled_at']);
        self::assertTrue($this->answer($survivor, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $ask)->missed);
        $code = Totp::codeAt(Base32::decode(self::SECRET), Totp::stepAt(time()));
        $app = new UserSecondFactorProveSignalData($survivor, $code, false, true, false, null, null, ...$tail);
        self::assertFalse($this->answer($survivor, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $app)->missed);
        self::assertTrue($this->answer($survivor, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $app)->missed);
        self::assertSame(1, (int)Database::sql('SELECT `app_code_misses` FROM `hilos_second_factor_setting` WHERE `user_id` = ?', [$survivor])
            ->rows()[0]['app_code_misses']);
        for ($miss = 1; $miss < SecondFactorLockPolicy::fromEnv()->misses(); $miss++) {
            $locked = $this->answer($survivor, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $app);
        }
        self::assertNotNull($locked->lockUntil, 'A reservation does not disable the wrong-code lock');
    }

    /** Passkey counters and step-up proof are permitted before give and refused before any write afterward. */
    public function testPasskeyUseAndStepUpFollowTheFactRatherThanTheProtectionGate(): void
    {
        $survivor = self::person();
        $loser = self::person();
        self::seedIdentity($survivor, IdentityType::PASSKEY, 'merge-passkey');
        $identityId = (int)Database::sql('SELECT `id` FROM `hilos_identity` WHERE `user_id` = ?', [$survivor])->rows()[0]['id'];
        Database::sql('INSERT INTO `hilos_passkey_credential` (`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`,'
            . ' `sign_count`, `user_handle`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$identityId, $survivor, 'merge-passkey', 'public key fixture', PasskeyAlgorithm::Es256->value, 1, 'merge-handle', TimeHelper::nowMs()]);
        $passkeyId = Database::lastInsertId();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $survivor);
        $tail = [self::REPLY, self::ACCEPT_KEY, null, HilosSignalConstants::HILOS_LOGIN, null];
        $proof = new UserPasskeyUseSignalData($survivor, $passkeyId, 2, null, null, ...$tail);
        self::assertNull($this->answer($survivor, HilosSignalConstants::HILOS_USER_PASSKEY_USE, $proof)->error);
        $confirmation = new UserStepUpRecordSignalData($survivor, hash('sha256', 'merge-browser'), StepUpOperationKey::CHANGE_EMAIL, ...$tail);
        self::assertNull($this->answer($survivor, HilosSignalConstants::HILOS_USER_STEP_UP_RECORD, $confirmation)->error);
        self::assertSame(2, (int)Database::sql('SELECT `sign_count` FROM `hilos_passkey_credential` WHERE `id` = ?', [$passkeyId])
            ->rows()[0]['sign_count']);
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_step_up` WHERE `user_id` = ?', [$survivor])->rows());
        $this->give($loser, $this->go($operation));
        $before = self::protectionRows();
        $late = new UserPasskeyUseSignalData($survivor, $passkeyId, 3, null, null, ...$tail);
        self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $this->answer($survivor, HilosSignalConstants::HILOS_USER_PASSKEY_USE, $late)->error);
        self::assertSame(AccountMergeAccess::REFUSED_MESSAGE,
            $this->answer($survivor, HilosSignalConstants::HILOS_USER_STEP_UP_RECORD, $confirmation)->error);
        self::assertSame($before, self::protectionRows());
    }

    /** Existing content keeps its ordinary owner door while protection is reserved, even after give. */
    public function testContentRenameIsNotClosedByTheProtectionOrSignInGate(): void
    {
        $survivor = self::person();
        $loser = self::person();
        $operation = $this->open($survivor, $loser);
        $this->reserve($operation, $survivor);
        $this->give($loser, $this->go($operation));
        $answer = $this->answer($survivor, HilosSignalConstants::HILOS_USER_RENAME, new UserRenameSignalData(
            $survivor, 'Content still open', $survivor, self::REPLY, self::ACCEPT_KEY, null,
            HilosSignalConstants::PROFILE_NAME_SET, null, null,
        ));
        self::assertNull($answer->error);
        self::assertSame('Content still open', Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$survivor])->rows()[0]['name']);
    }

    /** Both public fallbacks use the same safe domain sentence. */
    public function testSafeFallbacksCarryOnlyTheMergeRefusal(): void
    {
        self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, AuthMessages::forNoticeCode(AuthFlowOutcome::CODE_ACCOUNT_MERGING));
        self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, AuthMessages::forOAuthReason(OAuthResultSignalData::REASON_ACCOUNT_MERGING));
    }

    #[DataProvider('passwordOrders')]
    public function testPasswordAndOldAddressRefuseBeforeChoosingArrivedProtection(bool $incomingFirst, bool $oldAddress): void
    {
        $this->withProfile(function () use ($incomingFirst, $oldAddress): void {
            $survivor = ProfileIntegrationTestCase::USER_ID;
            $loser = self::person();
            $this->passwordRows($survivor, $incomingFirst);
            $operation = $this->open($survivor, $loser);
            $this->reserve($operation, $survivor);
            $this->reserve($operation, $loser);
            $give = $this->go($operation);
            self::assertNull($operation->mergeFact());
            $reader = Hilos::$db;
            try {
                Hilos::$db = new HilosSessionTestDbContext();
                Hilos::$db->configure();
                $this->give($loser, $give);
            } finally {
                Hilos::$db = $reader;
            }
            self::drain();
            $before = self::protectionRows();
            $this->expectMerging(static fn () => new PasswordCommands(new ProfileIntegrationLibrary())->login(
                ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                new LoginActionDTO($oldAddress ? 'old-address@example.test' : 'survivor@example.test', 'wrong for either password'),
            ));
            self::assertSame($before, self::protectionRows());
            self::assertNull(Hilos::$db->sessions->findByToken(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN)->userId);
            self::assertSame([], $this->signals());
        });
    }

    /** @return iterable<string, array{bool, bool}> Both password id orders and both address bindings */
    public static function passwordOrders(): iterable
    {
        foreach ([false, true] as $incomingFirst) {
            foreach ([false, true] as $oldAddress) {
                yield ($incomingFirst ? 'incoming first' : 'survivor first') . ($oldAddress ? ', old address' : ', current address')
                    => [$incomingFirst, $oldAddress];
            }
        }
    }

    public function testPostFactStepUpAndResolvedLibraryGrantRefuseBeforeProofSelection(): void
    {
        $this->withProfile(function (): void {
            $userId = ProfileIntegrationTestCase::USER_ID;
            $operation = $this->open($userId, self::person());
            $this->reserve($operation, $userId);
            $this->give($operation->loserUserId, $this->go($operation));
            self::drain();
            $before = self::protectionRows();
            $library = new ProfileIntegrationLibrary();
            $stepUp = new StepUpCommands($library, new PasskeyCommands($library));
            $this->expectMerging(static fn () => new StepUpMethodResolver()->resolve($userId));
            $this->expectMerging(static fn () => $stepUp->start(ProfileIntegrationTestCase::ACCEPT_KEY,
                new StepUpStartActionDTO(StepUpOperationKey::CHANGE_PASSWORD)));
            $this->expectMerging(static fn () => $stepUp->confirm(ProfileIntegrationTestCase::ACCEPT_KEY,
                new StepUpConfirmActionDTO(StepUpOperationKey::CHANGE_PASSWORD, StepUpMethod::PASSWORD, '', false, 'wrong', null)));
            $this->expectMerging(static fn () => $library->grantSession(
                new ActingSession(ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                    ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN, null), $userId,
            ));
            self::assertSame($before, self::protectionRows());
            self::assertSame([], $this->signals());
        });
    }

    #[DataProvider('signInProofs')]
    public function testLateHolderGrantsRefuseBeforeBindingRotationCreditOrTrust(?string $method, bool $secondFactor): void
    {
        $this->withProfile(function () use ($method, $secondFactor): void {
            $userId = ProfileIntegrationTestCase::USER_ID;
            $session = Hilos::$db->sessions->findByToken(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN);
            $this->asHolder(static fn () => $session->actions->holdPendingSecondFactor(
                $userId, SecondFactorPendingMode::VERIFY, TimeHelper::nowMs() + TimeConstants::MS_PER_SECOND, null,
            ));
            $operation = $this->open($userId, self::person());
            $this->reserve($operation, $userId);
            $this->give($operation->loserUserId, $this->go($operation));
            self::drain();
            $holder = new MergeAccessTestSessionsHolder();
            $this->asHolder(static fn () => $holder->onSignalAgent(new AgentSignalData(new AuthSessionGrantSignalData(
                sessionToken: ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN,
                userId: $userId, acceptKey: ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                requestId: 'late-grant', action: HilosSignalConstants::HILOS_LOGIN,
                secondFactorProven: $secondFactor, trustDevice: true, provenBy: $method,
            )), 'test', HilosSignalConstants::HILOS_AUTH_SESSION_GRANT));
            self::assertNull($session->userId);
            self::assertSame(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN, $session->token);
            self::assertNull($session->pendingSecondFactorUserId);
            self::assertCount(0, Hilos::$rt->hilosSessionRotations);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_step_up`')->rows());
            self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
            $frames = [];
            foreach ($this->signals() as $signal) {
                self::assertNotSame(HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW, $signal->signalName->getName());
                self::assertNotSame(HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT, $signal->signalName->getName());
                if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                    $frames[] = $signal->data->data;
                }
            }
            self::assertNotEmpty($frames);
            $answer = $frames[array_key_last($frames)];
            self::assertSame('late-grant', $answer->requestId);
            self::assertSame(AuthFlowOutcome::rejectTo(AuthFlowOutcome::CODE_ACCOUNT_MERGING, AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN, AccountMergeAccess::REFUSED_MESSAGE)->toArray(), $answer->outcome);
            self::assertNull($answer->rotationTicket);
        });
    }

    /** @return iterable<string, array{?string, bool}> Every proof ends at the same holder door */
    public static function signInProofs(): iterable
    {
        foreach ([StepUpMethod::PASSWORD, StepUpMethod::PASSKEY, StepUpMethod::EMAIL_CODE, StepUpMethod::SMS_CODE] as $method) {
            yield $method => [$method, false];
        }
        yield 'second factor proven' => [null, true];
    }

    #[DataProvider('optionalTrustReservations')]
    public function testLostOptionalTrustAskAndRefusedAnswerLeaveTheSuccessfulLoginStanding(bool $reserved): void
    {
        $this->withProfile(function () use ($reserved): void {
            RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
            try {
                $holder = new MergeAccessTestSessionsHolder();
                $userId = ProfileIntegrationTestCase::USER_ID;
                if ($reserved) {
                    $this->reserve($this->open($userId, self::person()), $userId);
                }
                self::drain();
                $holder->onSignalAgent(new AgentSignalData(new AuthSessionGrantSignalData(
                    sessionToken: ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN,
                    userId: $userId, acceptKey: ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                    secondFactorProven: true, trustDevice: true,
                )), 'test', HilosSignalConstants::HILOS_AUTH_SESSION_GRANT);
                $asks = [];
                $states = [];
                foreach ($this->signals() as $signal) {
                    if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW) {
                        $asks[] = $signal->data->data;
                    }
                    if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                        $states[] = $signal->data->data;
                    }
                }
                self::assertCount(1, $asks);
                self::assertNotEmpty($states);
                $signedIn = $states[array_key_last($states)];
                self::assertSame($userId, $signedIn->userId);
                self::assertNotNull($signedIn->rotationTicket);
                $session = Hilos::$db->sessions[$signedIn->sessionId];
                self::assertSame($userId, $session->userId);
                self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
                // A dropped ask produces neither a trust nor a retry. Deliver it later: no confirmed factor remains.
                self::assertSame([], $this->signals());
                $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
                    new AgentSignalData($asks[0]), self::HOLDER_ID, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW,
                ));
                $answers = $this->signals();
                self::assertCount(1, $answers);
                self::assertInstanceOf(UserBrowserTrustRenewDoneSignalData::class, $answers[0]->data->data);
                self::assertFalse($answers[0]->data->data->renewed);
                self::assertSame($reserved ? AccountMergeAccess::REFUSED_MESSAGE : null, $answers[0]->data->data->error);
                $holder->onSignalAgent($answers[0]->data, 'test', HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE);
                self::assertSame($userId, $session->userId);
                self::assertSame($signedIn->sessionToken, $session->token);
                self::assertSame([], $this->signals());
                self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
            } finally {
                RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            }
        });
    }

    /** @return iterable<string, array{bool}> Ordinary negative answer and pre-fact protection refusal */
    public static function optionalTrustReservations(): iterable
    {
        yield 'factor gone' => [false];
        yield 'reserved protection' => [true];
    }

    public function testPreFactPasswordProofStillReachesTheHolderWithoutRehash(): void
    {
        $this->withProfile(function (): void {
            $userId = ProfileIntegrationTestCase::USER_ID;
            $now = TimeHelper::nowMs();
            Database::sqlRun('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$userId, IdentityType::PASSWORD, 'survivor@example.test', password_hash('original proof', PASSWORD_BCRYPT), $now, $now]);
            $operation = $this->open($userId, self::person());
            $this->reserve($operation, $userId);
            $this->go($operation);
            self::drain();
            new PasswordCommands(new ProfileIntegrationLibrary())->login(ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
                new LoginActionDTO('survivor@example.test', 'original proof'));
            $signals = $this->signals();
            self::assertCount(1, $signals);
            self::assertSame(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, $signals[0]->signalName->getName());
            self::assertSame($userId, $signals[0]->data->data->userId);
        });
    }

    /**
     * @param Closure(): mixed $action Real sign-in or proof-selection door
     */
    private function expectMerging(Closure $action): void
    {
        try {
            $action();
            self::fail('Post-fact sign-in reached account protection');
        } catch (ValidationException $failure) {
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $failure->getMessage());
        }
    }

    /** @param bool $down Whether to remove the private copy fixture */
    private static function copyStub(bool $down): void
    {
        // external-boundary: migration stub names carry direction only on down
        $suffix = $down ? '_down' : '';
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2)
            . '/backend/Database/Migration/Stub/create_hilos_account_merge_copy' . $suffix . '.sql'));
    }

    /**
     * @param int $survivor Existing own password and old magic-link address
     * @param int $loser Source of the chosen password
     * @return AccountOperationAssignment Real prepared and given survivor obligation
     */
    private function genuineReceive(int $survivor, int $loser): AccountOperationAssignment
    {
        self::seedIdentity($survivor, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity($survivor, IdentityType::MAGIC_LINK, 'old-address@example.test');
        self::seedIdentity($loser, IdentityType::PASSWORD, 'incoming@example.test');
        foreach ([$survivor => 'previous-password', $loser => 'chosen-password'] as $userId => $password) {
            Database::sqlRun('UPDATE `hilos_identity` SET `secret` = ? WHERE `type` = ? AND `user_id` = ?',
                [Hilos::$db->identities->hashPassword($password), IdentityType::PASSWORD, $userId]);
        }
        $operation = $this->asHolder(static fn () => AccountMergeOpening::open(
            $survivor, $loser, new AccountMergeChoice(PasswordFate::LOSER, null, null, null),
        ));
        $this->reserve($operation, $survivor);
        $this->reserve($operation, $loser);
        $give = $this->go($operation);
        $this->asPerson($loser, static fn (AbstractUserAgent $agent) => $agent->giveForMerge($give->id));

        return $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Receive->value,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$survivor),
            $survivor, AccountOperationAssignmentPayload::fromJson('{}'),
        ));
    }

    /**
     * @param Closure(): void $test Business scenario using real profile connections
     */
    private function withProfile(Closure $test): void
    {
        $previousRt = Hilos::$rt;
        $previousSetting = Hilos::$setting;
        try {
            Hilos::$setting = new SettingsAccessor(ProfileIntegrationSettingsCatalog::class);
            $rt = new ProfileIntegrationRtContext();
            $rt->mountFeatureRuntime([new AuthFeature()]);
            $rt->configure();
            $rt->bindStateCollectionNames();
            Hilos::$rt = $rt;
            self::seedSession(ProfileIntegrationTestCase::SESSION_TOKEN, ProfileIntegrationTestCase::USER_ID, TimeHelper::nowMs(), null);
            self::seedSession(ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN, null, TimeHelper::nowMs(), null);
            $test();
        } finally {
            Hilos::$rt = $previousRt;
            Hilos::$setting = $previousSetting;
        }
    }

    /**
     * @param int $userId Survivor with a temporary incoming password and an old address
     * @param bool $incomingFirst Whether the incoming password receives the lower id
     */
    private function passwordRows(int $userId, bool $incomingFirst): void
    {
        $addresses = $incomingFirst ? ['incoming@example.test', 'survivor@example.test'] : ['survivor@example.test', 'incoming@example.test'];
        $now = TimeHelper::nowMs();
        foreach ($addresses as $address) {
            Database::sqlRun('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`, `verified`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$userId, IdentityType::PASSWORD, $address, 'unusable pending password', $now, $now]);
        }
        self::seedIdentity($userId, IdentityType::MAGIC_LINK, 'old-address@example.test');
    }

    /** @return list<SignalDTO> Every queued frame after a real handler */
    private function signals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param int $userId Reserved account
     */
    private function assertProtectionRefused(int $userId): void
    {
        $this->asPerson($userId, static function () use ($userId): void {
            try {
                AccountMergeAccess::requireProtectionChange($userId);
                self::fail('Reserved protection was changed');
            } catch (ValidationException $failure) {
                self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $failure->getMessage());
            }
        });
    }

    /** @param int $userId Owner of a confirmed app, an unused backup code and a reset */
    private static function seedProofRows(int $userId): void
    {
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [$userId, 'merge app', self::SECRET, $now, $now]);
        Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `created_at`) VALUES (?, ?, ?)',
            [$userId, self::BACKUP, $now]);
        Database::sql('INSERT INTO `hilos_second_factor_reset` (`user_id`, `requested_at`, `effective_at`, `notified_at`) VALUES (?, ?, ?, ?)',
            [$userId, $now, $now, $now]);
    }

    /** @return array<string, list<array<string, mixed>>> Independently persisted protection rows */
    private static function protectionRows(): array
    {
        $rows = [];
        foreach (self::PROTECTION_TABLES as $table) {
            $rows[$table] = Database::sql("SELECT * FROM `{$table}` ORDER BY 1")->rows();
        }

        return $rows;
    }
}

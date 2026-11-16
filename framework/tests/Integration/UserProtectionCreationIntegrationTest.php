<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\Command\PhoneCodeCommands;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Library\Command\PasskeyCommands;
use Hilos\Auth\Library\DTO\ConfirmPhoneCodeActionDTO;
use Hilos\Auth\Library\DTO\PasskeyLoginConfirmActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\Verification\VerificationType;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewDoneSignalData;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterConfirmActionDTO;
use Hilos\Auth\Library\DTO\PasskeyRegisterOptionsActionDTO;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Auth\WebAuthn\WebAuthnChallengeSigner;
use Hilos\Constants\EnvConstants;
use Hilos\Tests\Unit\Auth\WebAuthn\WebAuthnTestVectors;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Auth\SecondFactor\DTO\SecondFactorProfileReplyDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Http\DeviceName;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\SecondFactorLockPolicy;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Database\DatabaseException;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationDraft;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestDoneSignalData;
use Hilos\Users\DTO\IdentityAddData;
use Hilos\Users\DTO\OAuthIdentityAddData;
use Hilos\Users\DTO\PasskeyIdentityAddData;
use Hilos\Users\DTO\PasswordIdentityAddData;
use Hilos\Users\DTO\SmsIdentityAddData;
use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Users\DTO\UserIdentityAddSignalData;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real owner handlers and commits; no rollback root hides the ordering of Add and reservation. */
final class UserProtectionCreationIntegrationTest extends ProfileIntegrationTestCase
{
    private const string EMAIL = 'owner-add@example.test';
    private const string PASSWORD = 'correct horse battery';
    private const string HOLDER_ID = 'identity-add-holder';
    private const array TABLES = ['hilos_account_operation_assignment', 'hilos_account_operation_result'];

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(true);
        self::stubs(false);
        OwnershipDeclaration::claimDb(ProfileIntegrationHolder::class, self::HOLDER_ID);
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(true);
            TruthSourceRegistry::unregisterAgent(self::HOLDER_ID);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('orders')]
    public function testAddAndReservationAreSerializedAtThePersonalOwner(string $type, bool $reserveFirst): void
    {
        $ask = $this->ask($this->material($type));
        if ($reserveFirst) {
            $this->reserve();
        }
        $done = $this->deliver($ask);
        if (!$reserveFirst) {
            self::assertNull($done->error);
            self::assertGreaterThan(0, $done->identityId);
            $this->reserve();
        } else {
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $done->error);
            self::assertNull($done->identityId);
        }
        $rows = Database::sql('SELECT `id`, `secret`, `verified` FROM `hilos_identity` WHERE `user_id` = ?', [self::USER_ID])->rows();
        self::assertCount($reserveFirst ? 0 : 1, $rows);
        self::assertCount(!$reserveFirst && $type === IdentityType::PASSKEY ? 1 : 0,
            Database::sql('SELECT `id` FROM `hilos_passkey_credential`')->rows());
        if (!$reserveFirst && $type === IdentityType::PASSWORD) {
            self::assertTrue(password_verify(self::PASSWORD, $rows[0]['secret']));
            self::assertSame(1, (int)$rows[0]['verified']);
        }
    }

    /** @return iterable<string, array{string, bool}> Both handler orders for every addition */
    public static function orders(): iterable
    {
        foreach ([IdentityType::PASSWORD, IdentityType::SMS, IdentityType::OAUTH, IdentityType::PASSKEY] as $type) {
            yield $type . ' add first' => [$type, false];
            yield $type . ' reserve first' => [$type, true];
        }
    }

    public function testPasswordAbsenceIsCheckedAgainAfterTheCallerHasPreparedItsAsk(): void
    {
        $ask = $this->ask($this->material(IdentityType::PASSWORD));
        self::assertNull($this->deliver($ask)->error);
        $second = $this->ask(new PasswordIdentityAddData('another@example.test', 'new hash'));
        self::assertSame(AuthMessages::ALREADY_HAS_PASSWORD, $this->deliver($second)->error);
        $rows = Database::sql('SELECT `identifier`, `secret` FROM `hilos_identity` WHERE `user_id` = ?', [self::USER_ID])->rows();
        self::assertCount(1, $rows);
        self::assertSame(self::EMAIL, $rows[0]['identifier']);
        self::assertTrue(password_verify(self::PASSWORD, $rows[0]['secret']));
    }

    public function testVerifiedEmailCollisionIsCheckedAgainAtTheOwner(): void
    {
        $ask = $this->ask($this->material(IdentityType::PASSWORD));
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        self::assertSame(AuthMessages::EMAIL_IN_USE, $this->deliver($ask)->error);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity` WHERE `user_id` = ?', [self::USER_ID])->rows());
    }

    #[DataProvider('uniqueMaterials')]
    public function testUniqueCollisionsDoNotMoveAnExistingIdentity(string $type, string $refusal): void
    {
        $ask = $this->ask($this->material($type));
        self::assertNull($this->deliver($ask)->error);
        self::assertSame($refusal, $this->deliver($ask)->error);
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_identity`')->rows());
    }

    /** @return iterable<string, array{string, string}> Existing phone, provider and credential identities */
    public static function uniqueMaterials(): iterable
    {
        yield 'phone' => [IdentityType::SMS, AuthMessages::PHONE_IN_USE];
        yield 'provider' => [IdentityType::OAUTH, AuthMessages::INVALID_LINK];
        yield 'passkey' => [IdentityType::PASSKEY, AuthMessages::PASSKEY_ALREADY_REGISTERED];
    }

    #[DataProvider('missingLabels')]
    public function testMissingOrUnknownUserAgentStoresANullPasskeyLabel(?string $userAgent): void
    {
        $material = self::passkey(DeviceName::fromUserAgent($userAgent));
        $done = $this->deliver($this->ask($material));
        self::assertNull($done->error);
        $rows = Database::sql('SELECT `label`, `user_handle`, `identity_id`, `user_id` FROM `hilos_passkey_credential`')->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['label']);
        self::assertSame("\x00\xffhandle", $rows[0]['user_handle']);
        self::assertSame($done->identityId, (int)$rows[0]['identity_id']);
        self::assertSame(self::USER_ID, (int)$rows[0]['user_id']);
    }

    /** @return iterable<string, array{?string}> Headers with no recognizable device name */
    public static function missingLabels(): iterable
    {
        yield 'absent' => [null];
        yield 'unknown' => ['Unrecognized Agent'];
    }

    #[DataProvider('missingLabels')]
    public function testVerifiedProfileCeremonyHandsNullableLabelToTheOwner(?string $userAgent): void
    {
        $key = EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name;
        $previous = getenv($key);
        putenv($key . '=identity-add-test-secret');
        try {
            $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
            $config = WebAuthnConfig::fromEnv();
            $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
                WebAuthnChallengeSigner::PURPOSE_REGISTER, self::SESSION_TOKEN, self::USER_ID, $config->challengeTtlSeconds,
            );
            $vectors = new WebAuthnTestVectors($config->rpId);
            $authenticator = $vectors->authenticatorData(
                WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED
                    | WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
                0,
                $vectors->attestedCredentialData('profile-credential', str_repeat("\x00", 16)),
            );
            $this->submitStep(HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM, new PasskeyRegisterConfirmActionDTO(
                $challenge->token,
                Base64Url::encode($vectors->attestationObject($authenticator)),
                Base64Url::encode($vectors->clientDataJson($challenge->challenge, $config->origins[0])),
                ['usb'],
                $userAgent,
            ));
            $rows = Database::sql('SELECT `label`, `public_key`, `user_id` FROM `hilos_passkey_credential`')->rows();
            self::assertCount(1, $rows);
            self::assertNull($rows[0]['label']);
            self::assertSame($vectors->publicKeyPem, $rows[0]['public_key']);
            self::assertSame(self::USER_ID, (int)$rows[0]['user_id']);
        } finally {
            putenv($previous === false ? $key : $key . '=' . $previous);
        }
    }

    public function testReservedProfileRefusesBeforeOptionsOrAttestation(): void
    {
        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $this->reserve();
        $this->assertRefused(AccountMergeAccess::REFUSED_MESSAGE,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS, new PasskeyRegisterOptionsActionDTO());
        $this->assertRefused(AccountMergeAccess::REFUSED_MESSAGE,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM, new PasskeyRegisterConfirmActionDTO('invalid', '', '', [], null));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity`')->rows());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_passkey_credential`')->rows());
    }

    public function testPasskeyUseCompletionKeepsMergeWordsBeforeTheOrdinaryFailureMask(): void
    {
        foreach ([null, StepUpOperationKey::ADD_SIGN_IN_METHOD] as $operation) {
            foreach ([AccountMergeAccess::REFUSED_MESSAGE, 'ordinary failure'] as $reason) {
                $ask = new UserPasskeyUseSignalData(self::USER_ID, 1, 1, $operation,
                    $operation === null ? null : ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                    HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE, self::ACCEPT_KEY, null,
                    HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM, null);
                $done = UserPasskeyUseDoneSignalData::to($ask, ActionRefusal::said($reason));
                $this->library->onSignalAgent(new AgentSignalData($done), 'personal', HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE);
                $frames = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $frame): bool =>
                    $frame->data instanceof WebSocketSignalData && $frame->data->data instanceof PageActionErrorSignalData));
                self::assertCount(1, $frames);
                self::assertSame($reason === AccountMergeAccess::REFUSED_MESSAGE ? $reason
                    : ($operation === null ? AuthMessages::INVALID_PASSKEY : StepUpMessages::PASSKEY_NOT_CONFIRMED),
                    $frames[0]->data->data->reason);
            }
        }
    }

    public function testKnownDeviceNameSurvivesStorage(): void
    {
        self::assertNull($this->deliver($this->ask(self::passkey(DeviceName::fromUserAgent('Chrome Linux'))))->error);
        self::assertSame('Chrome on Linux', Database::sql('SELECT `label` FROM `hilos_passkey_credential`')->rows()[0]['label']);
    }

    #[DataProvider('failedWrites')]
    public function testAStorageFailureRollsBackTheIdentityAndItsSecretOrCredential(bool $passkey): void
    {
        $material = $passkey
            ? self::passkey(null, str_repeat('x', 70000))
            : new PasswordIdentityAddData(self::EMAIL, str_repeat('x', 70000));
        $done = $this->deliver($this->ask($material));
        self::assertNotNull($done->error);
        self::assertNull($done->identityId);
        self::assertSame([], Database::sql('SELECT `id`, `secret` FROM `hilos_identity`')->rows());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_passkey_credential`')->rows());
        self::assertNull(Hilos::$db->identities->findByIdentity($passkey ? IdentityType::PASSKEY : IdentityType::PASSWORD,
            $passkey ? Base64Url::encode('credential') : self::EMAIL));
        self::assertNull($this->deliver($this->ask($this->material($passkey ? IdentityType::PASSKEY : IdentityType::PASSWORD)))->error);
    }

    /** @return iterable<string, array{bool}> The second write of each two-part creation */
    public static function failedWrites(): iterable
    {
        yield 'password hash' => [false];
        yield 'credential' => [true];
    }

    public function testSessionOfAnotherPersonAndWrongPersonalAddressCreateNothing(): void
    {
        $ask = $this->ask($this->material(IdentityType::SMS));
        $foreignSession = Hilos::$db->sessions->findByToken(self::ADMIN_SESSION_TOKEN);
        $wrongSession = new UserIdentityAddSignalData($ask->userId, $foreignSession->id, $ask->identity, null,
            $ask->replySignal, $ask->acceptKey, $ask->requestId, $ask->action, null);
        self::assertSame('User session not found', $this->deliver($wrongSession)->error);
        $anonymous = Hilos::$db->sessions->findByToken(self::ANONYMOUS_SESSION_TOKEN);
        foreach ([$anonymous->id, 999999] as $sessionId) {
            $inactive = new UserIdentityAddSignalData($ask->userId, $sessionId, $ask->identity, null,
                $ask->replySignal, $ask->acceptKey, $ask->requestId, $ask->action, null);
            self::assertSame('User session not found', $this->deliver($inactive)->error);
        }
        $wrongPerson = new UserIdentityAddSignalData(self::OTHER_USER_ID, $ask->sessionId, $ask->identity, null,
            $ask->replySignal, $ask->acceptKey, $ask->requestId, $ask->action, null);
        $this->asPerson(self::USER_ID, static function (AbstractUserAgent $agent) use ($wrongPerson): void {
            $agent->onSignalAgent(new AgentSignalData($wrongPerson), 'caller', HilosSignalConstants::HILOS_USER_IDENTITY_ADD);
        });
        $answers = self::takeSignals();
        self::assertCount(1, $answers);
        self::assertNotNull($answers[0]->data->data->error);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity`')->rows());
    }

    public function testLostAndLateAnswersNeverRunALibraryFallbackInsert(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->confirmStepUp(StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $this->submit(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO(self::PASSWORD));
        $frames = self::takeSignals();
        $asks = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::HILOS_USER_IDENTITY_ADD));
        self::assertCount(1, $asks);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity` WHERE `type` = ?', [IdentityType::PASSWORD])->rows());
        $this->deliverToPerson($asks[0]);
        $answers = self::takeSignals();
        $done = array_values(array_filter($answers, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE));
        self::assertCount(1, $done);
        $before = Database::sql('SELECT * FROM `hilos_identity`')->rows();
        $this->reserve();
        $this->library->onSignalAgent($done[0]->data, 'personal', HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_identity`')->rows());
        $this->library->onSignalAgent($done[0]->data, 'personal', HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_identity`')->rows());
    }

    #[DataProvider('creationOrders')]
    public function testFactorCreationAndReservationAreSerialized(string $kind, bool $reserveFirst): void
    {
        $ask = self::factorAsk($kind);
        if ($reserveFirst) {
            $this->reserve();
        }
        $done = $this->deliverFactor($kind, $ask);
        if ($reserveFirst) {
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $done->error);
            self::assertSame([], Hilos::$db->secondFactors->listByUser(self::USER_ID));
            self::assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
            self::assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
        } else {
            self::assertNull($done->error);
            self::assertFalse(Database::hasOpenTransactions());
            $this->reserve();
            $refused = $this->deliverFactor($kind, $ask);
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $refused->error);
            match ($kind) {
                'enrollment' => self::assertSame($done->enrollment->authenticatorId,
                    Hilos::$db->secondFactors->unconfirmedOf(self::USER_ID)->id),
                'codes' => self::assertCount(count($done->codes), Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID)),
                'reset' => self::assertSame($done->resetId, Hilos::$db->secondFactorResets->liveOf(self::USER_ID)->id),
            };
        }
    }

    /** @return iterable<string, array{string, bool}> Both real handler orders for each new owner operation */
    public static function creationOrders(): iterable
    {
        foreach (['enrollment', 'codes', 'reset'] as $kind) {
            yield $kind . ' reserve first' => [$kind, true];
            yield $kind . ' add first' => [$kind, false];
        }
    }

    public function testTwoEnrollmentStartsLeaveOneUnfinishedAndRollbackKeepsItsRowAndSecret(): void
    {
        $first = $this->deliverFactor('enrollment', self::factorAsk('enrollment'));
        $firstRow = Hilos::$db->secondFactors[$first->enrollment->authenticatorId];
        $second = $this->deliverFactor('enrollment', self::factorAsk('enrollment'));
        self::assertNull($second->error);
        self::assertNotSame($first->enrollment->authenticatorId, $second->enrollment->authenticatorId);
        self::assertCount(1, Hilos::$db->secondFactors->listByUser(self::USER_ID));
        self::assertFalse(isset(Hilos::$db->secondFactors[$firstRow->id]));
        $row = Hilos::$db->secondFactors[$second->enrollment->authenticatorId];
        $before = Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows();
        self::failCreation(HilosDbContext::secondFactors);
        $failed = $this->deliverFactor('enrollment', self::factorAsk('enrollment'));
        self::assertNotNull($failed->error);
        self::assertNull($failed->enrollment);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
        self::assertSame($row->id, Hilos::$db->secondFactors[$row->id]->id);
        self::assertSame($row->readSecret(), Hilos::$db->secondFactors[$row->id]->readSecret());
        self::assertSame($second->enrollment->secret, $row->readSecret());
        self::assertCount(1, Hilos::$db->secondFactors->listByUser(self::USER_ID));
    }

    #[DataProvider('codeFailures')]
    public function testCodeReplacementRollbackAndReservationKeepSpentAndUnusedCodes(bool $reserve): void
    {
        $this->deliverFactor('enrollment', self::factorAsk('enrollment'));
        $codes = $this->deliverFactor('codes', self::factorAsk('codes'));
        $proof = new UserSecondFactorProveSignalData(self::USER_ID, $codes->codes[0], true, false, false, null, null,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE, self::ACCEPT_KEY, null,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW, null);
        $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData($proof), 'caller', HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE,
        ));
        self::takeSignals();
        $entries = Hilos::$db->secondFactorBackupCodes->entriesOf(self::USER_ID);
        self::assertTrue($entries[0]->used);
        self::assertFalse($entries[1]->used);
        $rows = Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID);
        $before = Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows();
        $ask = self::factorAsk('codes');
        if ($reserve) {
            $this->reserve();
        } else {
            self::failCreation(HilosDbContext::secondFactorBackupCodes, 2);
        }
        $failed = $this->deliverFactor('codes', $ask);
        self::assertNotNull($failed->error);
        self::assertNull($failed->codes);
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows());
        self::assertEquals($entries, Hilos::$db->secondFactorBackupCodes->entriesOf(self::USER_ID));
        foreach ($rows as $row) {
            self::assertSame($row->id, Hilos::$db->secondFactorBackupCodes[$row->id]->id);
            self::assertSame($row->usedAt, Hilos::$db->secondFactorBackupCodes[$row->id]->usedAt);
        }
    }

    /** @return iterable<string, array{bool}> Real write failure and a reservation overtaking the proved request */
    public static function codeFailures(): iterable
    {
        yield 'partial insert fails' => [false];
        yield 'reserved after proof' => [true];
    }

    /**
     * @param string $action Profile mutation whose proof was queued before reservation
     * @param string $proofKind Backup, valid app code or invalid app attempt
     */
    #[DataProvider('queuedProfileProofs')]
    public function testReservationOvertakesQueuedProfileProofWithoutSpendingOrCounting(string $action, string $proofKind): void
    {
        $now = TimeHelper::nowMs();
        $secret = 'JBSWY3DPEHPK3PXP';
        Database::sql('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [self::USER_ID, 'Existing app', $secret, $now, $now]);
        foreach (['abcdefghjk', 'mnpqrstuvw'] as $code) {
            Database::sql('INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `created_at`) VALUES (?, ?, ?)',
                [self::USER_ID, $code, $now]);
        }
        Database::sql('INSERT INTO `hilos_second_factor_setting` (`user_id`, `app_code_misses`, `app_code_misses_from`,'
            . ' `app_code_lock_step`, `app_code_locked_until`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?)',
            [self::USER_ID, SecondFactorLockPolicy::fromEnv()->misses() - 1, $now, 1, $now - 1, $now]);
        $this->confirmStepUp(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
        $code = match ($proofKind) {
            'backup' => 'abcdefghjk',
            'app' => Totp::codeAt(Base32::decode($secret), Totp::stepAt(time())),
            'invalid app' => 'not-an-app-code',
        };
        $dto = $action === HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START
            ? new ProfileSecondFactorEnrollStartActionDTO($code, $proofKind === 'backup')
            : new ProfileSecondFactorCodesRenewActionDTO($code, $proofKind === 'backup');
        self::takeSignals();
        $this->library->beginActionDispatch('queued-profile-proof');
        try {
            $this->submit($action, $dto);
            self::assertTrue($this->library->actionReplyDeferred());
        } finally {
            $this->library->endActionDispatch();
        }
        $asks = self::takeSignals();
        self::assertCount(1, $asks);
        self::assertSame(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE, $asks[0]->signalName->getName());
        $ask = $asks[0]->data->data;
        self::assertInstanceOf(UserSecondFactorProveSignalData::class, $ask);
        self::assertSame($action, $ask->action);
        self::assertSame('queued-profile-proof', $ask->requestId);
        $operation = $this->reserve();
        self::assertNull($operation->mergeFact());
        $beforeCodes = Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows();
        $beforeFactors = Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows();
        $beforeSettings = Database::sql('SELECT * FROM `hilos_second_factor_setting` ORDER BY `user_id`')->rows();
        $previousNotifier = Hilos::$notify;
        $notifier = new class extends HilosNotifier {
            /** @var list<NotificationDraft> Notices requested by the proof and its answer */
            public array $drafts = [];

            public function emit(NotificationDraft $draft): void
            {
                $this->drafts[] = $draft;
                parent::emit($draft);
            }
        };
        Hilos::$notify = $notifier;
        try {
            $this->deliverToPerson($asks[0]);
            $answers = self::takeSignals();
            self::assertCount(1, $answers);
            self::assertSame(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE, $answers[0]->signalName->getName());
            $done = $answers[0]->data->data;
            self::assertSame($ask, $done->ask);
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $done->error);
            self::assertNull($done->errorType);
            self::assertNull($done->errorDetail);
            self::assertFalse($done->missed);
            self::assertNull($done->lockMisses);
            self::assertNull($done->lockUntil);
            self::assertFalse($done->resetCanceled);
            $this->library->onSignalAgent($answers[0]->data, 'personal', HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE);
            $acks = self::takeSignals();
            self::assertCount(1, $acks, 'The refusal must not queue a creation ask or a notice');
            self::assertInstanceOf(WebSocketSignalData::class, $acks[0]->data);
            self::assertSame(self::ACCEPT_KEY, $acks[0]->data->targetAcceptKey);
            $ack = $acks[0]->data->data;
            self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
            self::assertSame($action, $ack->action);
            self::assertSame('queued-profile-proof', $ack->requestId);
            self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $ack->reason);
            self::assertNull($ack->errorType);
            self::assertNull($ack->errorDetail);
            self::assertSame([], $notifier->drafts);
            self::assertSame($beforeCodes, Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows());
            self::assertSame($beforeFactors, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
            self::assertSame($beforeSettings, Database::sql('SELECT * FROM `hilos_second_factor_setting` ORDER BY `user_id`')->rows());
        } finally {
            Hilos::$notify = $previousNotifier;
        }
    }

    /** @return iterable<string, array{string, string}> Every mutating profile proof overtaken before its owner check */
    public static function queuedProfileProofs(): iterable
    {
        foreach ([HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START, HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW] as $action) {
            foreach (['backup', 'app', 'invalid app'] as $proofKind) {
                yield $action . ' ' . $proofKind => [$action, $proofKind];
            }
        }
    }

    public function testResetReadsTheOwnerWaitAndNotifiesOnlyAfterCommitWithoutLeakingItsToken(): void
    {
        $previousNotifier = Hilos::$notify;
        $key = EnvConstants::HILOS_SECOND_FACTOR_CANCEL_URL->name;
        $previousUrl = getenv($key);
        putenv($key . '=https://example.test/cancel');
        $notifier = new class extends HilosNotifier {
            /** @var list<NotificationDraft> Requests observed after their commits */
            public array $drafts = [];

            public function emit(NotificationDraft $draft): void
            {
                UserProtectionCreationIntegrationTest::assertFalse(Database::hasOpenTransactions());
                UserProtectionCreationIntegrationTest::assertNotNull(Hilos::$db->secondFactorResets->liveOf($draft->userId));
                $this->drafts[] = $draft;
                parent::emit($draft);
            }
        };
        Hilos::$notify = $notifier;
        try {
            $ask = self::factorAsk('reset');
            $this->asPerson(self::USER_ID, static fn () =>
                Hilos::$db->secondFactorSettings->actions->setResetWait(self::USER_ID, 21, null, null));
            self::failCreation(HilosDbContext::secondFactorResets);
            $failed = $this->deliverFactor('reset', $ask);
            self::assertNotNull($failed->error);
            self::assertNull($failed->resetId);
            self::assertNull($failed->effectiveAt);
            self::assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
            self::assertSame([], $notifier->drafts);
            $beforeRequest = TimeHelper::nowMs();
            $done = $this->deliverFactor('reset', $ask);
            $afterRequest = TimeHelper::nowMs();
            self::assertNull($done->error);
            $reset = Hilos::$db->secondFactorResets[$done->resetId];
            $waitMs = 21 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
            self::assertGreaterThanOrEqual($beforeRequest + $waitMs, $done->effectiveAt);
            self::assertLessThanOrEqual($afterRequest + $waitMs, $done->effectiveAt);
            self::assertSame($done->effectiveAt, $reset->effectiveAt);
            self::assertCount(1, $notifier->drafts);
            $token = $reset->readCancelToken();
            self::assertNotNull($token);
            self::assertStringNotContainsString($token, $done->toJson());
            self::assertStringContainsString($token, $notifier->drafts[0]->body);
            $again = $this->deliverFactor('reset', $ask);
            self::assertSame(sprintf(SecondFactorMessages::RESET_ALREADY_REQUESTED,
                gmdate('Y-m-d H:i:s', intdiv($done->effectiveAt, TimeConstants::MS_PER_SECOND))), $again->error);
            self::assertCount(1, $notifier->drafts);
            self::assertSame($reset, Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
        } finally {
            Hilos::$notify = $previousNotifier;
            putenv($previousUrl === false ? $key : $key . '=' . $previousUrl);
        }
    }

    #[DataProvider('factorKinds')]
    public function testLateFactorAnswersNeverCreateOrRereadSecretsInTheLibrary(string $kind): void
    {
        $ask = self::factorAsk($kind);
        $done = $this->deliverFactor($kind, $ask);
        self::assertNull($done->error);
        if ($kind !== 'reset') {
            self::assertNull($this->deliverFactor($kind, $ask)->error);
        }
        $beforeFactors = Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows();
        $beforeCodes = Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows();
        $beforeResets = Database::sql('SELECT * FROM `hilos_second_factor_reset` ORDER BY `id`')->rows();
        $this->reserve();
        foreach ([1, 2] as $delivery) {
            $this->library->onSignalAgent(new AgentSignalData($done), 'personal', $ask->replySignal);
            $acks = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $frame): bool =>
                $frame->data instanceof WebSocketSignalData && $frame->data->data instanceof PageActionSuccessSignalData));
            self::assertCount(1, $acks);
            $ack = $acks[0]->data->data;
            self::assertSame($ask->action, $ack->action);
            self::assertSame($ask->requestId, $ack->requestId);
            $expected = match ($kind) {
                'enrollment' => new SecondFactorProfileReplyDTO(authenticatorId: $done->enrollment->authenticatorId,
                    secret: $done->enrollment->secret, otpauthUri: $done->enrollment->otpauthUri),
                'codes' => new SecondFactorProfileReplyDTO(backupCodes: $done->codes),
                'reset' => null,
            };
            self::assertSame($expected?->toArray(), $ack->reply);
            self::assertSame($beforeFactors, Database::sql('SELECT * FROM `hilos_second_factor` ORDER BY `id`')->rows());
            self::assertSame($beforeCodes, Database::sql('SELECT * FROM `hilos_second_factor_backup_code` ORDER BY `id`')->rows());
            self::assertSame($beforeResets, Database::sql('SELECT * FROM `hilos_second_factor_reset` ORDER BY `id`')->rows());
        }
    }

    #[DataProvider('factorKinds')]
    public function testFactorCreationForAnotherPersonalAddressIsRefused(string $kind): void
    {
        $ask = self::factorAsk($kind);
        $this->expectException(AgentException::class);
        $this->asPerson(self::OTHER_USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData($ask), 'caller', self::factorSignal($kind),
        ));
    }

    public function testLostEnrollmentAskOrAnswerHasNoLibraryFallbackWriter(): void
    {
        $this->confirmStepUp(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
        $this->submit(HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START, new ProfileSecondFactorEnrollStartActionDTO(null, false));
        $asks = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $signal): bool =>
            $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START));
        self::assertCount(1, $asks);
        self::assertSame([], Hilos::$db->secondFactors->listByUser(self::USER_ID));
        $this->deliverToPerson($asks[0]);
        self::takeSignals();
        self::assertCount(1, Hilos::$db->secondFactors->listByUser(self::USER_ID));
    }

    /** @return iterable<string, array{string}> Owner operation kinds */
    public static function factorKinds(): iterable
    {
        foreach (['enrollment', 'codes', 'reset'] as $kind) {
            yield $kind => [$kind];
        }
    }

    private static function factorAsk(string $kind): SignalDataInterface
    {
        return match ($kind) {
            'enrollment' => new UserSecondFactorEnrollStartSignalData(self::USER_ID,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE, self::ACCEPT_KEY, 'factor-create',
                HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START, null),
            'codes' => new UserSecondFactorCodesIssueSignalData(self::USER_ID,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE, self::ACCEPT_KEY, 'factor-create',
                HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW, null),
            'reset' => new UserSecondFactorResetRequestSignalData(self::USER_ID,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE, self::ACCEPT_KEY, 'factor-create',
                HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, null),
        };
    }

    private static function factorSignal(string $kind): string
    {
        return match ($kind) {
            'enrollment' => HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START,
            'codes' => HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE,
            'reset' => HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST,
        };
    }

    private function deliverFactor(string $kind, SignalDataInterface $ask): SignalDataInterface
    {
        self::takeSignals();
        $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData($ask), 'caller', self::factorSignal($kind),
        ));
        $answers = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === $ask->replySignal));
        self::assertCount(1, $answers);

        return $answers[0]->data->data;
    }

    /** Fails a real source publication after an insert, once; rollback and subsequent retries remain real. */
    private static function failCreation(string $collection, int $failAt = 1): void
    {
        SourceChangeBus::subscribe(new class($collection, $failAt) implements SourceMirrorSubscriberInterface {
            private int $creates = 0;

            public function __construct(private readonly string $collection, private readonly int $failAt)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === $this->collection
                    && $change->mutationType === TableMutationType::Create && ++$this->creates === $this->failAt) {
                    throw new DatabaseException('Second-factor creation failure after insert');
                }
            }
        });
    }

    #[DataProvider('resolvedSignInMethods')]
    public function testResolvedSignInDoorsRefuseTheSavedFactBeforeTheirGrant(string $method): void
    {
        $key = EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name;
        $previous = getenv($key);
        putenv($key . '=resolved-sign-in-test-secret');
        try {
            switch ($method) {
                case 'phone':
                    self::seedIdentity(self::USER_ID, IdentityType::SMS, '+15551234567');
                    $this->seedCode(VerificationType::SMS_LOGIN, '+15551234567', self::USER_ID, '123456');
                    break;
                case 'provider':
                    self::seedIdentity(self::USER_ID, IdentityType::OAUTH, 'oauth:github:merge-subject');
                    break;
                case 'passkey':
                    self::assertNull($this->deliver($this->ask(self::passkey(null)))->error);
                    break;
            }
            $operation = $this->reserve();
            $assignment = ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID),
                static function () use ($operation): AccountOperationAssignment {
                    self::assertTrue($operation->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value));

                    return Hilos::$db->accountOperationAssignments->actions->record(
                        $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
                        new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::OTHER_USER_ID),
                        self::OTHER_USER_ID, AccountOperationAssignmentPayload::fromJson('{}'),
                    );
                });
            $this->asPerson(self::OTHER_USER_ID, static fn () => AccountOperationParticipantStep::run(
                $assignment->id, static fn (): AccountOperationResultPayload => AccountOperationResultPayload::fromJson('{}'),
            ));
            self::takeSignals();
            $identities = Database::sql('SELECT * FROM `hilos_identity`')->rows();
            $credentials = Database::sql('SELECT * FROM `hilos_passkey_credential`')->rows();
            try {
                switch ($method) {
                    case 'phone':
                        new PhoneCodeCommands($this->library)->confirmPhoneCode(self::ANONYMOUS_ACCEPT_KEY,
                            new ConfirmPhoneCodeActionDTO('+15551234567', '123456'));
                        break;
                    case 'provider':
                        new OAuthCommands($this->library)->completeLogin(new OAuthLoginReadySignalData(
                            'oauth:github', 'merge-subject', null, 'Name', self::ANONYMOUS_ACCEPT_KEY,
                            self::ANONYMOUS_SESSION_TOKEN, 'trip-hash', providerName: 'Changed provider name',
                        ));
                        break;
                    case 'passkey':
                        $config = WebAuthnConfig::fromEnv();
                        $challenge = new WebAuthnChallengeSigner($config->challengeSecret)->issue(
                            WebAuthnChallengeSigner::PURPOSE_LOGIN, self::ANONYMOUS_SESSION_TOKEN, null, $config->challengeTtlSeconds,
                        );
                        new PasskeyCommands($this->library)->loginConfirm(self::ANONYMOUS_ACCEPT_KEY,
                            new PasskeyLoginConfirmActionDTO($challenge->token, Base64Url::encode('credential'), '', '', ''));
                        break;
                }
                self::fail('A resolved post-fact sign-in reached grant or protection proof');
            } catch (ValidationException $failure) {
                self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $failure->getMessage());
            }
            self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity`')->rows());
            self::assertSame($credentials, Database::sql('SELECT * FROM `hilos_passkey_credential`')->rows());
            self::assertNull(Hilos::$db->sessions->findByToken(self::ANONYMOUS_SESSION_TOKEN)->userId);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
            self::assertSame([], self::takeSignals());
        } finally {
            putenv($previous === false ? $key : $key . '=' . $previous);
        }
    }

    /** @return iterable<string, array{string}> Identity-resolved early sign-in doors */
    public static function resolvedSignInMethods(): iterable
    {
        foreach (['phone', 'provider', 'passkey'] as $method) {
            yield $method => [$method];
        }
    }

    public function testTrustAddDoesNotBelongToTheHolderOrAnotherPersonsSet(): void
    {
        $ask = $this->trustAsk();
        foreach ([self::USER_ID, self::OTHER_USER_ID] as $owner) {
            if ($owner === self::OTHER_USER_ID) {
                $agentId = $this->personAgent($owner)->getId();
            } else {
                $agentId = self::HOLDER_ID;
            }
            try {
                ExecutionContext::run(new ExecutionFrame(agentId: $agentId), static fn () =>
                    Hilos::$db->secondFactorTrusts->actions->grantOrRenew(
                        $ask->sessionId, $ask->userId, $ask->trustedUntil, $ask->grantedAt,
                    ));
                self::fail('Trust Add escaped its personal set');
            } catch (CreateNotAllowedException) {
                self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
            }
        }
        try {
            $this->asPerson(self::OTHER_USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
                new AgentSignalData($ask), self::HOLDER_ID, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW,
            ));
            self::fail('A trust ask reached another personal address');
        } catch (AgentException) {
            self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
        }
    }

    public function testTrustGrantOrRenewCapsPolicyAndDoesNotRollBackALaterProof(): void
    {
        $ask = $this->trustAsk();
        $day = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $long = new UserBrowserTrustRenewSignalData(
            $ask->userId, $ask->sessionId, $ask->grantedAt + 365 * $day, $ask->grantedAt, $ask->replySignal,
        );
        self::assertTrue($this->deliverTrust($long)->renewed);
        $created = Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows();
        self::assertCount(1, $created);
        self::assertSame($ask->grantedAt + SecondFactorSettings::DEFAULT_TRUST_DAYS * $day, (int)$created[0]['trusted_until']);

        $fresh = new UserBrowserTrustRenewSignalData(
            $ask->userId, $ask->sessionId, $ask->grantedAt + 2 * $day,
            $ask->grantedAt + TimeConstants::MS_PER_SECOND, $ask->replySignal,
        );
        self::assertTrue($this->deliverTrust($fresh)->renewed);
        $standing = Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows();
        self::assertSame($created[0]['id'], $standing[0]['id']);
        self::assertSame($created[0]['created_at'], $standing[0]['created_at']);
        self::assertSame($fresh->grantedAt, (int)$standing[0]['granted_at']);
        self::assertSame($fresh->trustedUntil, (int)$standing[0]['trusted_until']);
        self::assertTrue($this->deliverTrust($ask)->renewed);
        self::assertSame($standing, Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
    }

    #[DataProvider('trustInvalidations')]
    public function testTrustOwnerRechecksEveryLateGrantBeforeCreating(string $reason): void
    {
        $ask = $this->trustAsk();
        $session = Hilos::$db->sessions[$ask->sessionId];
        switch ($reason) {
            case 'reserved':
                $this->reserve();
                break;
            case 'ended':
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () => $session->actions->unbindUser());
                break;
            case 'expired':
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () => $session->actions->expire());
                break;
            case 'another person':
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID),
                    static fn () => $session->actions->bindUser(self::OTHER_USER_ID));
                break;
            case 'missing session':
                ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () => $session->actions->delete());
                break;
            case 'no factor':
                $this->asPerson(self::USER_ID, static fn () => Hilos::$db->secondFactors->actions->deleteForUser(self::USER_ID));
                break;
            case 'trust off':
                Hilos::$setting = new SettingsAccessor(SecondFactorTrustTurnedOffTestCatalog::class);
                break;
            case 'password changed':
            case 'password same millisecond':
                Database::sqlRun(
                    'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `verified`, `created_at`, `updated_at`, `password_changed_at`)'
                    . ' VALUES (?, ?, ?, 1, ?, ?, ?)',
                    [self::USER_ID, IdentityType::PASSWORD, self::EMAIL, $ask->grantedAt, $ask->grantedAt,
                        $ask->grantedAt + ($reason === 'password changed' ? 1 : 0)],
                );
                break;
        }
        $done = $this->deliverTrust($ask);
        self::assertFalse($done->renewed);
        self::assertSame($reason === 'reserved' ? AccountMergeAccess::REFUSED_MESSAGE : null, $done->error);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
        // Neither a negative reply nor an error lets the holder create the missing pair.
        $this->holder->onSignalAgent(new AgentSignalData($done), 'test', HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
        self::assertSame([], self::takeSignals());
    }

    /** @return iterable<string, array{string}> Facts invalidating a delayed optional grant */
    public static function trustInvalidations(): iterable
    {
        foreach (['reserved', 'ended', 'expired', 'another person', 'missing session', 'no factor', 'trust off',
            'password changed', 'password same millisecond'] as $reason) {
            yield $reason => [$reason];
        }
    }

    public function testTrustCreationCommitsBeforeReservationAndLaterRenewalIsRefused(): void
    {
        $ask = $this->trustAsk();
        self::assertTrue($this->deliverTrust($ask)->renewed);
        $standing = Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows();
        $this->reserve();
        $done = $this->deliverTrust($ask);
        self::assertSame(AccountMergeAccess::REFUSED_MESSAGE, $done->error);
        self::assertFalse($done->renewed);
        self::assertSame($standing, Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
    }

    public function testTrustInsertFailureRollsBackAndALostAnswerDoesNotMakeAnotherWriter(): void
    {
        $ask = $this->trustAsk();
        self::failCreation(HilosDbContext::secondFactorTrusts);
        self::assertNotNull($this->deliverTrust($ask)->error);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $done = $this->deliverTrust($ask);
        self::assertTrue($done->renewed);
        $standing = Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows();
        // Drop the successful answer. A later negative answer is terminal too.
        $this->holder->onSignalAgent(
            new AgentSignalData(new UserBrowserTrustRenewDoneSignalData($ask, false, null, null, null)),
            'test', HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE,
        );
        self::assertSame($standing, Database::sql('SELECT * FROM `hilos_second_factor_trust`')->rows());
        self::assertSame([], self::takeSignals());
    }

    /** @return UserBrowserTrustRenewSignalData Real live session and confirmed factor, with an earlier proof */
    private function trustAsk(): UserBrowserTrustRenewSignalData
    {
        $grantedAt = TimeHelper::nowMs() - TimeConstants::MS_PER_SECOND;
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `created_at`, `confirmed_at`) VALUES (?, ?, ?, ?, ?)',
            [self::USER_ID, 'Phone', 'JBSWY3DPEHPK3PXP', $grantedAt, $grantedAt],
        );

        return new UserBrowserTrustRenewSignalData(
            self::USER_ID, (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id,
            $grantedAt + SecondFactorSettings::DEFAULT_TRUST_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            $grantedAt, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE,
        );
    }

    /**
     * @param UserBrowserTrustRenewSignalData $ask Grant request delivered once
     * @return UserBrowserTrustRenewDoneSignalData Actual owner answer
     */
    private function deliverTrust(UserBrowserTrustRenewSignalData $ask): UserBrowserTrustRenewDoneSignalData
    {
        self::takeSignals();
        $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData($ask), self::HOLDER_ID, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW,
        ));
        $answers = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $signal): bool =>
            $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE));
        self::assertCount(1, $answers);
        self::assertInstanceOf(UserBrowserTrustRenewDoneSignalData::class, $answers[0]->data->data);
        self::assertSame($ask->toArray(), $answers[0]->data->data->request->toArray());

        return $answers[0]->data->data;
    }

    private function material(string $type): IdentityAddData
    {
        return match ($type) {
            IdentityType::PASSWORD => new PasswordIdentityAddData(self::EMAIL, Hilos::$db->identities->hashPassword(self::PASSWORD)),
            IdentityType::SMS => new SmsIdentityAddData('+15551234567'),
            IdentityType::OAUTH => new OAuthIdentityAddData('oauth:github', 'subject', 'Provider Name'),
            IdentityType::PASSKEY => self::passkey('Known Device'),
        };
    }

    private static function passkey(?string $label, string $publicKey = 'verified public key'): PasskeyIdentityAddData
    {
        return new PasskeyIdentityAddData(Base64Url::encode('credential'), $publicKey, PasskeyAlgorithm::Es256->value,
            0, ['usb'], 'aaguid', Base64Url::encode("\x00\xffhandle"), $label);
    }

    private function ask(IdentityAddData $material): UserIdentityAddSignalData
    {
        $session = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN);

        return new UserIdentityAddSignalData(self::USER_ID, $session->id, $material, null,
            HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE, self::ACCEPT_KEY, null, HilosSignalConstants::PROFILE_SET_PASSWORD, null);
    }

    private function deliver(UserIdentityAddSignalData $ask): UserIdentityAddDoneSignalData
    {
        self::takeSignals();
        $this->asPerson($ask->userId, static function (AbstractUserAgent $agent) use ($ask): void {
            $agent->onSignalAgent(new AgentSignalData($ask), 'caller', HilosSignalConstants::HILOS_USER_IDENTITY_ADD);
        });
        $done = array_values(array_filter(self::takeSignals(), static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE));
        self::assertCount(1, $done);
        self::assertInstanceOf(UserIdentityAddDoneSignalData::class, $done[0]->data->data);

        return $done[0]->data->data;
    }

    /** @return AccountOperation Merge whose survivor is reserved */
    private function reserve(): AccountOperation
    {
        $operation = ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static fn () =>
            AccountMergeOpening::open(self::USER_ID, self::OTHER_USER_ID, new AccountMergeChoice(null, null, null, null)));
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value,
        );
        self::assertNotNull($assignment);
        $this->asPerson(self::USER_ID, static fn (AbstractUserAgent $agent) => $agent->prepareForMerge($assignment->id));
        self::takeSignals();

        return $operation;
    }

    /** @return list<SignalDTO> Queued frames of the preceding handler */
    private static function takeSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: migration stub names carry the direction only on down
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

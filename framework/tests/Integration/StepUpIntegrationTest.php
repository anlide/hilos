<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpConfirmedSignalData;
use Hilos\Auth\StepUp\DTO\StepUpOpeningReplyDTO;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpMethodResolver;
use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationDirectory;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Operation-level step-up against real auth and confirmation tables (HIL-495).
 *
 * The library checks the proof and the confirming person's agent records it and tells the tabs
 * (HIL-1407): the frames between them are carried by the case, and the agent is the sender of
 * every confirmation frame.
 */
final class StepUpIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const string CREATED_AT = '2026-09-25 10:00:00';
    public const string SESSION_TOKEN = 'aa00000000000000000000000000495';
    public const string OTHER_SESSION_TOKEN = 'bb00000000000000000000000000495';
    public const string ACCEPT_KEY = 'accept-step-up';
    public const string OTHER_ACCEPT_KEY = 'accept-step-up-other';
    private const string EMAIL = 'step-up@example.test';
    private const string PHONE = '+15551234950';
    private const string PASSWORD = 'correct horse battery staple';
    private const string CODE = '495495';
    private const string SECRET_BYTES = '12345678901234567890';
    public const string OPERATION = 'test_operation';
    public const int USER_ID = 495;

    /** Administrator behind a takeover of the acting session (HIL-1170). */
    private const int ADMINISTRATOR_ID = 7;
    private const string ADMINISTRATOR_EMAIL = 'root@example.test';
    private const string ADMINISTRATOR_PASSWORD = 'administrator horse battery staple';
    private const int TTL_SECONDS = 900;

    private ?RtContext $previousRt = null;
    private ?SettingsAccessor $previousSetting = null;

    private ?SignalRouter $previousSignalRouter = null;

    private StepUpIntegrationLibrary $library;

    /**
     * @throws DatabaseException When a stub statement or seed fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runExtraStubs(down: true);
        self::runExtraStubs(down: false);
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Administrator')",
            [self::ADMINISTRATOR_ID],
        );

        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        StepUpIntegrationHilos::mount();
        Hilos::$setting = new SettingsAccessor(StepUpIntegrationSettingsCatalog::class);
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=smtp.example.test');

        $rt = new StepUpIntegrationRtContext();
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        self::seedSession(self::OTHER_SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $this->library = new StepUpIntegrationLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        SourceChangeBus::reset();
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        Hilos::$setting = $this->previousSetting;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = $this->previousRt;
        StepUpIntegrationHilos::unmount();
        self::runExtraStubs(down: true);

        parent::tearDown();
    }

    /**
     * The resolver takes a confirmed app before a password and an email.
     *
     * @throws HilosException When a proof row cannot be written or read
     */
    public function testSecondFactorHasFirstPriority(): void
    {
        $this->addPassword();
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');

        self::assertSame(StepUpMethod::SECOND_FACTOR, new StepUpMethodResolver()->resolve(self::USER_ID)?->method);
    }

    /**
     * An account with no available proof can still export, but ordinary operations keep their refusal.
     *
     * @throws HilosException When the opening cannot be read
     */
    public function testExportPassesWithNothingToConfirm(): void
    {
        self::assertFalse($this->start(StepUpOperationKey::EXPORT_DATA)->required);
        $this->expectExceptionMessage(StepUpMessages::NOTHING_TO_CONFIRM_WITH);
        $this->start(self::OPERATION);
    }

    /**
     * A password takes priority over address codes.
     *
     * @throws HilosException When an identity cannot be written or read
     */
    public function testPasswordHasPriorityOverEmail(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);
        $this->addPassword();

        self::assertSame(StepUpMethod::PASSWORD, new StepUpMethodResolver()->resolve(self::USER_ID)?->method);
    }

    /**
     * A verified deliverable email is selected when stronger proofs are absent.
     *
     * @throws HilosException When an identity cannot be written or read
     */
    public function testVerifiedEmailIsSelectedWithItsDestination(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);

        $target = new StepUpMethodResolver()->resolve(self::USER_ID);

        self::assertSame(StepUpMethod::EMAIL_CODE, $target?->method);
        self::assertSame(self::EMAIL, $target?->destination);
    }

    /**
     * A verified phone is selected when no email proof is available.
     *
     * @throws HilosException When an identity cannot be written or read
     */
    public function testVerifiedPhoneIsSelectedWithItsDestination(): void
    {
        Hilos::$db->identities->createSmsIdentity(self::USER_ID, self::PHONE);

        $target = new StepUpMethodResolver()->resolve(self::USER_ID);

        self::assertSame(StepUpMethod::SMS_CODE, $target?->method);
        self::assertSame(self::PHONE, $target?->destination);
    }

    /** @throws HilosException When a code request or identity write fails */
    public function testEmailCodeOpeningReportsTheSendAndItsCooldown(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);

        $sent = $this->start(self::OPERATION);
        self::assertSame(StepUpMethod::EMAIL_CODE, $sent->method);
        self::assertTrue($sent->send?->sent);
        self::assertNotNull($sent->send?->expiresAt);

        $held = $this->start(self::OPERATION);
        self::assertFalse($held->send?->sent);
        self::assertSame($sent->send?->expiresAt, $held->send?->expiresAt);
    }

    /** @throws HilosException When a phone code request or identity write fails */
    public function testSmsCodeOpeningReportsTheSend(): void
    {
        Hilos::$db->identities->createSmsIdentity(self::USER_ID, self::PHONE);

        $opening = $this->start(self::OPERATION);

        self::assertSame(StepUpMethod::SMS_CODE, $opening->method);
        self::assertTrue($opening->send?->sent);
    }

    /**
     * The address a code goes to is found past the stronger proofs, which keep their order, and
     * a passkey is no address (HIL-302).
     *
     * @throws HilosException When an identity or a credential row cannot be written or read
     */
    public function testAddressIsFoundPastStrongerProofsAndKeepsTheOrder(): void
    {
        $identity = Hilos::$db->identities->createPasskeyIdentity(self::USER_ID, 'credential-302');
        Database::sqlRun(
            'INSERT INTO `hilos_passkey_credential` '
            . '(`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$identity->id, self::USER_ID, 'credential-302', 'unused-public-key', -7, 'handle-302'],
        );
        self::assertNull(new StepUpMethodResolver()->resolveAddress(self::USER_ID));

        Hilos::$db->identities->createSmsIdentity(self::USER_ID, self::PHONE);
        $phone = new StepUpMethodResolver()->resolveAddress(self::USER_ID);
        self::assertSame(StepUpMethod::SMS_CODE, $phone?->method);
        self::assertSame(self::PHONE, $phone?->destination);

        $this->addPassword();
        $email = new StepUpMethodResolver()->resolveAddress(self::USER_ID);
        self::assertSame(StepUpMethod::EMAIL_CODE, $email?->method);
        self::assertSame(self::EMAIL, $email?->destination);

        self::assertSame(StepUpMethod::PASSWORD, new StepUpMethodResolver()->resolve(self::USER_ID)?->method);
    }

    /**
     * A passkey is the final available proof, and an account with none is refused.
     *
     * @throws HilosException When a credential row cannot be written or read
     */
    public function testPasskeyIsTheLastProofAndNoProofAnswersNull(): void
    {
        self::assertNull(new StepUpMethodResolver()->resolve(self::USER_ID));

        $identity = Hilos::$db->identities->createPasskeyIdentity(self::USER_ID, 'credential-495');
        Database::sqlRun(
            'INSERT INTO `hilos_passkey_credential` '
            . '(`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$identity->id, self::USER_ID, 'credential-495', 'unused-public-key', -7, 'handle-495'],
        );

        self::assertSame(StepUpMethod::PASSKEY, new StepUpMethodResolver()->resolve(self::USER_ID)?->method);
    }

    /**
     * A correct password opens exactly one operation in one browser.
     *
     * @throws HilosException When the command or assertion fails
     */
    public function testPasswordConfirmationIsPerOperationAndBrowser(): void
    {
        $this->addPassword();
        $opening = $this->start(self::OPERATION);
        self::assertTrue($opening->required);
        self::assertSame(StepUpMethod::PASSWORD, $opening->method);

        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);

        $this->library->assertOperation(self::ACCEPT_KEY, self::OPERATION);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID,
            self::OPERATION,
        ));
        $this->expectException(ValidationException::class);
        $this->library->assertOperation(self::OTHER_ACCEPT_KEY, self::OPERATION);
    }

    /**
     * A confirmation for one operation leaves another closed.
     *
     * @throws HilosException When the command or assertion fails
     */
    public function testAnotherOperationStaysClosed(): void
    {
        $this->addPassword();
        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);

        $this->expectException(ValidationException::class);
        $this->library->assertOperation(self::ACCEPT_KEY, StepUpOperationKey::DELETE_ACCOUNT);
    }

    /**
     * An expired row does not pass the gate.
     *
     * @throws HilosException When the seed or assertion fails
     */
    public function testExpiredConfirmationIsRefused(): void
    {
        $this->addPassword();
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`) '
            . 'VALUES (?, ?, ?, ?)',
            [
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                self::USER_ID,
                self::OPERATION,
                '2020-01-01 00:00:00',
            ],
        );

        $this->expectExceptionMessage(StepUpMessages::EXPIRED);
        $this->library->assertOperation(self::ACCEPT_KEY, self::OPERATION);
    }

    /**
     * A known email code is spent and records the confirmation.
     *
     * @throws HilosException When the challenge, command, or assertion fails
     */
    public function testEmailCodeIsSpentOnConfirmation(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);
        $this->verifications()->createChallenge(
            VerificationType::STEP_UP,
            self::EMAIL,
            self::USER_ID,
            self::CODE,
            self::TTL_SECONDS,
        );

        $this->confirm(self::OPERATION, StepUpMethod::EMAIL_CODE, code: self::CODE);
        $this->library->assertOperation(self::ACCEPT_KEY, self::OPERATION);

        $this->expectException(ValidationException::class);
        $this->confirm(
            self::OPERATION,
            StepUpMethod::EMAIL_CODE,
            code: self::CODE,
            acceptKey: self::OTHER_ACCEPT_KEY,
        );
    }

    /**
     * A current authenticator code proves the operation.
     *
     * @throws HilosException When the factor, command, or assertion fails
     */
    public function testAuthenticatorCodeConfirmsTheOperation(): void
    {
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');

        $code = Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time()));
        $this->confirm(
            self::OPERATION,
            StepUpMethod::SECOND_FACTOR,
            code: $code,
        );

        $this->library->assertOperation(self::ACCEPT_KEY, self::OPERATION);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID,
            self::OPERATION,
        ));

        $this->expectException(ValidationException::class);
        $this->confirm(
            self::OPERATION,
            StepUpMethod::SECOND_FACTOR,
            code: $code,
            acceptKey: self::OTHER_ACCEPT_KEY,
        );
    }

    /**
     * A backup code burns on its first operation proof.
     *
     * @throws HilosException When the factor, command, or assertion fails
     */
    public function testBackupCodeConfirmsOnce(): void
    {
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
        Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, ['abcdefghjk']);
        $code = BackupCodeGenerator::display('abcdefghjk');

        $this->confirm(self::OPERATION, StepUpMethod::SECOND_FACTOR, code: $code, backupCode: true);
        $this->library->assertOperation(self::ACCEPT_KEY, self::OPERATION);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID,
            self::OPERATION,
        ));

        $this->expectException(ValidationException::class);
        $this->confirm(
            self::OPERATION,
            StepUpMethod::SECOND_FACTOR,
            code: $code,
            backupCode: true,
            acceptKey: self::OTHER_ACCEPT_KEY,
        );
    }

    /**
     * A framework operation whose own first step mails the account address skips duplicate proof.
     *
     * @throws HilosException When the identity or opening cannot be read
     */
    public function testFrameworkAddressCodeOperationSkipsDuplicateProof(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);

        self::assertFalse($this->start(StepUpOperationKey::CHANGE_EMAIL)->required);
    }

    /**
     * Connecting an app skips the step when a connected app would be the proof: the operation's own
     * step asks that code (HIL-1138). Without an app the strongest other proof is asked.
     *
     * @throws HilosException When a proof row or the opening cannot be written or read
     */
    public function testAddAuthenticatorAppSkipsTheStepOnlyWhereTheAppWouldBeTheProof(): void
    {
        $this->addPassword();
        $opening = $this->start(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
        self::assertTrue($opening->required);
        self::assertSame(StepUpMethod::PASSWORD, $opening->method);

        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');

        self::assertFalse($this->start(StepUpOperationKey::ADD_AUTHENTICATOR_APP)->required);
        self::assertTrue($this->start(StepUpOperationKey::ADD_SIGN_IN_METHOD)->required, 'Adding a way in has no step of its own');
    }

    /**
     * Both adding operations pass an account with nothing to confirm with: refusing would leave
     * the account unable to ever gain a proof (HIL-1138).
     *
     * @throws HilosException When the opening cannot be read
     */
    public function testAddingOperationsPassWithNothingToConfirm(): void
    {
        self::assertFalse($this->start(StepUpOperationKey::ADD_AUTHENTICATOR_APP)->required);
        self::assertFalse($this->start(StepUpOperationKey::ADD_SIGN_IN_METHOD)->required);
    }

    /**
     * Impersonation refuses adding an app before the connected-app pass is considered.
     *
     * @throws HilosException When the session update, the factor or the opening fails
     */
    public function testImpersonationRefusesAddingAnAppBeforeTheConnectedAppPass(): void
    {
        Database::sqlRun('UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?', [7, self::SESSION_TOKEN]);
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');

        $this->expectExceptionMessage(StepUpMessages::IMPERSONATED);
        $this->start(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
    }

    /**
     * An administrator-disabled operation opens without confirmation.
     *
     * @throws HilosException When the setting, identity, or opening cannot be read
     */
    public function testDisabledOperationSkipsConfirmation(): void
    {
        Hilos::$setting = new SettingsAccessor(StepUpDisabledIntegrationSettingsCatalog::class);
        $this->addPassword();

        self::assertFalse($this->start(self::OPERATION)->required);
    }

    /**
     * Impersonation refuses before any proof is considered.
     *
     * @throws HilosException When the session update or opening fails
     */
    public function testImpersonatedSessionIsRefused(): void
    {
        Database::sqlRun('UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?', [7, self::SESSION_TOKEN]);
        $this->addPassword();

        $this->expectExceptionMessage(StepUpMessages::IMPERSONATED);
        $this->start(self::OPERATION);
    }

    /**
     * Inside a takeover an operation that touches the sign-in stays closed until the administrator allowed it (HIL-1170).
     *
     * @throws HilosException When the session update or the opening fails
     */
    public function testInsideATakeoverTheSignInStaysClosedUntilAllowed(): void
    {
        $this->takeOver();
        $this->addPassword();

        $this->expectExceptionMessage(StepUpMessages::IMPERSONATED);
        $this->start(StepUpOperationKey::CHANGE_PASSWORD);
    }

    /**
     * Allowed, the step asks the ADMINISTRATOR by their own method, and the confirmation is theirs (HIL-1170).
     *
     * The person's password is no proof here: the step guards against somebody else at the
     * administrator's browser, so it is the administrator who proves themselves.
     *
     * @throws HilosException When an identity, the opening or a confirmation fails
     */
    public function testWithTheSignInAllowedTheAdministratorConfirmsWithTheirOwnPassword(): void
    {
        $this->takeOver();
        Hilos::$setting = new SettingsAccessor(StepUpAccountAccessIntegrationSettingsCatalog::class);
        $this->addPassword();
        Hilos::$db->identities->createPasswordIdentity(self::ADMINISTRATOR_ID, self::ADMINISTRATOR_EMAIL, self::ADMINISTRATOR_PASSWORD)
            ->markVerified();

        $opening = $this->start(StepUpOperationKey::CHANGE_PASSWORD);
        self::assertTrue($opening->required);
        self::assertSame(StepUpMethod::PASSWORD, $opening->method);

        try {
            $this->confirm(StepUpOperationKey::CHANGE_PASSWORD, StepUpMethod::PASSWORD, password: self::PASSWORD);
            self::fail("The person's password confirms nothing inside a takeover");
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->confirmedSignals();

        $this->confirm(StepUpOperationKey::CHANGE_PASSWORD, StepUpMethod::PASSWORD, password: self::ADMINISTRATOR_PASSWORD);

        $this->library->assertOperation(self::ACCEPT_KEY, StepUpOperationKey::CHANGE_PASSWORD);
        $hash = ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed($hash, self::ADMINISTRATOR_ID, StepUpOperationKey::CHANGE_PASSWORD));
        self::assertFalse(Hilos::$db->stepUps->isConfirmed($hash, self::USER_ID, StepUpOperationKey::CHANGE_PASSWORD));
        $signals = $this->confirmedSignals();
        self::assertCount(1, $signals);
        self::assertSame(HilosAgentType::HILOS_USER, $signals[0]->signalSource->getType(), 'The administrator\'s agent records it');
        self::assertSame((string)self::ADMINISTRATOR_ID, $signals[0]->signalSource->getIndex());
    }

    /**
     * The operation's own address step proves the person's address, not the administrator, so inside a takeover it suppresses nothing (HIL-1170).
     *
     * Outside a takeover the same opening passes ({@see self::testFrameworkAddressCodeOperationSkipsDuplicateProof()}).
     *
     * @throws HilosException When an identity or the opening fails
     */
    public function testInsideATakeoverTheOperationsOwnAddressStepSuppressesNothing(): void
    {
        $this->takeOver();
        Hilos::$setting = new SettingsAccessor(StepUpAccountAccessIntegrationSettingsCatalog::class);
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);
        Hilos::$db->identities->createMagicLinkIdentity(self::ADMINISTRATOR_ID, self::ADMINISTRATOR_EMAIL);

        $opening = $this->start(StepUpOperationKey::CHANGE_EMAIL);

        self::assertTrue($opening->required);
        self::assertSame(StepUpMethod::EMAIL_CODE, $opening->method);
        self::assertSame(self::ADMINISTRATOR_EMAIL, $opening->destination);
    }

    /**
     * Deleting the account is never done with someone else's hands, whatever the setting says (HIL-302, HIL-1170).
     *
     * @throws HilosException When the session update or the opening fails
     */
    public function testDeletingTheAccountStaysClosedInsideATakeoverWhenTheSignInIsAllowed(): void
    {
        $this->takeOver();
        Hilos::$setting = new SettingsAccessor(StepUpAccountAccessIntegrationSettingsCatalog::class);
        $this->addPassword();

        $this->expectExceptionMessage(StepUpMessages::IMPERSONATED);
        $this->start(StepUpOperationKey::DELETE_ACCOUNT);
    }

    /**
     * A recorded confirmation is told to every tab of the browser, and to no other browser, by the
     * person's agent that recorded it (HIL-1330, HIL-1407).
     *
     * @throws HilosException When the command fails
     */
    public function testAConfirmationIsToldToEveryTabOfTheBrowser(): void
    {
        $this->addPassword();

        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);

        $signals = $this->confirmedSignals();
        self::assertCount(1, $signals);
        self::assertSame(HilosAgentType::HILOS_USER, $signals[0]->signalSource->getType());
        self::assertSame((string)self::USER_ID, $signals[0]->signalSource->getIndex());
        $frame = $signals[0]->data;
        self::assertInstanceOf(WebSocketSignalData::class, $frame);
        self::assertSame(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN), $frame->targetSessionTokenHash);
        self::assertNull($frame->targetAcceptKey);
        self::assertInstanceOf(StepUpConfirmedSignalData::class, $frame->data);
        self::assertSame([self::OPERATION], $frame->data->operations);
    }

    /**
     * A second-factor code confirms the operation in the turn of the agent that checked it: the
     * frame to the tabs leaves from there, and a missed code tells nobody (HIL-1407).
     *
     * @throws HilosException When the factor or a command fails
     */
    public function testACodeIsRecordedAndToldByTheAgentThatCheckedIt(): void
    {
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
        $this->confirmedSignals();

        try {
            $this->confirm(self::OPERATION, StepUpMethod::SECOND_FACTOR, code: 'ABCD-EFGH', backupCode: true);
            self::fail('A wrong backup code confirms nothing');
        } catch (ValidationException) {
            self::assertSame([], $this->confirmedSignals());
        }

        $this->confirm(self::OPERATION, StepUpMethod::SECOND_FACTOR, code: Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time())));

        $signals = $this->confirmedSignals();
        self::assertCount(1, $signals);
        self::assertSame(HilosAgentType::HILOS_USER, $signals[0]->signalSource->getType());
        self::assertSame((string)self::USER_ID, $signals[0]->signalSource->getIndex());
    }

    /**
     * The frame carries every live confirmation of the browser, not only the one just recorded (HIL-1330).
     *
     * @throws HilosException When a command fails
     */
    public function testTheFrameCarriesEveryLiveOperationOfTheBrowserInAscendingOrder(): void
    {
        $this->addPassword();
        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);
        $this->confirmedFrames();

        $this->confirm(StepUpOperationKey::DELETE_ACCOUNT, StepUpMethod::PASSWORD, password: self::PASSWORD);

        $frames = $this->confirmedFrames();
        self::assertCount(1, $frames);
        self::assertInstanceOf(StepUpConfirmedSignalData::class, $frames[0]->data);
        self::assertSame([StepUpOperationKey::DELETE_ACCOUNT, self::OPERATION], $frames[0]->data->operations);
    }

    /**
     * An expired row of the same browser and a live row of another browser stay out of the frame (HIL-1330).
     *
     * The expired row is the administrator's, so the person's own sweep before the write leaves it in place
     * and only the reading of the frame can keep it out.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testTheFrameLeavesOutExpiredRowsAndOtherBrowsers(): void
    {
        $this->addPassword();
        $this->seedConfirmation(self::SESSION_TOKEN, self::ADMINISTRATOR_ID, StepUpOperationKey::EXPORT_DATA, '2020-01-01 00:00:00');
        $this->seedConfirmation(
            self::OTHER_SESSION_TOKEN,
            self::USER_ID,
            StepUpOperationKey::DELETE_ACCOUNT,
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        );

        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);

        $frames = $this->confirmedFrames();
        self::assertCount(1, $frames);
        self::assertInstanceOf(StepUpConfirmedSignalData::class, $frames[0]->data);
        self::assertSame([self::OPERATION], $frames[0]->data->operations);
    }

    /**
     * Confirming an operation the browser has already confirmed writes nothing and tells nobody (HIL-1330).
     *
     * @throws HilosException When a command fails
     */
    public function testConfirmingAnOperationAlreadyConfirmedTellsNobody(): void
    {
        $this->addPassword();
        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);
        $this->confirmedFrames();

        $this->confirm(self::OPERATION, StepUpMethod::PASSWORD, password: self::PASSWORD);

        self::assertSame([], $this->confirmedFrames());
    }

    /**
     * Puts the acting session inside a takeover by the administrator.
     *
     * @throws DatabaseException When the session update fails
     */
    private function takeOver(): void
    {
        Database::sqlRun(
            'UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::ADMINISTRATOR_ID, self::SESSION_TOKEN],
        );
    }

    /**
     * @throws HilosException When the identity cannot be created
     */
    private function addPassword(): void
    {
        Hilos::$db->identities->createPasswordIdentity(self::USER_ID, self::EMAIL, self::PASSWORD)->markVerified();
    }

    /**
     * @param string $operation Protected operation key
     * @return StepUpOpeningReplyDTO Opening reply
     * @throws HilosException When the opening fails
     */
    private function start(string $operation): StepUpOpeningReplyDTO
    {
        $reply = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_STEP_UP_START,
            new StepUpStartActionDTO($operation),
        );
        self::assertInstanceOf(StepUpOpeningReplyDTO::class, $reply);

        return $reply;
    }

    /**
     * @param string $operation Protected operation key
     * @param string $method Selected step-up method
     * @param string $code Submitted code, or empty for another method
     * @param bool $backupCode Whether the submitted second-factor code is a backup code
     * @param string $password Submitted password, or empty for another method
     * @param string $acceptKey Browser submitting the proof
     * @throws ValidationException When the proof is refused, by the library or by the confirming person's agent
     * @throws HilosException When confirmation fails
     */
    private function confirm(
        string $operation,
        string $method,
        string $code = '',
        bool $backupCode = false,
        string $password = '',
        string $acceptKey = self::ACCEPT_KEY,
    ): void {
        // A second-factor code is checked by the confirming person's agent (HIL-1406), so the
        // proof runs the way the dispatcher runs it and its frames are carried both ways.
        $this->runTracked(
            $this->library,
            $acceptKey,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO($operation, $method, $code, $backupCode, $password, null),
        );
    }

    /**
     * Writes one confirmation row straight into the table, past every command.
     *
     * @param string $sessionToken Browser the row belongs to
     * @param int $userId Person the row is recorded on
     * @param string $operation Operation key
     * @param string $until Moment the row expires (SQL datetime)
     * @throws DatabaseException When the insert fails
     */
    private function seedConfirmation(string $sessionToken, int $userId, string $operation, string $until): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`) '
            . 'VALUES (?, ?, ?, ?)',
            [ProtectedModeRuntime::hashSessionToken($sessionToken), $userId, $operation, $until],
        );
    }

    /**
     * @return list<WebSocketSignalData> Every confirmation frame queued since the last call, in order
     */
    private function confirmedFrames(): array
    {
        $frames = [];
        foreach ($this->confirmedSignals() as $signal) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $frames[] = $signal->data;
        }

        return $frames;
    }

    /**
     * @return list<SignalDTO> Every confirmation frame queued since the last call, in order, with its sender
     */
    private function confirmedSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_STEP_UP_CONFIRMED
                && $signal->data instanceof WebSocketSignalData
            ) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * @return ObjectUserVerifications Verification persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * Raises or drops the two auth tables the session integration base does not otherwise need.
     *
     * @param bool $down Drop tables when true, create them when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runExtraStubs(bool $down): void
    {
        $tables = $down
            ? ['hilos_passkey_credential', 'hilos_user_verification']
            : ['hilos_user_verification', 'hilos_passkey_credential'];
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * Framework step-up operations plus one project operation that always asks.
 */
final class StepUpIntegrationDirectory extends StepUpOperationDirectory
{
    /**
     * @return array<string, StepUpOperation> Operations in administration order
     * @throws InvalidArgumentException When an operation key is malformed
     */
    protected static function operations(): array
    {
        return [
            ...parent::operations(),
            StepUpIntegrationTest::OPERATION => new StepUpOperation(
                StepUpIntegrationTest::OPERATION,
                'Test operation',
                'run the test operation',
                false,
            ),
        ];
    }
}

/**
 * Phone code channel making the fixture installation able to deliver SMS codes.
 */
final class StepUpIntegrationCodeChannel extends CodeChannel
{
    /**
     * @return string Stable fixture channel name
     */
    public function name(): string
    {
        return 'integration-sms';
    }

    /**
     * @param string $type Verification type
     * @return bool Whether the type is delivered by SMS
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }
}

/**
 * Code-channel registry of the fixture project.
 */
final class StepUpIntegrationCodeChannelRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Registered fixture channel
     */
    protected static function channels(): array
    {
        $channel = new StepUpIntegrationCodeChannel();

        return [$channel->name() => $channel];
    }
}

/**
 * Project facade selecting the fixture operation and code-channel registries.
 */
final class StepUpIntegrationHilos extends Hilos
{
    protected const string STEP_UP_OPERATION_DIRECTORY = StepUpIntegrationDirectory::class;
    protected const string CODE_CHANNEL_REGISTRY = StepUpIntegrationCodeChannelRegistry::class;

    /**
     * Captures this fixture as the active project facade.
     */
    public static function mount(): void
    {
        static::initBrowser();
    }

    /**
     * Restores the framework facade after a case.
     */
    public static function unmount(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
    }

    /**
     * @return HilosDbContext No-op context; integration setup supplies the live one
     */
    protected static function createDb(): HilosDbContext
    {
        return new StepUpIntegrationDbContext();
    }
}

/**
 * No-op context required by the fixture facade.
 */
final class StepUpIntegrationDbContext extends HilosDbContext
{
    /**
     * Leaves collections unmounted; the integration base supplies the live context.
     */
    public function configure(): void
    {
    }
}

/**
 * Settings fragments the step-up and second-factor commands read.
 */
final class StepUpIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(StepUpSettingsCatalog::getCatalog(), SecondFactorSettingsCatalog::getCatalog());
    }
}

/**
 * The same catalog with the impersonation settings, the sign-in of a taken-over account allowed (HIL-1170).
 */
final class StepUpAccountAccessIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        $catalog = array_replace(StepUpIntegrationSettingsCatalog::getCatalog(), ImpersonationSettingsCatalog::getCatalog());
        $catalog[ImpersonationSettings::ACCOUNT_ACCESS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = true;

        return $catalog;
    }
}

/**
 * The same catalog with the project operation disabled by default.
 */
final class StepUpDisabledIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        $catalog = StepUpIntegrationSettingsCatalog::getCatalog();
        $catalog[StepUpSettings::DISABLED_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]
            = StepUpIntegrationTest::OPERATION;

        return $catalog;
    }
}

/**
 * Runtime holding the two browser sessions used by the fixture.
 */
final class StepUpIntegrationRtContext extends RtContext
{
    /**
     * Mounts two signed-in browser sessions.
     */
    public function configure(): void
    {
        $connections = StepUpIntegrationConnections::init();
        $connections->add(StepUpIntegrationConnection::create(
            StepUpIntegrationTest::ACCEPT_KEY,
            StepUpIntegrationTest::USER_ID,
            StepUpIntegrationTest::SESSION_TOKEN,
        ));
        $connections->add(StepUpIntegrationConnection::create(
            StepUpIntegrationTest::OTHER_ACCEPT_KEY,
            StepUpIntegrationTest::USER_ID,
            StepUpIntegrationTest::OTHER_SESSION_TOKEN,
        ));
        $this->_stateCollections[StepUpIntegrationConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Users library of the fixture project.
 */
final class StepUpIntegrationLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $acceptKey Browser asking to enter the operation
     * @param string $operation Protected operation key
     * @throws HilosException When the gate refuses or cannot read its state
     */
    public function assertOperation(string $acceptKey, string $operation): void
    {
        $this->requireStepUp($acceptKey, $operation);
    }

    /**
     * @param string $displayName Unused display name
     * @param ?string $themePick Guest theme choice, or null when not chosen
     * @return int Never returns
     * @throws LogicException Always: these cases create nobody
     */
    public function createUser(string $displayName, ?string $themePick): int
    {
        throw new LogicException('the step-up cases create nobody');
    }

    /**
     * @param int $userId Account to name
     * @return ?string Always null
     */
    public function displayNameOf(int $userId): ?string
    {
        return null;
    }
}

/**
 * Session-stage connection collection of the fixture.
 */
final class StepUpIntegrationConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'stepUpIntegrationConnections';
    public const string STATE_CLASS = StepUpIntegrationConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class StepUpIntegrationConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> No project fields
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

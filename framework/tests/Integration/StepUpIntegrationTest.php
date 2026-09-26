<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
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
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
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
 */
final class StepUpIntegrationTest extends HilosSessionIntegrationTestCase
{
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
    private const int TTL_SECONDS = 900;

    private ?RtContext $previousRt = null;
    private ?SettingsAccessor $previousSetting = null;

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

        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
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
        SourceChangeBus::reset();
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        Hilos::$setting = $this->previousSetting;
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
        $this->library->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO($operation, $method, $code, $backupCode, $password, null),
        );
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
     * @return int Never returns
     * @throws LogicException Always: these cases create nobody
     */
    public function createUser(string $displayName): int
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

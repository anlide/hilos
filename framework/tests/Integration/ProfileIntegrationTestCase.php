<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\HilosSmsSender;

/**
 * Base of the profile's sign-in-method and email-change cases (HIL-1137).
 *
 * The flows came off the chat demo into the framework's users library, and so did their cases:
 * they run here against the framework's own tables, a library with nothing of a project in it,
 * and two tabs of one signed-in person - the second is what a signal "to every tab" is checked
 * against. A third tab is signed out, for the refusal a socket that lost its person meets.
 *
 * Codes are seeded with a known value, as the other code flows are tested; the letters are
 * caught by a mailer that records instead of queueing.
 */
abstract class ProfileIntegrationTestCase extends HilosSessionIntegrationTestCase
{
    public const string SESSION_TOKEN = 'aa0000000000000000000000000001137';
    public const string OTHER_SESSION_TOKEN = 'bb0000000000000000000000000001137';
    public const string ANONYMOUS_SESSION_TOKEN = 'cc0000000000000000000000000001137';
    public const string ACCEPT_KEY = 'accept-profile';
    public const string OTHER_ACCEPT_KEY = 'accept-profile-other';
    public const string ANONYMOUS_ACCEPT_KEY = 'accept-profile-anonymous';
    public const int USER_ID = 1137;

    /** Another account, holding what the person under test is refused. */
    protected const int OTHER_USER_ID = 1138;

    /** Attempt ceiling handed to the challenge lookups; above what any case spends. */
    protected const int MAX_ATTEMPTS = 5;

    /** Lifetime of a seeded challenge or confirmation; long enough that no case outlives it. */
    protected const int TTL_SECONDS = 3600;

    private const string CREATED_AT = '2026-09-26 10:00:00';

    protected ProfileIntegrationLibrary $library;

    protected ProfileRecordingMailer $mailer;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?HilosMailer $previousMail = null;

    private ?HilosSmsSender $previousSms = null;

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
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousMail = Hilos::$mail;
        $this->previousSms = Hilos::$sms;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(ProfileIntegrationSettingsCatalog::class);
        $this->mailer = new ProfileRecordingMailer();
        Hilos::$mail = $this->mailer;
        Hilos::$sms = null;
        // The confirmation gate counts a mailed code as a proof only where mail can go out.
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=smtp.example.test');

        $rt = new ProfileIntegrationRtContext();
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        self::seedSession(self::OTHER_SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        self::seedSession(self::ANONYMOUS_SESSION_TOKEN, null, self::CREATED_AT, null);
        $this->library = new ProfileIntegrationLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        Hilos::$sms = $this->previousSms;
        Hilos::$mail = $this->previousMail;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        self::runExtraStubs(down: true);

        parent::tearDown();
    }

    /**
     * Submits one profile action on behalf of a tab.
     *
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @param string $acceptKey Tab that submits
     * @throws HilosException When the command refuses or fails
     */
    protected function submit(string $action, ActionPayloadDTO $dto, string $acceptKey = self::ACCEPT_KEY): void
    {
        self::assertNull($this->library->onAgentAction($acceptKey, $action, $dto), 'A profile submit answers with no reply');
    }

    /**
     * Submits an action and asserts it is refused with exactly the given sentence.
     *
     * @param string $message Expected refusal
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @param string $acceptKey Tab that submits
     * @throws HilosException When the command fails for another reason
     */
    protected function assertRefused(
        string $message,
        string $action,
        ActionPayloadDTO $dto,
        string $acceptKey = self::ACCEPT_KEY,
    ): void {
        try {
            $this->submit($action, $dto, $acceptKey);
        } catch (ValidationException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail("{$action} must be refused with: {$message}");
    }

    /**
     * Seeds an active challenge with a known code.
     *
     * @param string $type Verification type
     * @param string $identifier Target address or number
     * @param int $userId Owning user id carried on the challenge
     * @param string $code Plaintext code
     * @throws HilosException When the challenge insert fails
     */
    protected function seedCode(string $type, string $identifier, int $userId, string $code): void
    {
        $this->verifications()->createChallenge($type, $identifier, $userId, $code, self::TTL_SECONDS);
    }

    /**
     * @return ObjectUserVerifications Verification persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    protected function verifications(): ObjectUserVerifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        self::assertInstanceOf(ObjectUserVerifications::class, $collection);

        return $collection;
    }

    /**
     * Reads an account's identity rows straight from the table.
     *
     * @param int $userId Owning user id
     * @return list<array{0: string, 1: string, 2: bool}> Type, identifier and proven flag of each row, by id
     * @throws DatabaseException When the query fails
     */
    protected static function rowsOf(int $userId): array
    {
        Database::sql(
            'SELECT `' . EntityIdentity::type . '`, `' . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`'
            . ' FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` = ? ORDER BY `' . EntityIdentity::id . '`',
            [$userId],
        );

        return array_map(
            static fn (array $row): array => [
                (string)$row[EntityIdentity::type],
                (string)$row[EntityIdentity::identifier],
                (bool)$row[EntityIdentity::verified],
            ],
            Database::rows(),
        );
    }

    /**
     * Drains the queue and returns every password-updated frame in it, as tab and mode.
     *
     * @return list<array{0: string, 1: string}> Target accept key and mode of each frame, in order
     */
    protected function passwordUpdates(): array
    {
        $updates = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::PROFILE_PASSWORD_UPDATED) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(ProfilePasswordUpdatedSignalData::class, $signal->data->data);
            $updates[] = [(string)$signal->data->targetAcceptKey, $signal->data->data->mode];
        }

        return $updates;
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
 * Settings fragments the confirmation gate and its proof resolver read.
 */
final class ProfileIntegrationSettingsCatalog implements CatalogProviderInterface
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
 * Runtime holding two signed-in tabs of one person and one signed-out tab.
 */
final class ProfileIntegrationRtContext extends RtContext
{
    /**
     * Mounts the three tabs.
     */
    public function configure(): void
    {
        $connections = ProfileIntegrationConnections::init();
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::ACCEPT_KEY,
            ProfileIntegrationTestCase::USER_ID,
            ProfileIntegrationTestCase::SESSION_TOKEN,
        ));
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::OTHER_ACCEPT_KEY,
            ProfileIntegrationTestCase::USER_ID,
            ProfileIntegrationTestCase::OTHER_SESSION_TOKEN,
        ));
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
            null,
            ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN,
        ));
        $this->_stateCollections[ProfileIntegrationConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Users library of the fixture project: every profile command is the framework's own.
 */
final class ProfileIntegrationLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Unused display name
     * @return int Never returns
     * @throws LogicException Always: these cases create nobody
     */
    public function createUser(string $displayName): int
    {
        throw new LogicException('the profile cases create nobody');
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
final class ProfileIntegrationConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'profileIntegrationConnections';
    public const string STATE_CLASS = ProfileIntegrationConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class ProfileIntegrationConnection extends HilosSessionConnection
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

/**
 * A mailer that records what would have been queued instead of queueing it.
 *
 * Subclassed rather than faked behind an interface: the change-email notice rides the same
 * raw-send intake the code letters do.
 */
final class ProfileRecordingMailer extends HilosMailer
{
    /** @var list<array{to: string, templateKey: ?string, params: array<string, mixed>}> Captured sends */
    public array $sent = [];

    /**
     * @param EmailMessage|MailSendSignalData $message Message the caller handed over
     */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        if (!$message instanceof MailSendSignalData) {
            return;
        }

        $this->sent[] = [
            'to' => $message->to,
            'templateKey' => $message->templateKey,
            'params' => $message->params,
        ];
    }

    /**
     * @param string $templateKey Template key to keep
     * @return list<array{0: string, 1: ?string}> Recipient and template key of each matching send
     */
    public function sentTo(string $templateKey): array
    {
        return array_values(array_map(
            static fn (array $sent): array => [$sent['to'], $sent['templateKey']],
            array_filter($this->sent, static fn (array $sent): bool => $sent['templateKey'] === $templateKey),
        ));
    }
}

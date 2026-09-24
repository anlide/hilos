<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\SecondFactorResetCancelLinkActionDTO;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollConfirmActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorRemoveActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetRequestActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetWaitSetActionDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorProfileReplyDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Auth\SecondFactor\SecondFactorNotificationType;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;

/**
 * The profile half of the second factor and its delayed removal, against the real tables (HIL-494).
 *
 * The users library is driven the way a node drives it - a command in - and what it queues is
 * read back: the section fanned to the person's group, the announcements handed to the notifier,
 * the frame telling the holder a factor is gone. What is pinned is what only a database answers:
 * that an app is connected by its first code and the first one issues backup codes, that the last
 * app takes the factor with it, that a removal waits the person's own wait and is canceled by its
 * link once, that the sweep carries a due removal out and reminds of a waiting one, and that a
 * shorter wait waits for the one in force.
 */
final class SecondFactorResetIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string CREATED_AT = '2026-09-24 09:00:00';

    private const string SESSION_TOKEN = 'cc00000000000000000000000000cc94';

    private const string ACCEPT_KEY = 'accept-profile';

    private const int USER_ID = 88;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?HilosNotifier $previousNotify = null;

    private SecondFactorResetTestLibrary $library;

    /** @var list<SignalDTO> Signals read off the router and not yet dropped */
    private array $seen = [];

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->previousNotify = Hilos::$notify;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        Hilos::$notify = new HilosNotifier();
        $rt = new SecondFactorResetTestRtContext();
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $this->library = new SecondFactorResetTestLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$notify = $this->previousNotify;
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * The first app is connected by its first code and issues backup codes; the section follows.
     *
     * @throws HilosException When a command fails
     */
    public function testTheFirstAppIsConnectedAndIssuesBackupCodes(): void
    {
        $codes = $this->connectFirstApp();

        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, $codes);
        $state = $this->lastState();
        $this->assertCount(1, $state?->authenticators ?? []);
        $this->assertSame(SecondFactorSettings::DEFAULT_BACKUP_CODES, $state?->backupCodesLeft);
    }

    /**
     * A second app starts only with a code from the first.
     *
     * @throws HilosException When a command fails
     */
    public function testASecondAppNeedsAProof(): void
    {
        $this->connectFirstApp();

        $this->expectException(ValidationException::class);
        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
    }

    /**
     * The last app takes the whole factor with it, and the holder is told.
     *
     * @throws HilosException When a command fails
     */
    public function testTheLastAppTakesTheFactorWithIt(): void
    {
        $secret = $this->connectFirstAppSecret();
        $this->drain();
        $factor = Hilos::$db->secondFactors->confirmedOf(self::USER_ID)[0];

        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
            new ProfileSecondFactorRemoveActionDTO((int)$factor->id, $this->nextCodeOf($secret), false),
        );

        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
        $this->assertContains(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF, $this->queuedNames());
    }

    /**
     * A removal waits the default, announces itself with the link, and the link cancels it once.
     *
     * @throws HilosException When a command fails
     */
    public function testARemovalIsCanceledByItsLinkOnce(): void
    {
        $this->connectFirstApp();
        $this->drain();

        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
            new ProfileSecondFactorResetRequestActionDTO(),
        );

        $reset = Hilos::$db->secondFactorResets->liveOf(self::USER_ID);
        $this->assertNotNull($reset);
        $waited = (int)strtotime($reset->effectiveAt) - (int)strtotime($reset->requestedAt);
        $this->assertEqualsWithDelta(SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS * 86400, $waited, 2);
        $announcement = $this->announcement(SecondFactorNotificationType::RESET_REQUESTED);
        $url = $announcement?->data['url'] ?? null;
        $this->assertIsString($url, 'The announcement carries the cancel link');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $token = $query[SecondFactorResetNotifier::TOKEN_PARAM] ?? null;
        $this->assertIsString($token);

        $this->library->onAgentAction(
            'accept-anyone',
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK,
            new SecondFactorResetCancelLinkActionDTO($token),
        );
        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));

        $this->expectException(ValidationException::class);
        $this->library->onAgentAction(
            'accept-anyone',
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK,
            new SecondFactorResetCancelLinkActionDTO($token),
        );
    }

    /**
     * The sweep carries a due removal out and reminds of one still waiting.
     *
     * @throws HilosException When a command or the sweep fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testTheSweepCarriesOutAndReminds(): void
    {
        $this->connectFirstApp();
        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
            new ProfileSecondFactorResetRequestActionDTO(),
        );
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `notified_at` = ? WHERE `user_id` = ?',
            [date('Y-m-d H:i:s', time() - 2 * 86400), self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->drain();

        $this->library->onStart();
        $this->library->onTick();
        $this->assertNotNull($this->announcement(SecondFactorNotificationType::RESET_REMINDER));
        $this->assertNotNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID), 'A reminder carries nothing out');

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ? WHERE `user_id` = ?',
            [date('Y-m-d H:i:s', time() - 60), self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->library->onStart();
        $this->library->onTick();

        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertNotNull($this->announcement(SecondFactorNotificationType::RESET_COMPLETED));
    }

    /**
     * A longer wait applies at once; a shorter one waits for the wait in force.
     *
     * @throws HilosException When a command fails
     */
    public function testAShorterWaitWaitsForTheOneInForce(): void
    {
        $this->setWait(20);
        $this->assertSame(20, $this->lastState()?->resetWait[SecondFactorStateSignalData::days] ?? null);

        $this->setWait(3);
        $state = $this->lastState();
        $this->assertSame(20, $state?->resetWait[SecondFactorStateSignalData::days] ?? null);
        $this->assertSame(3, $state?->resetWait[SecondFactorStateSignalData::pendingDays] ?? null);

        $this->expectException(ValidationException::class);
        $this->setWait(SecondFactorSettings::DEFAULT_RESET_WAIT_MAX_DAYS + 1);
    }

    /**
     * Connects the first app and answers its backup codes.
     *
     * @return list<string> Backup codes the enrolment issued
     * @throws HilosException When a command fails
     */
    private function connectFirstApp(): array
    {
        $this->connectFirstAppSecret($codes);

        return $codes;
    }

    /**
     * Connects the first app and answers its secret.
     *
     * @param ?list<string> $codes Receives the backup codes the enrolment issued
     * @return string Base32 secret of the app
     * @throws HilosException When a command fails
     */
    private function connectFirstAppSecret(?array &$codes = null): string
    {
        $start = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
        $this->assertInstanceOf(SecondFactorProfileReplyDTO::class, $start);
        $secret = (string)$start->secret;
        $confirm = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
            new ProfileSecondFactorEnrollConfirmActionDTO(
                (int)$start->authenticatorId,
                Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time())),
                'Phone',
            ),
        );
        $this->assertInstanceOf(SecondFactorProfileReplyDTO::class, $confirm);
        $codes = $confirm->backupCodes ?? [];

        return $secret;
    }

    /**
     * @param string $secret Base32 secret
     * @return string The code of the step after the current one, which the replay guard has not seen
     */
    private function nextCodeOf(string $secret): string
    {
        return Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time()) + 1);
    }

    /**
     * @param int $days Wait to choose
     * @throws HilosException When the command fails
     */
    private function setWait(int $days): void
    {
        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET,
            new ProfileSecondFactorResetWaitSetActionDTO($days),
        );
    }

    /**
     * @return ?SecondFactorStateSignalData The last section fanned since the last drain
     */
    private function lastState(): ?SecondFactorStateSignalData
    {
        $state = null;
        foreach ($this->queued() as $signal) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof SecondFactorStateSignalData) {
                $state = $signal->data->data;
            }
        }

        return $state;
    }

    /**
     * @param string $type Notification type
     * @return ?NotificationEmitSignalData The last announcement of that type since the last drain
     */
    private function announcement(string $type): ?NotificationEmitSignalData
    {
        $found = null;
        foreach ($this->queued() as $signal) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof NotificationEmitSignalData
                && $payload->data->type === $type) {
                $found = $payload->data;
            }
        }

        return $found;
    }

    /**
     * @return list<string> Names of the signals queued since the last drain
     */
    private function queuedNames(): array
    {
        return array_map(static fn ($signal): string => $signal->signalName->getName(), $this->queued());
    }

    /**
     * @return list<SignalDTO> Every signal queued since the last drain
     */
    private function queued(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->seen[] = $signal;
        }

        return $this->seen;
    }

    /**
     * Drops every signal queued so far.
     */
    private function drain(): void
    {
        $this->queued();
        $this->seen = [];
    }
}

/**
 * Runtime with the one signed-in tab of the person under test.
 */
final class SecondFactorResetTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SecondFactorResetTestConnections::init();
        $connections->add(SecondFactorResetTestConnection::create('accept-profile', 88, 'cc00000000000000000000000000cc94'));
        $this->_stateCollections[SecondFactorResetTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Users library of the fixture project, which creates and names nobody.
 */
final class SecondFactorResetTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Name the new account would be created with
     * @return int Never returns
     * @throws LogicException Always: these cases register nobody
     */
    public function createUser(string $displayName): int
    {
        throw new LogicException('the second-factor cases register nobody');
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
 * Session-stage connection collection of the fixture project.
 */
final class SecondFactorResetTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'secondFactorResetTestConnections';

    public const string STATE_CLASS = SecondFactorResetTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SecondFactorResetTestConnection extends HilosSessionConnection
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
     * @return array<string, mixed> Own fields, of which this fixture has none
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

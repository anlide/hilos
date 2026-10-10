<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationPreference as EntityNotificationPreference;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Users\DTO\UserNotificationChannelSetSignalData;
use Hilos\Users\DTO\UserNotificationReadSignalData;
use Hilos\Users\DTO\UserPushRemoveSignalData;
use Hilos\Users\DTO\UserPushUnsubscribeSignalData;

/**
 * A person's notifications, channel choices and push devices are written by that person's agent (HIL-1408).
 *
 * The notifications library creates a letter and reads the channel settings. It no longer marks a
 * letter read, mutes a channel or removes a device: the truth source refuses all three. The same
 * writes handed to the person's agent go through, and the agent tells the person's tabs. A frame
 * naming another person is refused rather than written, and an account that is gone or folded
 * into another one is written nothing.
 *
 * Every writer runs in its own frame and under the claims its own class declares, and the frames
 * between them are carried by the case.
 */
final class PersonNotificationEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-notification-edits';

    private const string CHANNEL = 'email';

    private const string ENDPOINT = 'https://push.example/notification-edits';

    private const string OTHER_ENDPOINT = 'https://push.example/notification-edits-other';

    /** @var list<string> Tables this case adds beside the session base, dropped before that base drops users */
    private const array TABLES = [
        'hilos_push_subscription',
        'hilos_notification_preference',
        'hilos_notification',
    ];

    private NotificationEditsTestLibrary $library;

    private ?SignalRouter $previousRouter = null;

    /** @var array<string, SignalDTO> Signals the last {@see carry()} took off the queue beside the answer, by name */
    private array $carried = [];

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->runNotificationStubs(down: true);
        $this->runNotificationStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->library = new NotificationEditsTestLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        Hilos::$sr = $this->previousRouter;

        $this->runNotificationStubs(down: true);
        Schema::reset();
        Schema::initialize();

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheLibraryEditingAPersonsRowsItselfIsRefused(): void
    {
        $userId = self::seedPerson();
        $notificationId = $this->letter($userId);
        $this->subscribe($userId, self::ENDPOINT);

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->library->getId()),
                static fn () => Hilos::$db->notifications[$notificationId]?->actions->markRead(),
            );
            self::fail('The library must not mark a letter read');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->library->getId(), $refusal->getMessage());
        }
        self::assertNull($this->readAt($notificationId));

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->library->getId()),
                static fn () => Hilos::$db->notificationPreferences->actions->setChannel($userId, self::CHANNEL, false),
            );
            self::fail('The library must not mute a channel');
        } catch (WriteNotAllowedException | CreateNotAllowedException $refusal) {
            self::assertStringContainsString($this->library->getId(), $refusal->getMessage());
        }
        self::assertSame(0, $this->preferenceRows($userId));

        $subscriptionId = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($subscriptionId);
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->library->getId()),
                static fn () => Hilos::$db->pushSubscriptions->actions->removeOwned($userId, $subscriptionId),
            );
            self::fail('The library must not remove a device');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->library->getId(), $refusal->getMessage());
        }
        self::assertNotNull($this->subscriptionIdOf($userId, self::ENDPOINT));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentMarksOneAndAllAndTellsTheTabs(): void
    {
        $userId = self::seedPerson();
        $one = $this->letter($userId);
        $this->letter($userId);

        $marked = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_READ, new UserNotificationReadSignalData(
            $userId,
            $one,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            NotificationAction::MARK_READ,
            null,
        ));
        self::assertInstanceOf(HandoverAnswerSignalData::class, $marked);
        self::assertNull($marked->error);
        self::assertNotNull($this->readAt($one));
        self::assertSame($one, $this->readPayload()->id);

        $all = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_READ, new UserNotificationReadSignalData(
            $userId,
            null,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            NotificationAction::MARK_ALL_READ,
            null,
        ));
        self::assertNull($all->error);
        self::assertSame(0, $this->unread($userId));
        self::assertSame(NotificationReadSignalData::ALL, $this->readPayload()->id);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testTurningAChannelOffWritesTheRowAndOnRemovesItAndTheFrameCarriesTheMap(): void
    {
        $userId = self::seedPerson();

        $muted = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET, new UserNotificationChannelSetSignalData(
            $userId,
            self::CHANNEL,
            false,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            NotificationPreferenceAction::CHANNEL_SET,
            null,
        ));
        self::assertNull($muted->error);
        self::assertSame(1, $this->preferenceRows($userId));
        self::assertSame(
            (new NotificationChannelPreferenceProjector())->channelPreferenceMap($userId),
            $this->preferencePayload()->channels,
        );

        $enabled = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET, new UserNotificationChannelSetSignalData(
            $userId,
            self::CHANNEL,
            true,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            NotificationPreferenceAction::CHANNEL_SET,
            null,
        ));
        self::assertNull($enabled->error);
        self::assertSame(0, $this->preferenceRows($userId));
        self::assertSame(
            (new NotificationChannelPreferenceProjector())->channelPreferenceMap($userId),
            $this->preferencePayload()->channels,
        );
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentDropsItsOwnSubscriptionAndDevice(): void
    {
        $userId = self::seedPerson();
        $this->subscribe($userId, self::ENDPOINT);
        $this->subscribe($userId, self::OTHER_ENDPOINT);
        $deviceId = $this->subscriptionIdOf($userId, self::OTHER_ENDPOINT);
        self::assertNotNull($deviceId);

        $unsubscribed = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE, new UserPushUnsubscribeSignalData(
            $userId,
            self::ENDPOINT,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            PushSubscriptionAction::UNSUBSCRIBE,
            null,
        ));
        self::assertNull($unsubscribed->error);
        self::assertNull($this->subscriptionIdOf($userId, self::ENDPOINT));

        $removed = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_REMOVE, new UserPushRemoveSignalData(
            $userId,
            $deviceId,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            PushSubscriptionAction::REMOVE,
            'Device removed',
        ));
        self::assertNull($removed->error);
        self::assertSame('Device removed', $removed->successMessage);
        self::assertNull($this->subscriptionIdOf($userId, self::OTHER_ENDPOINT));
    }

    /**
     * @throws HilosException When a frame fails
     */
    public function testADeviceThatIsAlreadyGoneIsRefused(): void
    {
        $userId = self::seedPerson();

        $answer = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_REMOVE, new UserPushRemoveSignalData(
            $userId,
            1,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            PushSubscriptionAction::REMOVE,
            'Device removed',
        ));

        self::assertSame('This device is already gone', $answer->error);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testAGoneOrFoldedPersonIsRefusedAndNoPreferenceRowIsWritten(): void
    {
        $foldedId = self::seedPerson();
        $survivorId = self::seedPerson();
        Hilos::$db->userMerges->actions->add($foldedId, $survivorId);

        $folded = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET, $this->mute($foldedId));
        self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $folded->error);
        self::assertSame(0, $this->preferenceRows($foldedId));

        $goneId = self::seedPerson();
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$goneId]);

        $gone = $this->carry(HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET, $this->mute($goneId));
        self::assertSame("No such user: {$goneId}", $gone->error);
        self::assertSame(0, $this->preferenceRows($goneId));
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $notificationId = $this->letter($otherId);

        $this->expectException(AgentException::class);
        try {
            $this->asPerson($userId, fn ($agent) => $agent->onSignalAgent(
                new AgentSignalData(data: new UserNotificationReadSignalData(
                    $otherId,
                    $notificationId,
                    HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
                    self::ACCEPT_KEY,
                    null,
                    NotificationAction::MARK_READ,
                    null,
                )),
                '',
                HilosSignalConstants::HILOS_USER_NOTIFICATION_READ,
            ));
        } finally {
            self::assertNull($this->readAt($notificationId));
        }
    }

    /**
     * Sends one frame the way the library does, hands it to the person's agent and takes its answer.
     *
     * Every other signal the agent queued is kept in {@see $carried}, by name.
     *
     * @param string $name Agent-signal name the frame travels under
     * @param HandoverAskInterface $frame The frame
     * @return HandoverAnswerSignalData Payload of the answer, under the reply name the frame carries
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(string $name, HandoverAskInterface $frame): HandoverAnswerSignalData
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->library->sendToAgent($name, $frame);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $this->carried = [];
        $answer = null;
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer === null && $queued->signalName->getName() === $frame->replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answer = $queued->data->data;
            } else {
                $this->carried[$queued->signalName->getName()] ??= $queued;
            }
        }
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer, "Nothing was sent under {$frame->replySignal}");

        return $answer;
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Person the letter is for
     * @return int Notification id
     * @throws HilosException When the insert fails
     */
    private function letter(int $userId): int
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId()),
            static function () use ($userId): int {
                $notifications = Hilos::$db->getObjectCollection(HilosDbContext::notifications);
                self::assertInstanceOf(ObjectNotifications::class, $notifications);

                return (int)$notifications->createFor(
                    $userId,
                    'test.notice',
                    NotificationSeverity::INFO,
                    'Title',
                    null,
                    null,
                )->id;
            },
        );
    }

    /**
     * @param int $userId Person the device belongs to
     * @param string $endpoint Browser push endpoint
     * @throws HilosException When the insert fails
     */
    private function subscribe(int $userId, string $endpoint): void
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId()),
            static fn () => Hilos::$db->pushSubscriptions->actions->subscribe($userId, $endpoint, 'pk', 'auth', null),
        );
    }

    /**
     * @param int $userId Person whose channel would be muted
     * @return UserNotificationChannelSetSignalData Mute ask
     */
    private function mute(int $userId): UserNotificationChannelSetSignalData
    {
        return new UserNotificationChannelSetSignalData(
            $userId,
            self::CHANNEL,
            false,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            NotificationPreferenceAction::CHANNEL_SET,
            null,
        );
    }

    /** @return NotificationReadSignalData The mark-read frame the last {@see carry()} kept */
    private function readPayload(): NotificationReadSignalData
    {
        $told = $this->carried[NotificationSignalName::READ] ?? null;
        self::assertInstanceOf(SignalDTO::class, $told);
        self::assertSame(HilosAgentType::HILOS_USER, $told->signalSource->getType());
        $payload = $told->data;
        self::assertInstanceOf(WebSocketSignalData::class, $payload);
        self::assertInstanceOf(NotificationReadSignalData::class, $payload->data);

        return $payload->data;
    }

    /** @return NotificationPreferencesChangedSignalData The channel map the last {@see carry()} kept */
    private function preferencePayload(): NotificationPreferencesChangedSignalData
    {
        $told = $this->carried[NotificationSignalName::PREFERENCES_CHANGED] ?? null;
        self::assertInstanceOf(SignalDTO::class, $told);
        self::assertSame(HilosAgentType::HILOS_USER, $told->signalSource->getType());
        $payload = $told->data;
        self::assertInstanceOf(WebSocketSignalData::class, $payload);
        self::assertInstanceOf(NotificationPreferencesChangedSignalData::class, $payload->data);

        return $payload->data;
    }

    /**
     * @param int $notificationId Notification to read
     * @return ?string Stored read time, or null when the row is still unread
     * @throws DatabaseException When the lookup fails
     */
    private function readAt(int $notificationId): ?string
    {
        $row = Database::sql(
            'SELECT `read_at` FROM `' . EntityNotification::_table . '` WHERE `id` = ?',
            [$notificationId],
        )->firstRow();
        self::assertIsArray($row);
        $readAt = $row['read_at'];

        return $readAt === null ? null : (string)$readAt;
    }

    /**
     * @param int $userId Person whose unread letters are counted
     * @return int Unread rows
     * @throws DatabaseException When the lookup fails
     */
    private function unread(int $userId): int
    {
        $row = Database::sql(
            'SELECT COUNT(*) AS `n` FROM `' . EntityNotification::_table . '`'
            . ' WHERE `user_id` = ? AND `read_at` IS NULL',
            [$userId],
        )->firstRow();
        self::assertIsArray($row);

        return (int)$row['n'];
    }

    /**
     * @param int $userId Person whose muted rows are counted
     * @return int Preference rows
     * @throws DatabaseException When the lookup fails
     */
    private function preferenceRows(int $userId): int
    {
        $row = Database::sql(
            'SELECT COUNT(*) AS `n` FROM `' . EntityNotificationPreference::_table . '` WHERE `user_id` = ?',
            [$userId],
        )->firstRow();
        self::assertIsArray($row);

        return (int)$row['n'];
    }

    /**
     * @param int $userId Person the device belongs to
     * @param string $endpoint Browser push endpoint
     * @return ?int Subscription id, or null when the row is gone
     * @throws DatabaseException When the lookup fails
     */
    private function subscriptionIdOf(int $userId, string $endpoint): ?int
    {
        $row = Database::sql(
            'SELECT `id` FROM `' . EntityPushSubscription::_table . '` WHERE `user_id` = ? AND `endpoint` = ?',
            [$userId, $endpoint],
        )->firstRow();

        return $row === null ? null : (int)$row['id'];
    }

    /**
     * @param bool $down Whether to run the drop stubs
     * @throws DatabaseException When a schema statement fails
     */
    private function runNotificationStubs(bool $down): void
    {
        // external-boundary: the create stub carries no filename suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? self::TABLES : array_reverse(self::TABLES) as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** The framework's notifications library under a test name, with nothing overridden. */
final class NotificationEditsTestLibrary extends AbstractNotificationsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_notification_edits_library';
}

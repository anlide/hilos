<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\API\AsyncHttpClient;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Push\DTO\PushSubscribeActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
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
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationReadSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Notification\NotificationSeverity;
use Hilos\Notification\NotificationSignalName;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Push\Delivery\PushEndpointSend;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Users\DTO\UserNotificationChannelSetSignalData;
use Hilos\Users\DTO\UserNotificationReadSignalData;
use Hilos\Users\DTO\UserPushEndpointGoneSignalData;
use Hilos\Users\DTO\UserPushRemoveSignalData;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
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

    private NotificationEditsTestPushChannel $pushChannel;

    private ?SignalRouter $previousRouter = null;

    private ?RtContext $previousRt = null;

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

        $this->previousRt = Hilos::$rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->library = new NotificationEditsTestLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('tick')->willThrowException(new AsyncHttpStatusException(HttpConstants::HTTP_GONE, ''));
        $this->pushChannel = new NotificationEditsTestPushChannel('1', $client);
        OwnershipDeclaration::claimAll($this->pushChannel);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        SourceChangeBus::reset();
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        TruthSourceRegistry::unregisterAgent($this->pushChannel->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->pushChannel->getId()));
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
                static fn () => Hilos::$db->notifications[$notificationId]->actions->markRead(),
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

    public function testTransportReportsEachDeadDeviceToItsPersonalOwnerWithoutWritingOrAnswering(): void
    {
        $userId = self::seedPerson();
        $notificationId = $this->letter($userId);
        $this->subscribe($userId, self::ENDPOINT);
        $this->subscribe($userId, self::OTHER_ENDPOINT);
        $devices = [];
        foreach ([self::ENDPOINT, self::OTHER_ENDPOINT] as $endpoint) {
            $id = $this->subscriptionIdOf($userId, $endpoint);
            self::assertNotNull($id);
            $devices[$id] = $this->deviceRow($id);
        }

        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $attempt = ExecutionContext::run(
            new ExecutionFrame(agentId: $this->pushChannel->getId()),
            fn () => $this->pushChannel->createAttempt('1', Hilos::$db->notifications->getObjectCollection()[$notificationId]),
        );
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $attempt->tick(1.0);
        self::assertFalse($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());

        foreach ($devices as $id => $before) {
            self::assertSame($before, $this->deviceRow($id));
            $signal = Hilos::$sr->getNextQueuedSignal();
            self::assertNotNull($signal);
            self::assertSame(HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE, $signal->signalName->getName());
            self::assertSame(HilosAgentType::HILOS_PUSH, $signal->signalSource->getType());
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(UserPushEndpointGoneSignalData::class, $signal->data->data);
            self::assertSame([
                'userId' => $userId,
                'subscriptionId' => $id,
                'revision' => (int)$before['revision'],
            ], $signal->data->data->toArray());
            $this->deliverToPerson($signal);
            $gone = $this->deviceRow($id);
            self::assertNotNull($gone['gone_at']);
            $before['gone_at'] = $gone['gone_at'];
            self::assertSame($before, $gone);
        }
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $queued->data, 'A transport report is not answered');
        }
        self::assertCount(0, Hilos::$db->pushSubscriptions->getObjectCollection()->forUser($userId));
        self::assertFalse($this->personAgent($userId)->shouldStop());
        $attempt->close();
    }

    public function testALateTransportReportKeepsTheStampFromOpeningBeforeTheOwnerRenewsTheDevice(): void
    {
        $userId = self::seedPerson();
        $notificationId = $this->letter($userId);
        $this->subscribe($userId, self::ENDPOINT);
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        // A fixed old stamp makes renewal distinct without waiting for the clock.
        ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), static function () use ($id): void {
            Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->lastSeenAt = 1767225600123;
            Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->sync();
        });
        $attempt = ExecutionContext::run(
            new ExecutionFrame(agentId: $this->pushChannel->getId()),
            fn () => $this->pushChannel->createAttempt('1', Hilos::$db->notifications->getObjectCollection()[$notificationId]),
        );

        $answer = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE, new UserPushSubscribeSignalData(
            $userId, $id, self::ENDPOINT, 'rotated-public-key', 'rotated-auth-secret', null,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE, self::ACCEPT_KEY, null,
            PushSubscriptionAction::SUBSCRIBE, null,
        ));
        self::assertNull($answer->error);
        $renewed = $this->deviceRow($id);
        self::assertGreaterThan(1767225600123, (int)$renewed['last_seen_at']);
        self::assertSame((int)$renewed['last_seen_at'], Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->lastSeenAt);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $attempt->tick(1.0);

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE, $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(UserPushEndpointGoneSignalData::class, $signal->data->data);
        self::assertSame([
            'userId' => $userId,
            'subscriptionId' => $id,
            'revision' => 1,
        ], $signal->data->data->toArray());
        $this->deliverToPerson($signal);

        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame($renewed, $this->deviceRow($id));
        self::assertNull($renewed['gone_at']);
        self::assertSame('rotated-public-key', $renewed['p256dh']);
        self::assertSame('rotated-auth-secret', $renewed['auth']);
        self::assertCount(1, Hilos::$db->pushSubscriptions->getObjectCollection()->forUser($userId));
        $attempt->close();
    }

    public function testARepeatedDeadEndpointReportKeepsTheFirstGoneMomentWithoutAnswering(): void
    {
        $userId = self::seedPerson();
        $this->subscribe($userId, self::ENDPOINT);
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $revision = (int)$this->deviceRow($id)['revision'];

        $this->reportGone(new UserPushEndpointGoneSignalData($userId, $id, $revision));
        self::assertNotNull($this->deviceRow($id)['gone_at']);
        self::assertFalse($this->personAgent($userId)->shouldStop());

        // Fix the first saved moment in the past so a duplicate rewrite cannot pass within the same millisecond.
        $this->asPerson($userId, static function () use ($id): void {
            Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->goneAt = 1767225600123;
            Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->sync();
        });
        $before = $this->deviceRow($id);
        $this->reportGone(new UserPushEndpointGoneSignalData($userId, $id, $revision));
        self::assertSame($before, $this->deviceRow($id));
    }

    public function testADeadEndpointReportLeavesBothPeoplesDevicesUntouchedWhenTheDeviceIsForeign(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $this->subscribe($userId, self::ENDPOINT);
        $this->subscribe($otherId, self::OTHER_ENDPOINT);
        $ownId = $this->subscriptionIdOf($userId, self::ENDPOINT);
        $foreignId = $this->subscriptionIdOf($otherId, self::OTHER_ENDPOINT);
        self::assertNotNull($ownId);
        self::assertNotNull($foreignId);
        $own = $this->deviceRow($ownId);
        $foreign = $this->deviceRow($foreignId);

        $this->reportGone(new UserPushEndpointGoneSignalData($userId, $foreignId, (int)$foreign['revision']));

        self::assertSame($own, $this->deviceRow($ownId));
        self::assertSame($foreign, $this->deviceRow($foreignId));
    }

    public function testAFoldedPersonsAgentAppliesTheTransportReportAndStopsButStillRefusesAnOrdinaryRemoval(): void
    {
        $foldedId = self::seedPerson();
        $survivorId = self::seedPerson();
        $this->subscribe($foldedId, self::ENDPOINT);
        $id = $this->subscriptionIdOf($foldedId, self::ENDPOINT);
        self::assertNotNull($id);
        $before = $this->deviceRow($id);
        Hilos::$db->userMerges->actions->add($foldedId, $survivorId);

        $this->reportGone(new UserPushEndpointGoneSignalData($foldedId, $id, (int)$before['revision']));

        $gone = $this->deviceRow($id);
        self::assertNotNull($gone['gone_at']);
        $before['gone_at'] = $gone['gone_at'];
        self::assertSame($before, $gone);
        self::assertTrue($this->personAgent($foldedId)->shouldStop());
        $removed = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_REMOVE, new UserPushRemoveSignalData(
            $foldedId,
            $id,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY,
            null,
            PushSubscriptionAction::REMOVE,
            null,
        ));
        self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $removed->error);
        self::assertSame($gone, $this->deviceRow($id));
    }

    public function testThePushChannelCannotMarkADeviceGoneItself(): void
    {
        $userId = self::seedPerson();
        $this->subscribe($userId, self::ENDPOINT);
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $before = $this->deviceRow($id);

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->pushChannel->getId()),
                static fn () => Hilos::$db->pushSubscriptions->actions->markGoneOwned($userId, $id, (int)$before['revision']),
            );
            self::fail('The push channel must report the device to its personal owner');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->pushChannel->getId(), $refusal->getMessage());
        }
        self::assertSame($before, $this->deviceRow($id));
    }

    /** @return iterable<string, array{?string}> Waiting browser requests */
    public static function renewalRequests(): iterable
    {
        yield 'tracked' => ['renew-request'];
        yield 'untracked' => [null];
    }

    /** @param ?string $requestId Waiting browser request, or null for a silent success */
    #[DataProvider('renewalRequests')]
    public function testLibraryInputRenewsThroughTheOwnerAndAnswersOnlyAfterTheWrite(?string $requestId): void
    {
        $userId = self::seedPerson();
        $this->bindBrowser($userId);
        self::assertNull($this->submitSubscription($requestId));
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $before = $this->deviceRow($id);
        self::assertSame('1', $before['revision']);
        $this->drainSignals();

        $changes = new NotificationRenewalChanges();
        SourceChangeBus::subscribe($changes);
        $signal = $this->submitSubscription($requestId, 'rotated', 'rotated-auth', 'Mozilla/5.0 (Linux) Firefox/120');
        self::assertNotNull($signal);
        self::assertSame($before, $this->deviceRow($id));
        self::assertSame([], $this->drainSignals(), 'No success before the owner writes');
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        $ask = $signal->data->data;
        self::assertInstanceOf(UserPushSubscribeSignalData::class, $ask);
        self::assertSame($id, $ask->subscriptionId);
        self::assertSame($userId, $ask->userId);
        self::assertSame($requestId, $ask->requestId);
        self::assertSame(self::ACCEPT_KEY, $ask->acceptKey);
        self::assertSame(PushSubscriptionAction::SUBSCRIBE, $ask->action);
        self::assertNull($ask->successMessage);

        $reply = $this->finishRenewal($signal);
        $saved = $this->deviceRow($id);
        foreach (['id', 'user_id', 'endpoint', 'endpoint_hash', 'created_at'] as $column) {
            self::assertSame($before[$column], $saved[$column]);
        }
        self::assertSame('rotated', $saved['p256dh']);
        self::assertSame('rotated-auth', $saved['auth']);
        self::assertSame('Mozilla/5.0 (Linux) Firefox/120', $saved['user_agent']);
        self::assertSame('Firefox on Linux', $saved['device_name']);
        self::assertNull($saved['gone_at']);
        self::assertSame('2', $saved['revision']);
        self::assertCount($requestId === null ? 0 : 1, $reply);
        if ($requestId !== null) {
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply[0]->data->data);
            self::assertSame($requestId, $reply[0]->data->data->requestId);
            self::assertSame(PushSubscriptionAction::SUBSCRIBE, $reply[0]->data->data->action);
        }
        self::assertCount(1, $changes->changes);
        self::assertSame(self::ACCEPT_KEY, $changes->changes[0]->origin);
        self::assertSame($requestId, $changes->changes[0]->originRequestId);
        self::assertSame(1, $changes->changes[0]->previous['revision']);
        self::assertSame(2, $changes->changes[0]->row['revision']);
        $sync = $this->carried[SignalConstants::DB_SYNC_UPDATED]->data;
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $sync);
        self::assertSame($changes->changes[0]->row, $sync->row);
        self::assertSame($changes->changes[0]->previous, $sync->previous);
        self::assertSame(self::ACCEPT_KEY, $sync->origin);
        self::assertSame($requestId, $sync->originRequestId);
    }

    public function testTwoLibrarySubmitsQueueTheSameIdAndBothRenewalsSucceedInOrder(): void
    {
        $userId = self::seedPerson();
        $this->bindBrowser($userId);
        self::assertNull($this->submitSubscription('create'));
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $this->drainSignals();
        $first = $this->submitSubscription('first', 'same', 'same-auth', null);
        $second = $this->submitSubscription('second', 'same', 'same-auth', null);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($id, $first->data->data->subscriptionId);
        self::assertSame($id, $second->data->data->subscriptionId);
        self::assertSame('1', $this->deviceRow($id)['revision']);
        foreach ([[$first, 'first', '2'], [$second, 'second', '3']] as [$signal, $requestId, $revision]) {
            $reply = $this->finishRenewal($signal);
            self::assertCount(1, $reply);
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply[0]->data->data);
            self::assertSame($requestId, $reply[0]->data->data->requestId);
            self::assertSame($revision, $this->deviceRow($id)['revision']);
        }
        self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM hilos_push_subscription')->firstRow()['n']);
    }

    public function testRenewalAndRemoveKeepTheirDeliveryOrderAndNeverFollowAReplacement(): void
    {
        $userId = self::seedPerson();
        $this->bindBrowser($userId);
        $this->submitSubscription('create');
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $this->drainSignals();
        $oldAsk = $this->submitSubscription('old');
        self::assertNotNull($oldAsk);
        self::assertNull($this->carry(HilosSignalConstants::HILOS_USER_PUSH_REMOVE, new UserPushRemoveSignalData(
            $userId, $id, HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY, null, PushSubscriptionAction::REMOVE, null,
        ))->error);
        $reply = $this->finishRenewal($oldAsk);
        self::assertCount(1, $reply);
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply[0]->data->data);
        self::assertSame('This device is already gone', $reply[0]->data->data->reason);
        self::assertArrayNotHasKey($id, Hilos::$db->pushSubscriptions);

        $this->submitSubscription('recreate');
        $newId = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($newId);
        self::assertNotSame($id, $newId);
        $before = $this->deviceRow($newId);
        $this->drainSignals();
        $this->finishRenewal($oldAsk);
        self::assertSame($before, $this->deviceRow($newId));
        $renewal = $this->submitSubscription('new');
        self::assertNotNull($renewal);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $this->finishRenewal($renewal)[0]->data->data);
        self::assertSame('2', $this->deviceRow($newId)['revision']);
        self::assertNull($this->carry(HilosSignalConstants::HILOS_USER_PUSH_REMOVE, new UserPushRemoveSignalData(
            $userId, $newId, HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            self::ACCEPT_KEY, null, PushSubscriptionAction::REMOVE, null,
        ))->error);
        self::assertArrayNotHasKey($newId, Hilos::$db->pushSubscriptions);
    }

    public function testGoneFoldedForeignAndFailedWritesRefuseRenewalWithoutSuccess(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $this->bindBrowser($userId);
        $this->submitSubscription('create');
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $this->drainSignals();
        $before = $this->deviceRow($id);
        // A real SQL write refusal after the owner has refreshed the baseline.
        $badKeys = $this->submitSubscription('db-refusal', str_repeat('x', 256));
        self::assertNotNull($badKeys);
        $reply = $this->finishRenewal($badKeys);
        self::assertCount(1, $reply);
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply[0]->data->data);
        self::assertSame($before, $this->deviceRow($id));

        $foreign = $this->renewalAsk($otherId, $id);
        self::assertSame('The action could not be completed.', $this->carry(HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE, $foreign)->error);
        self::assertSame($before, $this->deviceRow($id));
        Hilos::$db->userMerges->actions->add($userId, $otherId);
        $folded = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE, $this->renewalAsk($userId, $id));
        self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $folded->error);
        self::assertSame($before, $this->deviceRow($id));
        // The same addressability check also precedes creator dispatch at the external input.
        try {
            $this->submitSubscription('folded');
            self::fail('A folded account must not submit a new subscription');
        } catch (ValidationException $refusal) {
            self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $refusal->getMessage());
        }
        $goneId = self::seedPerson();
        Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [$goneId]);
        $gone = $this->carry(HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE, $this->renewalAsk($goneId, $id));
        self::assertSame("No such user: {$goneId}", $gone->error);
        self::assertSame($before, $this->deviceRow($id));
    }

    public function testUntrackedRefusalUsesTheExistingActionErrorAndLegacyForeignInputStillRebinds(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $this->subscribe($userId, self::ENDPOINT);
        $id = $this->subscriptionIdOf($userId, self::ENDPOINT);
        self::assertNotNull($id);
        $before = $this->deviceRow($id);
        $this->bindBrowser($otherId);
        $this->drainSignals();
        self::assertNull($this->submitSubscription(null, 'foreign-key'));
        $saved = $this->deviceRow($id);
        self::assertSame((string)$otherId, $saved['user_id']);
        self::assertSame('foreign-key', $saved['p256dh']);
        self::assertSame('2', $saved['revision']);
        foreach (['id', 'endpoint', 'endpoint_hash', 'created_at'] as $column) {
            self::assertSame($before[$column], $saved[$column]);
        }
        $this->drainSignals();
        $ask = $this->submitSubscription(null);
        self::assertNotNull($ask);
        $this->asPerson($otherId, static fn () => Hilos::$db->pushSubscriptions->actions->removeOwned($otherId, $id));
        $this->drainSignals();
        $reply = $this->finishRenewal($ask);
        self::assertCount(1, $reply);
        self::assertSame(SignalConstants::ACTION_ERROR, $reply[0]->signalName->getName());
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply[0]->data->data);
        self::assertNull($reply[0]->data->data->requestId);
        self::assertSame('This device is already gone', $reply[0]->data->data->reason);
    }

    /** @param int $userId Person bound to the acting browser */
    private function bindBrowser(int $userId): void
    {
        Hilos::$rt = new NotificationEditsTestRtContext($userId, self::ACCEPT_KEY);
        Hilos::$rt->configure();
    }

    /**
     * Calls the real public agent-action entry; takes only its queued personal ask off the queue.
     *
     * @param ?string $requestId Waiting submit
     * @param string $p256dh Public key
     * @param string $auth Auth secret
     * @param ?string $userAgent Device user agent
     * @return ?SignalDTO Queued renewal, or null for a synchronous create or legacy rebind
     */
    private function submitSubscription(
        ?string $requestId,
        string $p256dh = 'pk',
        string $auth = 'auth',
        ?string $userAgent = null,
    ): ?SignalDTO {
        $this->library->beginActionDispatch($requestId);
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->library->getId(), acceptKey: self::ACCEPT_KEY, requestId: $requestId),
                fn () => $this->library->onAgentAction(
                    self::ACCEPT_KEY, PushSubscriptionAction::SUBSCRIBE,
                    new PushSubscribeActionDTO(self::ENDPOINT, $p256dh, $auth, $userAgent),
                ),
            );
            $deferred = $this->library->actionReplyDeferred();
        } finally {
            $this->library->endActionDispatch();
        }
        $ask = null;
        foreach ($this->drainSignals() as $signal) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE) {
                self::assertNull($ask, 'One input queues exactly one ask');
                $ask = $signal;
            } else {
                Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
            }
        }

        self::assertSame($requestId !== null && $ask !== null, $deferred);

        return $ask;
    }

    /**
     * Runs the actual worker handover receipt, then delivers its answer back to the library.
     *
     * @param SignalDTO $signal Captured personal ask
     * @return list<SignalDTO> Browser outcomes
     */
    private function finishRenewal(SignalDTO $signal): array
    {
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(UserPushSubscribeSignalData::class, $signal->data->data);
        new NotificationEditsTestWorker($this->personAgent($signal->data->data->userId), Hilos::$sr)->deliver($signal);
        $answer = null;
        $this->carried = [];
        foreach ($this->drainSignals() as $queued) {
            if ($queued->signalName->getName() === HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE) {
                self::assertNull($answer, 'The owner answers once');
                $answer = $queued;
            } else {
                $this->carried[$queued->signalName->getName()] = $queued;
            }
        }
        self::assertNotNull($answer);
        self::assertInstanceOf(AgentSignalData::class, $answer->data);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId()),
            fn () => $this->library->onSignalAgent($answer->data, '', $answer->signalName->getName()),
        );

        return array_values(array_filter($this->drainSignals(), static fn (SignalDTO $queued): bool => $queued->data instanceof WebSocketSignalData));
    }

    /**
     * @param int $userId Person addressed by the ask
     * @param int $id Captured device
     * @return UserPushSubscribeSignalData Untracked renewal
     */
    private function renewalAsk(int $userId, int $id): UserPushSubscribeSignalData
    {
        return new UserPushSubscribeSignalData(
            $userId, $id, self::ENDPOINT, 'pk', 'auth', null,
            HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE, self::ACCEPT_KEY, null,
            PushSubscriptionAction::SUBSCRIBE, null,
        );
    }

    /** @return list<SignalDTO> All queued frames in order */
    private function drainSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Delivers a direct transport frame and checks that the owner sends no agent reply.
     *
     * @param UserPushEndpointGoneSignalData $report Device and revision captured by the transport
     */
    private function reportGone(UserPushEndpointGoneSignalData $report): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->pushChannel->sendToAgent(HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE, $report);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $queued->data, 'A transport report is not answered');
        }
    }

    /**
     * @param int $id Device row id
     * @return array<string, mixed> Independent saved device state
     */
    private function deviceRow(int $id): array
    {
        $row = Database::sql('SELECT * FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow();
        self::assertIsArray($row);

        return $row;
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
     * @return ?int Stored read epoch milliseconds, or null when the row is still unread
     * @throws DatabaseException When the lookup fails
     */
    private function readAt(int $notificationId): ?int
    {
        $row = Database::sql(
            'SELECT `read_at` FROM `' . EntityNotification::_table . '` WHERE `id` = ?',
            [$notificationId],
        )->firstRow();
        self::assertIsArray($row);
        $readAt = $row['read_at'];

        return $readAt === null ? null : (int)$readAt;
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

/** Uses the real channel fan-out with a push service that reports gone on the next tick. */
final class NotificationEditsTestPushChannel extends PushDeliveryChannelAgent
{
    /**
     * @param string $agentIndex Push shard index
     * @param AsyncHttpClient $client Client that deterministically refuses each endpoint
     */
    public function __construct(string $agentIndex, private readonly AsyncHttpClient $client)
    {
        parent::__construct($agentIndex);
    }

    /**
     * Exposes the real attempt opening to the integration case.
     *
     * @param string $address Presence marker
     * @param ObjectNotification $notification Notification to deliver
     * @return DeliveryAttempt Real fan-out attempt
     */
    public function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        return parent::createAttempt($address, $notification);
    }

    /**
     * @param ObjectPushSubscription $subscription Device being sent to
     * @param string $payload Rendered push payload
     * @return PushEndpointSend Send that reports the client's deterministic status
     */
    protected function openSend(ObjectPushSubscription $subscription, string $payload): PushEndpointSend
    {
        return new PushEndpointSend($subscription->endpoint, $this->client);
    }
}

/** The mounted session source used by the real library's user-id resolver. */
final class NotificationEditsTestRtContext extends RtContext
{
    /**
     * @param int $userId Acting person
     * @param string $acceptKey Acting browser
     */
    public function __construct(private readonly int $userId, private readonly string $acceptKey)
    {
    }

    /** Mounts the one session-stage browser used by the case. */
    public function configure(): void
    {
        $connections = NotificationEditsTestConnections::init();
        $connections->add(NotificationEditsTestConnection::create($this->acceptKey, $this->userId));
        $this->_stateCollections['notificationEditsConnections'] = $connections;
    }
}

/** @extends HilosSessionConnections<NotificationEditsTestConnection> */
final class NotificationEditsTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = NotificationEditsTestConnection::class;
}

/** Session-stage row with no additional fields. */
final class NotificationEditsTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** Actual worker dispatch supplies the ask's receipt rather than setting origin in application code. */
final class NotificationEditsTestWorker extends WorkerManager
{
    /**
     * @param AbstractUserAgent $agent Personal owner already claimed by the case
     * @param SignalRouter $router Existing queue
     */
    public function __construct(private readonly AbstractUserAgent $agent, private readonly SignalRouter $router)
    {
        parent::__construct(1);
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /**
     * @param SignalDTO $signal Queued personal ask
     */
    public function deliver(SignalDTO $signal): void
    {
        $deliver = Closure::bind(
            static function (WorkerManager $worker, DaemonAgentMessageDTO $message): void {
                $worker->handleAgentMessage($message);
            },
            null,
            WorkerManager::class,
        );
        $deliver($this, DaemonAgentMessageDTO::fromArray(new DaemonAgentMessageDTO($this->agent->getId(), $signal)->toArray()));
    }

    /** @return SignalRouter Existing queue, shared with the library fixture */
    protected function createSignalRouter(): SignalRouter
    {
        return $this->router;
    }

    /** @return AgentManager Manager whose personal agent is installed by the constructor */
    protected function createAgentManager(): AgentManager
    {
        return new NotificationEditsTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Personal agent receiving the signal
     * @return PageSignalRouter Empty page routes, leaving the signal to the personal owner
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig());
    }
}

final class NotificationEditsTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Person index
     * @return AgentInterface Personal owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new IntegrationUserAgent($agentIndex);
    }
}

/** Captures only ordinary push-subscription changes. */
final class NotificationRenewalChanges implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Observed changes */
    public array $changes = [];

    /**
     * @param SourceChange $change Persisted source fact
     * @param SourceChangeProvenance $provenance Origin of that fact
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::pushSubscriptions) {
            $this->changes[] = $change;
        }
    }
}

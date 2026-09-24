<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Notification\ChatDeliveryChannelRegistry;
use Hilos\Backup\BackupNotificationType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\NotificationDraft;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for chat notification channel dispatch and delivery-row transitions (HIL-490).
 *
 * The dispatcher has to resolve the chat registry through the facade, consult real settings and
 * identities, then persist rows and queue handoffs. The retry path likewise crosses the library's
 * signal boundary, so only the live database can prove the row is in its final stored state.
 */
final class NotificationChannelDeliveryTest extends IntegrationTestCase
{
    /** Synthetic recipient for dispatch-policy cases. */
    private const int RECIPIENT_ID = 909490;

    /** Synthetic recipient for delivery-row transition cases. */
    private const int RETRY_RECIPIENT_ID = 909491;

    /** Truth-source holder used only to write channel settings in these cases. */
    private const string SETTINGS_AGENT_ID = 'notification-channel-delivery-settings';

    /** Delivery channels exercised by the chat registry. */
    private const string EMAIL = 'email';
    private const string SMS = 'sms';

    /** Ordinary test notification type declared by the chat demo. */
    private const string TEST_TYPE = 'demo.chat.test';

    /** Channel attempts after which a delivery becomes terminally failed. */
    private const int MAX_ATTEMPTS = 3;

    /** Handover identity carried by retry frames. */
    private const string ACCEPT_KEY = 'test-accept-key';

    /** Extra distance from an existing delivery id to name a missing one. */
    private const int UNKNOWN_DELIVERY_OFFSET = 1000;

    /** Characters supplied to establish the delivery-error column limit. */
    private const int LONG_ERROR_LENGTH = 300;

    /** Router this case replaces while it captures library handoffs. */
    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testAnEnabledChannelWithAnAddressGetsAPendingRowAndItsDeliverFrame(): void
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);
        $this->giveSms(self::RECIPIENT_ID);

        $notificationId = $this->emit(self::RECIPIENT_ID);
        $rows = $this->rowsFor($notificationId);

        self::assertSame([self::EMAIL, self::SMS], array_keys($rows));
        foreach ($rows as $row) {
            self::assertSame(DeliveryStatus::PENDING, $row->status);
            self::assertSame(0, $row->attempts);
            self::assertNull($row->delivered_at);
        }

        $signals = $this->takeSignals();
        $this->assertDeliveryFrame($signals, self::EMAIL, HilosSignalConstants::HILOS_MAIL_DELIVER, $notificationId, self::RECIPIENT_ID);
        $this->assertDeliveryFrame($signals, self::SMS, HilosSignalConstants::HILOS_SMS_DELIVER, $notificationId, self::RECIPIENT_ID);
    }

    public function testADisabledChannelIsSkipped(): void
    {
        $this->enableChannel(self::EMAIL, false);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);
        $this->giveSms(self::RECIPIENT_ID);

        $notificationId = $this->emit(self::RECIPIENT_ID);

        self::assertSame([self::SMS], array_keys($this->rowsFor($notificationId)));
        $signals = $this->takeSignals();
        $this->assertNoDeliveryFrame($signals, self::EMAIL);
        $this->assertDeliveryFrame($signals, self::SMS, HilosSignalConstants::HILOS_SMS_DELIVER, $notificationId, self::RECIPIENT_ID);
    }

    public function testADraftNarrowedToOneChannelReachesOnlyThatChannel(): void
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);
        $this->giveSms(self::RECIPIENT_ID);

        $notificationId = $this->emit(self::RECIPIENT_ID, channels: [self::SMS]);

        self::assertSame([self::SMS], array_keys($this->rowsFor($notificationId)));
        $signals = $this->takeSignals();
        $this->assertNoDeliveryFrame($signals, self::EMAIL);
        $this->assertDeliveryFrame($signals, self::SMS, HilosSignalConstants::HILOS_SMS_DELIVER, $notificationId, self::RECIPIENT_ID);
    }

    public function testARecipientWithoutAnAddressIsSkippedOnThatChannel(): void
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);

        $notificationId = $this->emit(self::RECIPIENT_ID);

        self::assertSame([self::EMAIL], array_keys($this->rowsFor($notificationId)));
        $signals = $this->takeSignals();
        $this->assertDeliveryFrame($signals, self::EMAIL, HilosSignalConstants::HILOS_MAIL_DELIVER, $notificationId, self::RECIPIENT_ID);
        $this->assertNoDeliveryFrame($signals, self::SMS);
    }

    public function testAMutedChannelIsSkipped(): void
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);
        $this->giveSms(self::RECIPIENT_ID);
        $this->preferences()->setChannel(self::RECIPIENT_ID, self::EMAIL, false);

        $notificationId = $this->emit(self::RECIPIENT_ID);

        self::assertSame([self::SMS], array_keys($this->rowsFor($notificationId)));
        $signals = $this->takeSignals();
        $this->assertNoDeliveryFrame($signals, self::EMAIL);
        $this->assertDeliveryFrame($signals, self::SMS, HilosSignalConstants::HILOS_SMS_DELIVER, $notificationId, self::RECIPIENT_ID);
    }

    public function testAMandatoryTypePassesAMute(): void
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, true);
        $this->giveEmail(self::RECIPIENT_ID);
        $this->giveSms(self::RECIPIENT_ID);
        $this->preferences()->setChannel(self::RECIPIENT_ID, self::EMAIL, false);

        $notificationId = $this->emit(self::RECIPIENT_ID, type: BackupNotificationType::RESTORE_SUCCEEDED);

        self::assertSame([self::EMAIL, self::SMS], array_keys($this->rowsFor($notificationId)));
        $signals = $this->takeSignals();
        $this->assertDeliveryFrame($signals, self::EMAIL, HilosSignalConstants::HILOS_MAIL_DELIVER, $notificationId, self::RECIPIENT_ID);
        $this->assertDeliveryFrame($signals, self::SMS, HilosSignalConstants::HILOS_SMS_DELIVER, $notificationId, self::RECIPIENT_ID);
    }

    public function testAnAttemptCountsAndASendStampsTheDeliveredTime(): void
    {
        $delivery = $this->pendingRetryDelivery();

        $this->deliveries()->beginAttempt($delivery);
        $attempted = $this->row($this->deliveryId($delivery));
        self::assertSame(1, $attempted->attempts);
        self::assertSame(DeliveryStatus::PENDING, $attempted->status);

        $this->deliveries()->markSent($delivery);
        $sent = $this->row($this->deliveryId($delivery));
        self::assertSame(DeliveryStatus::SENT, $sent->status);
        self::assertNotNull($sent->delivered_at);
    }

    public function testAFailureStaysPendingBelowTheCeilingAndFailsAtIt(): void
    {
        $delivery = $this->pendingRetryDelivery();

        $this->deliveries()->beginAttempt($delivery);
        $this->deliveries()->recordFailure($delivery, 'smtp down', self::MAX_ATTEMPTS);
        $firstFailure = $this->row($this->deliveryId($delivery));
        self::assertSame(DeliveryStatus::PENDING, $firstFailure->status);
        self::assertSame(1, $firstFailure->attempts);
        self::assertSame('smtp down', $firstFailure->last_error);

        $this->deliveries()->beginAttempt($delivery);
        $this->deliveries()->recordFailure($delivery, 'smtp down', self::MAX_ATTEMPTS);
        $this->deliveries()->beginAttempt($delivery);
        $this->deliveries()->recordFailure($delivery, str_repeat('x', self::LONG_ERROR_LENGTH), self::MAX_ATTEMPTS);
        $failed = $this->row($this->deliveryId($delivery));
        self::assertSame(DeliveryStatus::FAILED, $failed->status);
        self::assertSame(self::MAX_ATTEMPTS, $failed->attempts);
        self::assertSame(255, mb_strlen((string) $failed->last_error));
    }

    public function testARetriedFailedRowIsResetAndQueuedAgain(): void
    {
        $delivery = $this->failedRetryDelivery();
        $deliveryId = $this->deliveryId($delivery);

        $this->retry($deliveryId);
        $retried = $this->row($deliveryId);
        self::assertSame(DeliveryStatus::PENDING, $retried->status);
        self::assertSame(0, $retried->attempts);
        self::assertNull($retried->last_error);
        self::assertNull($retried->delivered_at);

        $signals = $this->takeSignals();
        $this->assertDeliveryFrame(
            $signals,
            self::EMAIL,
            HilosSignalConstants::HILOS_MAIL_DELIVER,
            $retried->notification_id,
            self::RETRY_RECIPIENT_ID,
        );
        self::assertNull($this->retryAnswer($signals)->error);
    }

    public function testARetryOfARowThatIsNotFailedIsRefusedWithItsSentence(): void
    {
        $delivery = $this->pendingRetryDelivery();
        $deliveryId = $this->deliveryId($delivery);

        $this->retry($deliveryId);
        self::assertSame('Only failed deliveries can be retried', $this->retryAnswer($this->takeSignals())->error);
        self::assertSame(DeliveryStatus::PENDING, $this->row($deliveryId)->status);

        $this->retry($deliveryId + self::UNKNOWN_DELIVERY_OFFSET);
        self::assertSame(
            'Unknown delivery: ' . ($deliveryId + self::UNKNOWN_DELIVERY_OFFSET),
            $this->retryAnswer($this->takeSignals())->error,
        );
    }

    /**
     * Emits one notification through the library after discarding setup signals.
     *
     * @param int $userId Notification recipient
     * @param ?list<string> $channels Optional delivery-channel narrowing
     * @param string $type Notification machine type
     * @return int Persisted notification id
     */
    private function emit(int $userId, ?array $channels = null, string $type = self::TEST_TYPE): int
    {
        $library = $this->notificationsLibrary();
        $this->drainSignals();

        return $this->underAgent($library, static fn (): int => $library->emit(new NotificationDraft(
            userId: $userId,
            type: $type,
            title: 'Notification delivery test',
            channels: $channels,
        )));
    }

    /** Writes the enablement row for one delivery channel under its own settings writer. */
    private function enableChannel(string $channel, bool $enabled): void
    {
        $this->writeChannelSetting($channel, $enabled);
    }

    /** Removes both channel-enable rows so no following chat integration case inherits them. */
    private function dropChannelSwitches(): void
    {
        $this->writeChannelSetting(self::EMAIL, null);
        $this->writeChannelSetting(self::SMS, null);
    }

    /**
     * Writes or removes one channel setting under the only truth source this fixture creates.
     *
     * @param string $channel Delivery channel name
     * @param ?bool $enabled Stored switch value, or null to remove the override
     */
    private function writeChannelSetting(string $channel, ?bool $enabled): void
    {
        $previous = ExecutionContext::currentAgentId();
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SETTINGS_AGENT_ID);
        $key = DeliveryChannelSettings::enabledKey($channel);

        try {
            Hilos::$db->settings[$key]?->actions->delete();
            if ($enabled !== null) {
                Hilos::$db->settings->actions->add($key, $enabled, Hilos::$setting->catalog());
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
            ExecutionContext::setCurrentAgentId($previous);
        }
    }

    /** Gives the synthetic recipient a verified email identity. */
    private function giveEmail(int $userId): void
    {
        Hilos::$db->identities->createMagicLinkIdentity($userId, RandomHelper::hex(8) . '@example.test');
    }

    /** Gives the synthetic recipient a verified SMS identity. */
    private function giveSms(int $userId): void
    {
        Hilos::$db->identities->createSmsIdentity($userId, '+4860' . RandomHelper::integer(1000000, 9999999));
    }

    /**
     * Returns a notification's journal rows by channel, read from the entity layer past object caches.
     *
     * @param int $notificationId Notification whose delivery rows are read
     * @return array<string, EntityNotificationDelivery> Rows keyed by channel
     */
    private function rowsFor(int $notificationId): array
    {
        $rows = [];
        foreach (EntityNotificationDelivery::get([EntityNotificationDelivery::notification_id => $notificationId]) as $row) {
            $rows[$row->channel] = $row;
        }
        ksort($rows);

        return $rows;
    }

    /**
     * Reads one delivery row directly from the entity layer.
     *
     * @param int $deliveryId Delivery id to read
     * @return EntityNotificationDelivery Stored delivery row
     */
    private function row(int $deliveryId): EntityNotificationDelivery
    {
        $row = EntityNotificationDelivery::getById($deliveryId);
        self::assertInstanceOf(EntityNotificationDelivery::class, $row);

        return $row;
    }

    /** @return ObjectNotificationDeliveries Delivery collection the library owns. */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notificationDeliveries);
        self::assertInstanceOf(ObjectNotificationDeliveries::class, $collection);

        return $collection;
    }

    /** @return ObjectNotificationPreferences Sparse per-recipient channel preferences. */
    private function preferences(): ObjectNotificationPreferences
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notificationPreferences);
        self::assertInstanceOf(ObjectNotificationPreferences::class, $collection);

        return $collection;
    }

    /** Creates one pending email delivery suitable for transition and retry cases. */
    private function pendingRetryDelivery(): ObjectNotificationDelivery
    {
        $this->enableChannel(self::EMAIL, true);
        $this->enableChannel(self::SMS, false);
        $this->giveEmail(self::RETRY_RECIPIENT_ID);
        $notificationId = $this->emit(self::RETRY_RECIPIENT_ID, channels: [self::EMAIL]);
        $delivery = $this->deliveries()->findFor($notificationId, self::EMAIL);
        self::assertInstanceOf(ObjectNotificationDelivery::class, $delivery);
        $this->takeSignals();

        return $delivery;
    }

    /** Drives a pending email delivery through the attempt ceiling. */
    private function failedRetryDelivery(): ObjectNotificationDelivery
    {
        $delivery = $this->pendingRetryDelivery();
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $this->deliveries()->beginAttempt($delivery);
            $this->deliveries()->recordFailure($delivery, 'smtp down', self::MAX_ATTEMPTS);
        }

        return $delivery;
    }

    /** Delivers the retry handover to the notifications library under its own execution context. */
    private function retry(int $deliveryId): void
    {
        $library = $this->notificationsLibrary();
        $this->drainSignals();
        $this->underAgent($library, function () use ($deliveryId, $library): void {
            $library->onSignalAgent(
                new AgentSignalData(new DeliveryRetrySignalData(
                    deliveryId: $deliveryId,
                    replySignal: HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
                    acceptKey: self::ACCEPT_KEY,
                    requestId: null,
                    action: HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
                    successMessage: null,
                )),
                '',
                HilosSignalConstants::HILOS_DELIVERY_RETRY,
            );
        });
    }

    /**
     * Takes every queued signal so assertions see only the act that just happened.
     *
     * @return list<SignalDTO> Queued signals in delivery order
     */
    private function takeSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Asserts that the queue carries exactly one delivery frame for a channel.
     *
     * @param list<SignalDTO> $signals Queued signals to inspect
     * @param string $channel Expected channel
     * @param string $name Expected delivery signal name
     * @param int $notificationId Notification id in the frame
     * @param int $userId Recipient used to derive the shard key
     */
    private function assertDeliveryFrame(array $signals, string $channel, string $name, int $notificationId, int $userId): void
    {
        foreach ($signals as $signal) {
            $data = $signal->data;
            if (!$data instanceof AgentSignalData || !$data->data instanceof NotificationDeliverSignalData || $data->data->channel !== $channel) {
                continue;
            }

            self::assertSame($name, $signal->signalName->getName());
            self::assertSame($notificationId, $data->data->notificationId);
            self::assertSame(
                ChatDeliveryChannelRegistry::get($channel)?->shardKeyFor($userId, $notificationId),
                $data->data->shardKey,
            );

            return;
        }

        self::fail("No {$channel} delivery frame was queued");
    }

    /**
     * Asserts that no delivery handover names a channel.
     *
     * @param list<SignalDTO> $signals Queued signals to inspect
     * @param string $channel Channel that must be absent
     */
    private function assertNoDeliveryFrame(array $signals, string $channel): void
    {
        foreach ($signals as $signal) {
            $data = $signal->data;
            if ($data instanceof AgentSignalData && $data->data instanceof NotificationDeliverSignalData) {
                self::assertNotSame($channel, $data->data->channel);
            }
        }
    }

    /**
     * Returns the retry handover answer among a library dispatch's queued frames.
     *
     * @param list<SignalDTO> $signals Queued signals to inspect
     * @return HandoverAnswerSignalData Retry outcome
     */
    private function retryAnswer(array $signals): HandoverAnswerSignalData
    {
        foreach ($signals as $signal) {
            $data = $signal->data;
            if ($data instanceof AgentSignalData && $data->data instanceof HandoverAnswerSignalData) {
                return $data->data;
            }
        }

        self::fail('No retry answer was queued');
    }

    /**
     * Returns a delivery object's durable id.
     *
     * @param ObjectNotificationDelivery $delivery Delivery whose id is required
     * @return int Persisted delivery id
     */
    private function deliveryId(ObjectNotificationDelivery $delivery): int
    {
        self::assertNotNull($delivery->id);

        return $delivery->id;
    }

    /** Removes every fixture row and both channel switches for the synthetic recipients. */
    private function cleanUp(): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int(self::RECIPIENT_ID));
        $params->add(SqlParam::int(self::RETRY_RECIPIENT_ID));
        Database::sql(
            'DELETE FROM `' . EntityNotificationDelivery::_table . '` WHERE `' . EntityNotificationDelivery::notification_id . '` IN ('
            . 'SELECT `' . EntityNotification::id . '` FROM `' . EntityNotification::_table . '` WHERE `'
            . EntityNotification::user_id . '` IN (?, ?))',
            $params,
        );
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::user_id . '` IN (?, ?)',
            $params,
        );
        Database::sql(
            'DELETE FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` IN (?, ?)',
            $params,
        );
        $this->preferences()->deleteForUser(self::RECIPIENT_ID);
        $this->preferences()->deleteForUser(self::RETRY_RECIPIENT_ID);
        $this->dropChannelSwitches();
    }
}

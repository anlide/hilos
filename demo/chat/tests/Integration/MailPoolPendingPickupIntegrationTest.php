<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\NotificationDraft;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Utils\Helpers\RandomHelper;
use RuntimeException;

/**
 * A fresh mail shard adopts only its pending rows and resumes their attempt count (HIL-1135).
 */
final class MailPoolPendingPickupIntegrationTest extends IntegrationTestCase
{
    /** Fixture recipient backed by hilos_user. */
    private const int RECIPIENT_ID = 909061;

    /** Delivery channel the mail pool answers for. */
    private const string CHANNEL = 'email';

    /** Orphaned delivery to remove after its notification has gone. */
    private ?int $orphanNotificationId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Recipient')", [self::RECIPIENT_ID]);
    }

    protected function tearDown(): void
    {
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        $this->deleteRecipientRows();
        if ($this->orphanNotificationId !== null) {
            Database::sql(
                'DELETE FROM `' . EntityNotificationDelivery::_table . '` WHERE `'
                . EntityNotificationDelivery::notification_id . '` = ?',
                [$this->orphanNotificationId],
            );
        }
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [self::RECIPIENT_ID]);
        parent::tearDown();
    }

    public function testFreshShardSendsItsPendingRowWithoutADispatchSignal(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);

        $agent->onTick();
        $agent->onTick();

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testForeignShardLeavesTheRowUntouched(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('2', []);

        $agent->onTick();

        self::assertSame(0, $agent->createdCount);
        self::assertSame(DeliveryStatus::PENDING, $this->row($notificationId)->status);
        self::assertSame(0, $this->row($notificationId)->attempts);
    }

    public function testPriorAttemptsStillCountTowardTheFailureCeiling(): void
    {
        $notificationId = $this->pendingNotification();
        $delivery = $this->deliveries()->findFor($notificationId, self::CHANNEL);
        self::assertNotNull($delivery);
        $delivery->attempts = 2;
        $delivery->sync();
        $agent = new PendingPickupProbeMailAgent('1', [
            new PickupMailTransport(MailSendOutcome::failed('provider refused', false)),
        ]);

        $agent->onTick();
        $agent->onTick();

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::FAILED, $this->row($notificationId)->status);
        self::assertSame(3, $this->row($notificationId)->attempts);
    }

    public function testDispatchSignalBeforeFirstTickDoesNotDuplicateTheAttempt(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($notificationId, self::CHANNEL, 1)),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );

        $agent->onTick();
        $agent->onTick();

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testFreshShardStartsUnderFreezeAndPicksUpAfterItLifts(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);

        $agent->onTick();
        self::assertSame(0, $agent->createdCount);
        self::assertSame(0, $this->row($notificationId)->attempts);

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent->onTick();
        $agent->onTick();

        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testOrphanedNotificationIsSettledByShardOne(): void
    {
        $notificationId = $this->pendingNotification();
        $this->orphanNotificationId = $notificationId;
        $delivery = $this->deliveries()->findFor($notificationId, self::CHANNEL);
        self::assertNotNull($delivery);
        $delivery->attempts = 2;
        $delivery->sync();
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::id . '` = ?',
            [$notificationId],
        );
        Hilos::$db->getObjectCollection(HilosDbContext::notifications)?->clearInMemory();
        $agent = new PendingPickupProbeMailAgent('1', []);

        $agent->onTick();

        self::assertSame(0, $agent->createdCount);
        self::assertSame(DeliveryStatus::FAILED, $this->row($notificationId)->status);
        self::assertSame('notification unavailable', $this->row($notificationId)->last_error);
    }

    /**
     * Emits one notification before adding an address, then creates its pending email row.
     *
     * @return int Notification id
     */
    private function pendingNotification(): int
    {
        $this->deleteRecipientRows();
        $notificationId = $this->notificationsLibrary()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'Mail pending pickup',
        ));
        Hilos::$db->identities->createMagicLinkIdentity(self::RECIPIENT_ID, RandomHelper::hex(8) . '@example.test');
        $this->deliveries()->createPending($notificationId, self::CHANNEL);

        return $notificationId;
    }

    /**
     * @param string $phase Freeze phase to mount
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
        ]));
    }

    /**
     * @param int $notificationId Notification id
     * @return EntityNotificationDelivery The one email row
     */
    private function row(int $notificationId): EntityNotificationDelivery
    {
        $rows = EntityNotificationDelivery::get([
            EntityNotificationDelivery::notification_id => $notificationId,
            EntityNotificationDelivery::channel => self::CHANNEL,
        ]);
        self::assertCount(1, $rows);
        foreach ($rows as $row) {
            return $row;
        }

        self::fail('the delivery row is gone');
    }

    /**
     * @return ObjectNotificationDeliveries The delivery journal
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notificationDeliveries);
        self::assertInstanceOf(ObjectNotificationDeliveries::class, $collection);

        return $collection;
    }

    /** Removes this case's recipient rows, including deliveries from earlier runs. */
    private function deleteRecipientRows(): void
    {
        Database::sql(
            'DELETE FROM `' . EntityNotificationDelivery::_table . '` WHERE `'
            . EntityNotificationDelivery::notification_id . '` IN ('
            . 'SELECT `' . EntityNotification::id . '` FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::user_id . '` = ?)',
            [self::RECIPIENT_ID],
        );
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::user_id . '` = ?',
            [self::RECIPIENT_ID],
        );
        Database::sql(
            'DELETE FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` = ?',
            [self::RECIPIENT_ID],
        );
    }
}

/** Supplies a chosen shard index and scripted transports for pickup cases. */
final class PendingPickupProbeMailAgent extends MailDeliveryChannelAgent
{
    public int $createdCount = 0;

    /**
     * @param string $agentIndex Shard to impersonate
     * @param list<MailTransportInterface> $transports Attempt transports in order
     */
    public function __construct(string $agentIndex, private array $transports)
    {
        parent::__construct($agentIndex);
    }

    protected function createTransport(): MailTransportInterface
    {
        $this->createdCount++;
        $transport = array_shift($this->transports);
        if ($transport === null) {
            throw new RuntimeException('no scripted transport left');
        }

        return $transport;
    }
}

/** Completes one mail attempt on the next tick with its scripted outcome. */
final class PickupMailTransport implements MailTransportInterface
{
    private bool $busy = false;

    public function __construct(private readonly MailSendOutcome $outcome)
    {
    }

    public function start(EmailMessage $message, float $nowMs): void
    {
        $this->busy = true;
    }

    public function tick(float $nowMs): void
    {
        $this->busy = false;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function hasResult(): bool
    {
        return !$this->busy;
    }

    public function consumeResult(): MailSendOutcome
    {
        if ($this->busy) {
            throw new MailResultUnavailableException('the send is still active');
        }

        return $this->outcome;
    }

    public function close(): void
    {
        $this->busy = false;
    }
}

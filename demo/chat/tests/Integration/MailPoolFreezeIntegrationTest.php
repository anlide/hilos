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
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\NotificationDraft;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Utils\Helpers\RandomHelper;
use RuntimeException;

/**
 * Proves a mail delivery in flight when the freeze begins writes nothing to its row (HIL-1060).
 *
 * The freeze leaves the mail pool running for the watchdog's alarm, and the pool's durable half
 * silences itself instead: the attempt is cut, the queue forgotten, and the delivery row stays
 * exactly as the last write before the freeze left it. The unit cases of the pool see "writes
 * nothing" as a journal that throws when touched; this one needs the journal real, because only
 * an attempt already in flight - a row counted, a transport open - shows what the drop does to
 * a row it has started.
 */
final class MailPoolFreezeIntegrationTest extends IntegrationTestCase
{
    /** Synthetic recipient: hilos_notification has no FK to the project user table. */
    private const int RECIPIENT_ID = 909060;

    /** Delivery channel the mail pool answers for. */
    private const string CHANNEL = 'email';

    protected function tearDown(): void
    {
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        $this->deleteRecipientRows();
        parent::tearDown();
    }

    public function testAnAttemptInFlightWhenTheFreezeBeginsWritesNothing(): void
    {
        $this->deleteRecipientRows();
        // Emitted before the address exists, so the dispatch mints no row of its own and the
        // one row below is the only one the pool can find.
        $notificationId = $this->notificationsLibrary()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'Mail under a freeze',
        ));
        Hilos::$db->identities->createMagicLinkIdentity(self::RECIPIENT_ID, RandomHelper::hex(8) . '@example.test');
        $this->deliveries()->createPending($notificationId, self::CHANNEL);

        $transport = new HeldMailTransport();
        $agent = new FreezeProbeMailAgent([$transport]);
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData(notificationId: $notificationId, channel: self::CHANNEL, shardKey: 1)),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );

        $agent->onTick();
        self::assertInstanceOf(EmailMessage::class, $transport->started);
        $started = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $started->status);
        self::assertSame(1, $started->attempts);

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);
        $agent->onTick();
        self::assertTrue($transport->closed);
        $frozen = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $frozen->status);
        self::assertSame(1, $frozen->attempts);
        self::assertSame($started->updated_at, $frozen->updated_at);

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent->onTick();
        self::assertSame(1, $agent->createdCount);
        $after = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $after->status);
        self::assertSame(1, $after->attempts);
        self::assertSame($started->updated_at, $after->updated_at);
    }

    /**
     * Mounts this node's freeze row in the phase the case needs.
     *
     * Built through the deserialization path an inbound RT sync uses, as the pool's unit cases do.
     *
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
     * Reads the delivery row straight from the table, past the collection's cache.
     *
     * @param int $notificationId Notification the row delivers
     * @return EntityNotificationDelivery The one email row of that notification
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
     * @return ObjectNotificationDeliveries The framework-owned delivery journal
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notificationDeliveries);
        self::assertInstanceOf(ObjectNotificationDeliveries::class, $collection);

        return $collection;
    }

    /**
     * Removes what the case wrote for its recipient: identities, notifications and their rows.
     */
    private function deleteRecipientRows(): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int(self::RECIPIENT_ID));
        Database::sql(
            'DELETE FROM `' . EntityNotificationDelivery::_table . '` WHERE `' . EntityNotificationDelivery::notification_id . '` IN ('
            . 'SELECT `' . EntityNotification::id . '` FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::user_id . '` = ?)',
            $params,
        );
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::user_id . '` = ?',
            $params,
        );
        Database::sql(
            'DELETE FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` = ?',
            $params,
        );
    }
}

/**
 * A mail agent whose transport factory is scripted, as the pool's unit cases build one.
 */
final class FreezeProbeMailAgent extends MailDeliveryChannelAgent
{
    public int $createdCount = 0;

    /**
     * @param list<MailTransportInterface> $transports Transports to hand out in order
     */
    public function __construct(private array $transports)
    {
        parent::__construct('1');
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

/**
 * A mail transport that stays busy until it is closed: the send never settles on its own.
 */
final class HeldMailTransport implements MailTransportInterface
{
    public ?EmailMessage $started = null;
    public bool $closed = false;

    private bool $busy = false;

    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->busy) {
            throw new MailBusyException('transport already sending');
        }
        $this->started = $message;
        $this->busy = true;
    }

    public function tick(float $nowMs): void
    {
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function hasResult(): bool
    {
        return false;
    }

    public function consumeResult(): MailSendOutcome
    {
        throw new MailResultUnavailableException('the held send never settles');
    }

    public function close(): void
    {
        $this->closed = true;
        $this->busy = false;
    }
}

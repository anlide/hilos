<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
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
 * Proves a freeze abandons an attempt without writing, then the shard picks it up (HIL-1135).
 *
 * The freeze leaves the mail pool running for the watchdog's alarm, and the pool's durable half
 * silences itself instead: the attempt is cut, the queue forgotten, and the delivery row stays
 * exactly as the last write before the freeze left it. The unit cases of the pool see "writes
 * nothing" as a journal that throws when touched; this one needs the journal real, because only
 * an attempt already in flight - a row counted, a transport open - shows what the drop does to
 * a row it has started. The first tick after the freeze lifts opens a new attempt from that row.
 */
final class MailPoolFreezeIntegrationTest extends IntegrationTestCase
{
    /** Fixture recipient backed by hilos_user. */
    private const int RECIPIENT_ID = 909060;

    /** Delivery channel the mail pool answers for. */
    private const string CHANNEL = 'email';

    /** @var list<FreezeProbeMailAgent> Started mail instances whose claims end with the case */
    private array $mailAgents = [];

    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Recipient')", [self::RECIPIENT_ID]);
    }

    protected function tearDown(): void
    {
        foreach ($this->mailAgents as $agent) {
            try {
                $this->underAgent($agent, $agent->onStop(...));
            } finally {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                RtTruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
        }
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        $this->deleteRecipientRows();
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [self::RECIPIENT_ID]);
        parent::tearDown();
    }

    public function testAnAttemptInFlightWritesNothingDuringFreezeThenResumes(): void
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
        $this->deliveries()->createPending($notificationId, self::CHANNEL, 0);

        $transport = new HeldMailTransport();
        $resumedTransport = new HeldMailTransport();
        $agent = new FreezeProbeMailAgent([$transport, $resumedTransport]);
        $this->startMailAgent($agent);
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData(
                deliveryId: $this->row($notificationId)->id,
                notificationId: $notificationId,
                channel: self::CHANNEL,
                shardKey: 1,
            )),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );

        $this->underAgent($agent, $agent->onTick(...));
        self::assertInstanceOf(EmailMessage::class, $transport->started);
        $started = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $started->status);
        self::assertSame(1, $started->attempts);

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertTrue($transport->closed);
        $frozen = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $frozen->status);
        self::assertSame(1, $frozen->attempts);
        self::assertSame($started->updated_at, $frozen->updated_at);

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(2, $agent->createdCount);
        self::assertInstanceOf(EmailMessage::class, $resumedTransport->started);
        $after = $this->row($notificationId);
        self::assertSame(DeliveryStatus::PENDING, $after->status);
        self::assertSame(2, $after->attempts);
    }

    public function testDirectWindowStillStartsAndWritesADelivery(): void
    {
        $this->deleteRecipientRows();
        $notificationId = $this->notificationsLibrary()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'Mail in a direct window',
        ));
        Hilos::$db->identities->createMagicLinkIdentity(self::RECIPIENT_ID, RandomHelper::hex(8) . '@example.test');
        $this->deliveries()->createPending($notificationId, self::CHANNEL, 0);

        $transport = new HeldMailTransport();
        $agent = new FreezeProbeMailAgent([$transport]);
        $this->startMailAgent($agent);
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData(
                deliveryId: $this->row($notificationId)->id,
                notificationId: $notificationId,
                channel: self::CHANNEL,
                shardKey: 1,
            )),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );

        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW);
        $this->underAgent($agent, $agent->onTick(...));

        self::assertInstanceOf(EmailMessage::class, $transport->started);
        self::assertFalse($transport->closed);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    /** @param FreezeProbeMailAgent $agent Instance started with the harness's real declaration claims */
    private function startMailAgent(FreezeProbeMailAgent $agent): void
    {
        $this->startAgent($agent);
        $this->mailAgents[] = $agent;
    }

    /**
     * Mounts this node's freeze row in the phase the case needs.
     *
     * Built through the deserialization path an inbound RT sync uses, as the pool's unit cases do.
     *
     * @param string $phase Freeze phase to mount
     * @param ?string $entryMode Entry variant, inferred for legacy rows when null
     */
    private function freeze(string $phase, ?string $entryMode = null): void
    {
        $row = [
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ];
        if ($entryMode !== null) {
            $row[StateProtectedModeRuntime::entryMode] = $entryMode;
        }

        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow($row));
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

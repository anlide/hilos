<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\NotificationDelivery;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\DeliveryChannelOwners;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\NotificationDraft;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Proves the declared startup owner adopts saved work even when new email delivery is disabled. */
final class DeliveryChannelOwnerStartupIntegrationTest extends IntegrationTestCase
{
    private const string SETTINGS_AGENT_ID = 'delivery-startup-settings';

    /** Recipient allocated by the normal user action without advancing the fixture-id range. */
    private ?int $recipientId = null;

    /** Started instance whose claims and transport end with the case. */
    private ?StartupProbeMailAgent $mailAgent = null;

    /** Previous stored switch, or null when the catalog default applied. */
    private ?bool $previousEmailEnabled = null;

    /** The first tick needs neither a dispatch signal nor an enabled intake. */
    public function testDeclaredOwnerStartsSavedPendingDeliveryWithoutASignal(): void
    {
        self::assertNotNull($this->recipientId);
        $enabledKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        self::assertFalse(Hilos::$setting[$enabledKey]->bool());
        Hilos::$db->identities->createMagicLinkIdentity($this->recipientId, 'startup-recipient@example.test');
        $notificationId = $this->notificationsLibrary()->emit(new NotificationDraft(
            userId: $this->recipientId,
            type: 'demo.chat.test',
            title: 'Saved mail at owner startup',
        ));
        self::assertSame([], Database::sql(
            'SELECT id FROM hilos_notification_delivery WHERE notification_id = ?',
            [$notificationId],
        )->rows());
        $deliveryId = Hilos::$db->notificationDeliveries->actions->createPending(
            $notificationId,
            MailDeliveryChannel::NAME,
            HilosMailer::routingKeyForAddress('startup-recipient@example.test'),
        )->id;
        self::assertNotNull($deliveryId);
        $identity = Hilos::$db->notificationDeliveries->readIdentity($deliveryId);
        self::assertNotNull($identity);
        $owner = DeliveryOwnerResolver::forDelivery($identity);
        self::assertNotNull($owner);
        self::assertNotNull($owner->index);
        $owners = DeliveryChannelOwners::of(MailDeliveryChannel::NAME);
        self::assertSame(MailDeliveryChannelAgent::AGENT_TYPE, $owners->agentType);
        self::assertSame($owners->agentType, $owner->type);
        self::assertContains($owner->index, $owners->agentIndexes);

        $notificationsBefore = Database::sql('SELECT id FROM hilos_notification ORDER BY id')->rows();
        $deliveriesBefore = Database::sql('SELECT id FROM hilos_notification_delivery ORDER BY id')->rows();
        $pending = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$deliveryId])->rows();
        self::assertCount(1, $pending);
        self::assertSame(DeliveryStatus::PENDING, $pending[0][NotificationDelivery::status]);
        self::assertSame(0, (int)$pending[0][NotificationDelivery::attempts]);

        $transport = new StartupHeldMailTransport();
        $this->mailAgent = new StartupProbeMailAgent($owner->index, $transport);
        $this->startAgent($this->mailAgent);
        $this->underAgent($this->mailAgent, $this->mailAgent->onTick(...));

        self::assertInstanceOf(EmailMessage::class, $transport->started);
        self::assertFalse($transport->closed);
        $started = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$deliveryId])->rows();
        self::assertCount(1, $started);
        self::assertSame($pending[0][NotificationDelivery::id], $started[0][NotificationDelivery::id]);
        self::assertSame(DeliveryStatus::PENDING, $started[0][NotificationDelivery::status]);
        self::assertSame(1, (int)$started[0][NotificationDelivery::attempts]);
        self::assertSame($notificationsBefore, Database::sql('SELECT id FROM hilos_notification ORDER BY id')->rows());
        self::assertSame($deliveriesBefore, Database::sql('SELECT id FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertFalse(Hilos::$setting[$enabledKey]->bool());
    }

    /** Mounts the real Chat declarations and disables new email intake through its setting action. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        $enabledKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        $this->previousEmailEnabled = isset(Hilos::$db->settings[$enabledKey]) ? Hilos::$setting[$enabledKey]->bool() : null;
        $this->writeEmailEnabled(false);
        $this->recipientId = Hilos::$db->users->actions->createWithName('Startup recipient')->id;
        self::assertNotNull($this->recipientId);
    }

    /** Releases the probe's claims and removes its recipient rows without leaving a setting override. */
    protected function tearDown(): void
    {
        try {
            if ($this->mailAgent !== null) {
                try {
                    $this->underAgent($this->mailAgent, $this->mailAgent->onStop(...));
                } finally {
                    TruthSourceRegistry::unregisterAgent($this->mailAgent->getId());
                    RtTruthSourceRegistry::unregisterAgent($this->mailAgent->getId());
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->mailAgent->getId()));
                }
            }
            $this->writeEmailEnabled($this->previousEmailEnabled);
            if ($this->recipientId !== null) {
                Database::sqlRun(
                    'DELETE FROM hilos_notification_delivery WHERE notification_id IN (SELECT id FROM hilos_notification WHERE user_id = ?)',
                    [$this->recipientId],
                );
                Database::sqlRun('DELETE FROM hilos_notification WHERE user_id = ?', [$this->recipientId]);
                Database::sqlRun('DELETE FROM hilos_identity WHERE user_id = ?', [$this->recipientId]);
                Hilos::$db->users[$this->recipientId]->actions->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @param ?bool $enabled Stored switch, or null to remove the override */
    private function writeEmailEnabled(?bool $enabled): void
    {
        $previousAgentId = ExecutionContext::currentAgentId();
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::SETTINGS_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SETTINGS_AGENT_ID);
        $key = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        try {
            if ($enabled === null) {
                if (isset(Hilos::$db->settings[$key])) {
                    Hilos::$db->settings[$key]->actions->delete();
                }
            } elseif (isset(Hilos::$db->settings[$key])) {
                Hilos::$db->settings[$key]->actions->updateValue($enabled);
            } else {
                Hilos::$db->settings->actions->add($key, $enabled, Hilos::$setting->catalog());
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SETTINGS_AGENT_ID);
            ExecutionContext::setCurrentAgentId($previousAgentId);
        }
    }
}

/** Uses the real mail adoption pipeline with a transport owned only by this test instance. */
final class StartupProbeMailAgent extends MailDeliveryChannelAgent
{
    /**
     * @param string $agentIndex Declared owner index
     * @param MailTransportInterface $transport Attempt transport to observe
     */
    public function __construct(string $agentIndex, private MailTransportInterface $transport)
    {
        parent::__construct($agentIndex);
    }

    /** @return MailTransportInterface The held attempt transport */
    protected function createTransport(): MailTransportInterface
    {
        return $this->transport;
    }
}

/** Keeps an attempt open so the first tick's persisted bookkeeping can be inspected. */
final class StartupHeldMailTransport implements MailTransportInterface
{
    public ?EmailMessage $started = null;
    public bool $closed = false;

    /**
     * @param EmailMessage $message Message adopted from the saved delivery
     * @param float $nowMs Current tick time, unused
     */
    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->isBusy()) {
            throw new MailBusyException('The startup transport already has an attempt');
        }
        $this->started = $message;
    }

    /** @param float $nowMs Current tick time, unused while the attempt stays open */
    public function tick(float $nowMs): void
    {
    }

    /** @return bool Whether the attempt is still open */
    public function isBusy(): bool
    {
        return $this->started !== null && !$this->closed;
    }

    /** @return bool False because the attempt never settles */
    public function hasResult(): bool
    {
        return false;
    }

    /** @return MailSendOutcome No outcome is available from this held transport */
    public function consumeResult(): MailSendOutcome
    {
        throw new MailResultUnavailableException('The startup attempt remains open');
    }

    /** Releases the held attempt at agent stop. */
    public function close(): void
    {
        $this->closed = true;
    }
}

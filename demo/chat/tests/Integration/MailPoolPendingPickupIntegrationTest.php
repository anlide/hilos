<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Hilos\Constants\HilosSignalConstants;
use Demo\Chat\Hilos as ChatHilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Environment\Exception\EnvException;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @var list<PendingPickupProbeMailAgent> Started mail instances whose claims end with the case */
    private array $mailAgents = [];

    /** Orphaned delivery to remove after its notification has gone. */
    private ?int $orphanNotificationId = null;

    /** @var list<int> Additional journal rows without parents, removed after the case */
    private array $extraDeliveryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        PickupChannelRegistry::$width = 2;
        PickupHilos::initBrowser();
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
        foreach ($this->extraDeliveryIds as $id) {
            Database::sqlRun('DELETE FROM hilos_notification_delivery WHERE id = ?', [$id]);
        }
        PickupChannelRegistry::$width = 2;
        PickupChannelRegistry::$failure = null;
        PickupChannelRegistry::$available = true;
        ChatHilos::initBrowser();
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
        $this->startMailAgent($agent);

        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testForeignShardLeavesTheRowUntouched(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('2', []);
        $this->startMailAgent($agent);

        $this->underAgent($agent, $agent->onTick(...));

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
        $this->startMailAgent($agent);

        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::FAILED, $this->row($notificationId)->status);
        self::assertSame(3, $this->row($notificationId)->attempts);
    }

    public function testDispatchSignalBeforeFirstTickDoesNotDuplicateTheAttempt(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($this->row($notificationId)->id, $notificationId, self::CHANNEL, 1)),
            'src',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        );

        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testFreshShardStartsUnderFreezeAndPicksUpAfterItLifts(): void
    {
        $notificationId = $this->pendingNotification();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);

        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(0, $agent->createdCount);
        self::assertSame(0, $this->row($notificationId)->attempts);

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(1, $this->row($notificationId)->attempts);
    }

    public function testOrphanedNotificationIsSettledByItsSavedOwner(): void
    {
        $notificationId = $this->pendingNotification(null);
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
        $agent = new PendingPickupProbeMailAgent((string)new PickupMailChannel()->ownerIndexFor($notificationId), []);
        $this->startMailAgent($agent);

        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(0, $agent->createdCount);
        self::assertSame(DeliveryStatus::FAILED, $this->row($notificationId)->status);
        self::assertSame('notification unavailable', $this->row($notificationId)->last_error);
    }

    public function testOldPoolFrameAndWrongCoordinatesCannotOpenATransport(): void
    {
        $oldOwner = new PendingPickupProbeMailAgent('1', []);
        $newOwner = new PendingPickupProbeMailAgent('2', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($oldOwner);
        $this->startMailAgent($newOwner);
        $this->underAgent($oldOwner, $oldOwner->onTick(...));
        $this->underAgent($newOwner, $newOwner->onTick(...));
        $notificationId = $this->pendingNotification(4);
        $id = $this->row($notificationId)->id;
        $wrong = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId + 1, self::CHANNEL, 1));
        $oldOwner->onSignalAgent($wrong, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($oldOwner, $oldOwner->onTick(...));
        self::assertSame(0, $oldOwner->createdCount);
        self::assertSame(0, $this->row($notificationId)->attempts);

        $oldFrame = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1));
        $oldOwner->onSignalAgent($oldFrame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        PickupChannelRegistry::$width = 3;
        $this->underAgent($oldOwner, $oldOwner->onTick(...));
        $newOwner->onSignalAgent($oldFrame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($newOwner, $newOwner->onTick(...));
        self::assertSame(0, $oldOwner->createdCount);
        self::assertSame(0, $newOwner->createdCount);
        self::assertSame(0, $this->row($notificationId)->attempts);

        $newOwner->onSignalAgent(new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 2)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($newOwner, $newOwner->onTick(...));
        $this->underAgent($newOwner, $newOwner->onTick(...));
        self::assertSame(1, $newOwner->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        self::assertSame(4, $this->row($notificationId)->routing_key);
    }

    public function testDeletedDeliveryIdCannotSettleItsReplacementAtTheSamePair(): void
    {
        $transport = new PickupMailTransport(MailSendOutcome::delivered());
        $agent = new PendingPickupProbeMailAgent('1', [$transport]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $oldFrame = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1));
        $agent->onSignalAgent($oldFrame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($agent, $agent->onTick(...));
        Hilos::$db->notificationDeliveries[$id]->actions->delete();
        $replacement = Hilos::$db->notificationDeliveries->actions->createPending($notificationId, self::CHANNEL, 0)->id;
        self::assertNotSame($id, $replacement);
        $agent->onSignalAgent($oldFrame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertTrue($transport->closed);
        self::assertSame(DeliveryStatus::PENDING, $this->row($notificationId)->status);
        self::assertSame(0, $this->row($notificationId)->attempts);
    }

    /** @return iterable<string, array{bool, bool, int}> Owner loss before pumping and immediately before each settlement */
    public static function ownershipLoss(): iterable
    {
        yield 'before pumping' => [false, false, 0];
        yield 'before success' => [true, false, 0];
        yield 'before retry' => [true, true, 0];
        yield 'before terminal failure' => [true, true, 2];
    }

    /**
     * @param bool $duringPump Whether the transport tick changes the current owner
     * @param bool $failure Whether the transport completes with a refusal
     * @param int $priorAttempts Attempts already persisted before this instance starts
     */
    #[DataProvider('ownershipLoss')]
    public function testInFlightOwnerLossClosesAndForgetsTheAttemptWithoutWriting(
        bool $duringPump,
        bool $failure,
        int $priorAttempts,
    ): void {
        $notificationId = $this->pendingNotification(4);
        $id = $this->row($notificationId)->id;
        $delivery = Hilos::$db->notificationDeliveries[$id]->getObject();
        $delivery->attempts = $priorAttempts;
        $delivery->sync();
        $transport = new PickupMailTransport(
            $failure ? MailSendOutcome::failed('provider refused', true) : MailSendOutcome::delivered(),
            $duringPump ? static function (): void { PickupChannelRegistry::$width = 3; } : null,
        );
        $agent = new PendingPickupProbeMailAgent('1', [$transport]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame($priorAttempts + 1, $this->row($notificationId)->attempts);
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();

        if (!$duringPump) {
            PickupChannelRegistry::$width = 3;
        }
        $this->underAgent($agent, $agent->onTick(...));

        self::assertTrue($transport->closed);
        self::assertSame($duringPump ? 1 : 0, $transport->ticks);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
        PickupChannelRegistry::$width = 2;
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
    }

    /** A queued old id is discarded before an attempt; only the replacement's own frame can start it. */
    public function testQueuedDeletedIdCannotStartItsReplacementAtTheSamePair(): void
    {
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $frame = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1));
        $agent->onSignalAgent($frame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        Hilos::$db->notificationDeliveries[$id]->actions->delete();
        $replacement = Hilos::$db->notificationDeliveries->actions->createPending($notificationId, self::CHANNEL, 0)->id;
        self::assertNotSame($id, $replacement);
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$replacement])->rows();
        $agent->onSignalAgent($frame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER);

        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(0, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$replacement])->rows());
        $agent->onSignalAgent(new AgentSignalData(new NotificationDeliverSignalData($replacement, $notificationId, self::CHANNEL, 1)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER);
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
            'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$replacement],
        )->rows());
    }

    /** A freeze restarts a progressed scan, including an earlier row whose saved key now belongs here. */
    public function testFreezeResetsTheAdoptionCursorAfterAFullForeignBatch(): void
    {
        $notificationId = $this->pendingNotification(3);
        for ($i = 0; $i < 99; $i++) {
            $this->extraDeliveryIds[] = Hilos::$db->notificationDeliveries->actions->createPending(
                self::RECIPIENT_ID + 1000000 + $i, self::CHANNEL, 1,
            )->id;
        }
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(0, $agent->createdCount);

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);
        $this->underAgent($agent, $agent->onTick(...));
        PickupChannelRegistry::$width = 3;
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1', 'routing_key' => '3']], Database::sql(
            'SELECT status, attempts, routing_key FROM hilos_notification_delivery WHERE id = ?', [$this->row($notificationId)->id],
        )->rows());
        foreach ($this->extraDeliveryIds as $id) {
            self::assertSame([['attempts' => '0', 'status' => DeliveryStatus::PENDING]], Database::sql(
                'SELECT attempts, status FROM hilos_notification_delivery WHERE id = ?', [$id],
            )->rows());
        }
    }

    public function testAdoptionAdvancesOneBatchPerTickPastForeignRows(): void
    {
        $notificationId = $this->pendingNotification();
        Hilos::$db->notificationDeliveries[$this->row($notificationId)->id]->actions->delete();
        for ($i = 0; $i < 100; $i++) {
            $this->extraDeliveryIds[] = Hilos::$db->notificationDeliveries->actions->createPending(
                self::RECIPIENT_ID + 1000000 + $i, self::CHANNEL, 1,
            )->id;
        }
        Hilos::$db->notificationDeliveries->actions->createPending($notificationId, self::CHANNEL, 0);
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(0, $agent->createdCount);
        self::assertSame(0, $this->row($notificationId)->attempts);
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame(DeliveryStatus::SENT, $this->row($notificationId)->status);
        foreach ($this->extraDeliveryIds as $id) {
            self::assertSame([['attempts' => '0', 'status' => DeliveryStatus::PENDING]], Database::sql(
                'SELECT attempts, status FROM hilos_notification_delivery WHERE id = ?', [$id],
            )->rows());
        }
    }

    /** @return iterable<string, array{bool}> Invalid width and unreadable environment configuration */
    public static function poolFailures(): iterable
    {
        yield 'invalid width' => [false];
        yield 'unreadable environment' => [true];
    }

    /** @param bool $unreadable Whether the descriptor raises the original environment failure */
    #[DataProvider('poolFailures')]
    public function testPendingConfigFailureKeepsTheRowOwedUntilConfigurationIsRepaired(bool $unreadable): void
    {
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->invalidatePool($unreadable);

        for ($i = 0; $i < 2; $i++) {
            $this->assertPoolFailure(fn () => $this->underAgent($agent, $agent->onTick(...)), $unreadable);
            self::assertSame(0, $agent->createdCount);
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
        }

        PickupChannelRegistry::$width = 2;
        PickupChannelRegistry::$failure = null;
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));

        self::assertSame(1, $agent->createdCount);
        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
            'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /** @param bool $unreadable Whether the descriptor raises the original environment failure */
    #[DataProvider('poolFailures')]
    public function testMatchingFrameReportsConfigFailureWithoutAdmittingWork(bool $unreadable): void
    {
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        $frame = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1));
        $this->invalidatePool($unreadable);

        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId + 1, self::CHANNEL, 1)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        $this->assertPoolFailure(fn () => $this->underAgent($agent, fn () => $agent->onSignalAgent(
            $frame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        )), $unreadable);
        self::assertSame(0, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());

        PickupChannelRegistry::$width = 2;
        PickupChannelRegistry::$failure = null;
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 2)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(0, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());

        $this->underAgent($agent, fn () => $agent->onSignalAgent($frame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER));
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
            'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /** @param bool $unreadable Whether the descriptor raises the original environment failure */
    #[DataProvider('poolFailures')]
    public function testQueuedConfigFailurePreservesWorkWithoutStartingAnAttempt(bool $unreadable): void
    {
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        $this->invalidatePool($unreadable);

        for ($i = 0; $i < 2; $i++) {
            $this->assertPoolFailure(fn () => $this->underAgent($agent, $agent->onTick(...)), $unreadable);
            self::assertSame(0, $agent->createdCount);
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
        }

        PickupChannelRegistry::$width = 2;
        PickupChannelRegistry::$failure = null;
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(1, $agent->createdCount);
        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
            'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /** @return iterable<string, array{bool, bool, bool, int}> Configuration failures at pump and settlement boundaries */
    public static function inFlightPoolFailures(): iterable
    {
        foreach ([false, true] as $unreadable) {
            foreach (self::ownershipLoss() as $name => $scenario) {
                yield ($unreadable ? 'environment ' : 'width ') . $name => [$unreadable, ...$scenario];
            }
        }
    }

    /**
     * @param bool $unreadable Whether the descriptor raises the original environment failure
     * @param bool $duringPump Whether the transport invalidates configuration before settlement
     * @param bool $failure Whether the transport completes with a refusal
     * @param int $priorAttempts Attempts persisted before the held send
     */
    #[DataProvider('inFlightPoolFailures')]
    public function testInFlightConfigFailurePreservesTheAttemptWithoutProgressOrSettlement(
        bool $unreadable,
        bool $duringPump,
        bool $failure,
        int $priorAttempts,
    ): void {
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $delivery = Hilos::$db->notificationDeliveries[$id]->getObject();
        $delivery->attempts = $priorAttempts;
        $delivery->sync();
        $invalidated = false;
        $transport = new PickupMailTransport(
            $failure ? MailSendOutcome::failed('provider refused', true) : MailSendOutcome::delivered(),
            $duringPump ? function () use ($unreadable, &$invalidated): void {
                if (!$invalidated) {
                    $this->invalidatePool($unreadable);
                    $invalidated = true;
                }
            } : null,
        );
        $agent = new PendingPickupProbeMailAgent('1', [$transport]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        if (!$duringPump) {
            $this->invalidatePool($unreadable);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->assertPoolFailure(fn () => $this->underAgent($agent, $agent->onTick(...)), $unreadable);
            self::assertSame(1, $agent->createdCount);
            self::assertSame($duringPump ? 1 : 0, $transport->ticks);
            self::assertFalse($transport->closed);
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
        }

        PickupChannelRegistry::$width = 2;
        PickupChannelRegistry::$failure = null;
        $this->underAgent($agent, $agent->onTick(...));
        self::assertTrue($transport->closed);
        self::assertSame(1, $agent->createdCount);
        self::assertSame([[
            'status' => $failure ? ($priorAttempts === 2 ? DeliveryStatus::FAILED : DeliveryStatus::PENDING) : DeliveryStatus::SENT,
            'attempts' => (string)($priorAttempts + 1),
            'last_error' => $failure ? 'provider refused' : null,
        ]], Database::sql('SELECT status, attempts, last_error FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
    }

    /** @return iterable<string, array{bool}> Cleanup paths that must also work with invalid configuration */
    public static function configFailureCleanup(): iterable
    {
        yield 'freeze' => [true];
        yield 'stop' => [false];
    }

    /** @param bool $freeze Whether to abandon through a silencing freeze or shutdown */
    #[DataProvider('configFailureCleanup')]
    public function testConfigFailureCleanupCannotSettleAReplacementDelivery(bool $freeze): void
    {
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $transport = new PickupMailTransport(MailSendOutcome::delivered());
        $agent = new PendingPickupProbeMailAgent('1', [$transport, new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $this->invalidatePool(false);
        $this->assertPoolFailure(fn () => $this->underAgent($agent, $agent->onTick(...)), false);
        Hilos::$db->notificationDeliveries[$id]->actions->delete();
        $replacement = Hilos::$db->notificationDeliveries->actions->createPending($notificationId, self::CHANNEL, 0)->id;
        self::assertNotSame($id, $replacement);
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$replacement])->rows();

        if ($freeze) {
            $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING);
            $this->underAgent($agent, $agent->onTick(...));
        } else {
            $this->underAgent($agent, $agent->onStop(...));
        }
        self::assertTrue($transport->closed);
        self::assertSame(0, $transport->ticks);
        self::assertSame(1, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$replacement])->rows());
        if (!$freeze) {
            return;
        }

        PickupChannelRegistry::$width = 2;
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($replacement, $notificationId, self::CHANNEL, 1)),
            'src', HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        $this->underAgent($agent, $agent->onTick(...));
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(2, $agent->createdCount);
        self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
            'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$replacement],
        )->rows());
    }

    public function testValidConfigurationWithoutAnOwnerStillDropsTheFrame(): void
    {
        $agent = new PendingPickupProbeMailAgent('1', [new PickupMailTransport(MailSendOutcome::delivered())]);
        $this->startMailAgent($agent);
        $this->underAgent($agent, $agent->onTick(...));
        $notificationId = $this->pendingNotification();
        $id = $this->row($notificationId)->id;
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        $frame = new AgentSignalData(new NotificationDeliverSignalData($id, $notificationId, self::CHANNEL, 1));
        PickupChannelRegistry::$available = false;
        $this->underAgent($agent, fn () => $agent->onSignalAgent($frame, 'src', HilosSignalConstants::HILOS_MAIL_DELIVER));
        PickupChannelRegistry::$available = true;
        $this->underAgent($agent, $agent->onTick(...));
        self::assertSame(0, $agent->createdCount);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
    }

    /** @param bool $unreadable Whether to raise an environment error before the reducer sees the invalid width */
    private function invalidatePool(bool $unreadable): void
    {
        PickupChannelRegistry::$width = 0;
        PickupChannelRegistry::$failure = $unreadable ? new EnvException('fixture pool configuration unavailable') : null;
    }

    /**
     * @param Closure(): void $call Consumer entry point expected to expose the original configuration failure
     * @param bool $unreadable Whether the environment error rather than the reducer error must escape
     */
    private function assertPoolFailure(Closure $call, bool $unreadable): void
    {
        try {
            $call();
        } catch (EnvException | InvalidArgumentException $failure) {
            if ($unreadable) {
                self::assertSame(PickupChannelRegistry::$failure, $failure);
            } else {
                self::assertInstanceOf(InvalidArgumentException::class, $failure);
            }

            return;
        }
        self::fail('the consumer must expose the pool configuration failure');
    }

    /** @param PendingPickupProbeMailAgent $agent Instance started with the harness's real declaration claims */
    private function startMailAgent(PendingPickupProbeMailAgent $agent): void
    {
        $this->startAgent($agent);
        $this->mailAgents[] = $agent;
    }

    /**
     * Emits one notification before adding an address, then creates its pending email row.
     *
     * @param ?int $routingKey Saved routing dimension, null to exercise the legacy fallback
     * @return int Notification id
     */
    private function pendingNotification(?int $routingKey = 0): int
    {
        $this->deleteRecipientRows();
        $notificationId = $this->notificationsLibrary()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'Mail pending pickup',
        ));
        Hilos::$db->identities->createMagicLinkIdentity(self::RECIPIENT_ID, RandomHelper::hex(8) . '@example.test');
        $this->deliveries()->createPending($notificationId, self::CHANNEL, $routingKey);

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
            StateProtectedModeRuntime::enteredFromBrowser => false,
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
    public bool $closed = false;

    public int $ticks = 0;

    private bool $busy = false;

    /**
     * @param MailSendOutcome $outcome Scripted settlement
     * @param ?Closure(): void $onTick Deterministic change after the pre-pump identity check
     */
    public function __construct(private readonly MailSendOutcome $outcome, private readonly ?Closure $onTick = null)
    {
    }

    public function start(EmailMessage $message, float $nowMs): void
    {
        $this->busy = true;
    }

    /** @param float $nowMs Current time, unused by this scripted transport */
    public function tick(float $nowMs): void
    {
        $this->ticks++;
        if ($this->onTick !== null) {
            ($this->onTick)();
        }
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
        $this->closed = true;
        $this->busy = false;
    }
}

/** A test-local configured width exercises the same reducer and route without changing a production timer. */
final class PickupChannelRegistry extends DeliveryChannelRegistry
{
    public static int $width = 2;

    public static ?EnvException $failure = null;

    public static bool $available = true;

    /** @return array<string, PickupMailChannel> Configured descriptor, or no registered owner */
    protected static function channels(): array
    {
        return self::$available ? [MailDeliveryChannel::NAME => new PickupMailChannel()] : [];
    }
}

final class PickupMailChannel extends MailDeliveryChannel
{
    /** @return int Configured width, raising the scripted environment failure when requested */
    public function poolSize(): int
    {
        if (PickupChannelRegistry::$failure !== null) {
            throw PickupChannelRegistry::$failure;
        }

        return PickupChannelRegistry::$width;
    }
}

abstract class PickupHilos extends Hilos
{
    public const array AGENTS = ChatHilos::AGENTS;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = PickupChannelRegistry::class;
}

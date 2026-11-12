<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The owner of a delivery row takes a failed delivery back for an admin and answers the page itself.
 *
 * The email shard's real retry branch receives the library's request the way its worker hands it
 * over, resets the row through its own mounted item action and queues it; the following ticks
 * drive it through attempts handed out already settled, with no network and no clock. Every
 * write goes through the ORM doors, so the rollback puts memory back and no cold reset is owed.
 */
#[IntegrationTest]
final class DeliveryRetryOwnerAnswerIntegrationTest extends DeliveryRetryOwnerIntegrationTestCase
{
    private const string DIFFERENT_OWNER = 'Delivery retry reached a different channel or shard';

    /** @return iterable<string, array{?string}> How the taken-back delivery's next attempt ends */
    public static function nextAttempts(): iterable
    {
        yield 'failed, then pending, then sent' => [null];
        yield 'failed, then pending, then failed' => ['mailbox full'];
    }

    /**
     * The reset is the owner's, the next send starts on its tick, and the journal follows every step live.
     *
     * @param ?string $error Failure of the next attempt, or null when it is delivered
     */
    #[DataProvider('nextAttempts')]
    public function testTakenBackDeliveryIsDrivenOnByTheOwnersTicks(?string $error): void
    {
        $id = $this->failedDelivery();
        $agent = new RetryOwnerMailAgent('1');
        $this->tick($agent);
        self::assertSame([], self::queued($agent), 'A failed row is not adopted');
        $ask = $this->retryAsk($id);
        $this->changes->list = [];

        $this->deliverRetry($agent, $ask);

        $this->assertAnsweredOnce($ask, null);
        self::assertSame([$id], self::queued($agent));
        self::assertSame([], $agent->started, 'The handler opens no transport');
        self::assertSame([[DeliveryStatus::PENDING, '0', null, null]], $this->savedRow($id));
        self::assertCount(1, $this->changes->list);
        self::assertSame([self::ACCEPT_KEY, self::REQUEST_ID], [$this->changes->list[0]->origin, $this->changes->list[0]->originRequestId]);
        $this->assertJournal($this->changes->list[0], $id, DeliveryStatus::PENDING, 0, null);

        $agent->script = [new SettledRetryAttempt($error)];
        $this->tick($agent);
        self::assertSame([[RetryOwnerMailChannel::ADDRESS, $this->notificationId]], $agent->started);
        self::assertSame([[DeliveryStatus::PENDING, '1', null, null]], $this->savedRow($id));
        self::assertCount(2, $this->changes->list);
        $this->assertJournal($this->changes->list[1], $id, DeliveryStatus::PENDING, 1, null);
        $this->tick($agent);

        $status = $error === null ? DeliveryStatus::SENT : DeliveryStatus::FAILED;
        $saved = $this->savedRow($id);
        self::assertSame([$status, '1', $error], array_slice($saved[0], 0, 3));
        self::assertSame($error === null, $saved[0][3] !== null);
        self::assertSame([], self::queued($agent));
        self::assertCount(3, $this->changes->list);
        $this->assertJournal($this->changes->list[2], $id, $status, 1, $error);
    }

    /** @return iterable<string, array{?string, bool}> Tracked and untracked submits, taken back or refused */
    public static function submits(): iterable
    {
        yield 'tracked, taken back' => [self::REQUEST_ID, true];
        yield 'untracked, taken back' => [null, true];
        yield 'tracked, refused' => [self::REQUEST_ID, false];
        yield 'untracked, refused' => [null, false];
    }

    /**
     * The one answer goes under the page's reply name and carries the ask's own keys; the write carries them as its origin.
     *
     * @param ?string $requestId Request id of a tracked submit, or null for an untracked one
     * @param bool $failed Whether the row is failed, or still pending and so refused
     */
    #[DataProvider('submits')]
    public function testOwnerAnswersTheAsksOwnSubmitOnce(?string $requestId, bool $failed): void
    {
        $id = $failed
            ? $this->failedDelivery()
            : Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
        $agent = new RetryOwnerMailAgent('1');
        $ask = $this->retryAsk($id, $requestId);
        $before = $this->savedRow($id);
        $this->changes->list = [];

        $this->deliverRetry($agent, $ask);

        $answer = $this->assertAnsweredOnce($ask, $failed ? null : 'Only failed deliveries can be retried');
        self::assertSame([null, null], [$answer->errorType, $answer->errorDetail]);
        if ($failed) {
            self::assertCount(1, $this->changes->list);
            self::assertSame([self::ACCEPT_KEY, $requestId], [$this->changes->list[0]->origin, $this->changes->list[0]->originRequestId]);
            self::assertSame([$id], self::queued($agent));
        } else {
            self::assertSame([], $this->changes->list);
            self::assertSame($before, $this->savedRow($id));
            self::assertSame([], self::queued($agent));
        }
        self::assertSame([], $agent->errors);
    }

    /** @return iterable<string, array{string}> Ways the request no longer meets its row's current owner */
    public static function misroutedRequests(): iterable
    {
        yield 'the request names another channel' => ['channel'];
        yield 'the row is saved on another channel' => ['row channel'];
        yield 'the request names another notification' => ['notification'];
        yield 'the request names another routing key' => ['routing key'];
        yield 'the request carries another shard key' => ['shard key'];
        yield 'the row belongs to another shard' => ['other shard'];
        yield 'the pool shrank after the request was routed' => ['pool reduced'];
    }

    /**
     * A request is checked against the saved coordinates and the current pool before anything is written.
     *
     * @param string $case Which coordinate or shard disagrees
     */
    #[DataProvider('misroutedRequests')]
    public function testRequestThatReachedAnotherChannelOrShardIsRefusedWithoutChanges(string $case): void
    {
        $shard = match ($case) {
            'other shard' => '2',
            'pool reduced' => '3',
            default => '1',
        };
        $agent = new RetryOwnerMailAgent($shard);
        if ($case === 'row channel') {
            $id = $this->failedDelivery(SmsDeliveryChannel::NAME, owner: OwnershipSmsAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '1');
        } elseif ($case === 'pool reduced') {
            DeliveryOwnershipChannels::$width = 3;
            $id = $this->failedDelivery(routingKey: 2, owner: MailDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '3');
        } else {
            $id = $this->failedDelivery();
        }
        $ask = $this->retryAsk($id);
        $ask = match ($case) {
            'channel' => self::changedAsk($ask, [NotificationDeliveryRetrySignalData::channel => SmsDeliveryChannel::NAME]),
            'notification' => self::changedAsk($ask, [NotificationDeliveryRetrySignalData::notificationId => $this->notificationId + 1]),
            'routing key' => self::changedAsk($ask, [NotificationDeliveryRetrySignalData::routingKey => 2]),
            'shard key', 'other shard' => self::changedAsk($ask, [NotificationDeliveryRetrySignalData::shardKey => 2]),
            default => $ask,
        };
        if ($case === 'pool reduced') {
            self::assertSame(3, $ask->shardKey);
            DeliveryOwnershipChannels::$width = 2;
        }
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->changes->list = [];

        $this->deliverRetry($agent, $ask);

        $this->assertAnsweredOnce($ask, self::DIFFERENT_OWNER);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], $this->changes->list);
        self::assertSame([], self::queued($agent));
        self::assertSame([], $agent->errors);
    }

    /** @return iterable<string, array{string}> A write that fails, an announcement that fails, and the freeze */
    public static function blockedResets(): iterable
    {
        yield 'the write is refused' => ['write'];
        yield 'the announcement fails' => ['announcement'];
        yield 'a silencing freeze holds the node' => ['freeze'];
    }

    /**
     * Whatever stops the reset, the row stays as saved, nothing is queued and the page gets one answer.
     *
     * @param string $case What stops the reset
     */
    #[DataProvider('blockedResets')]
    public function testBlockedResetIsRefusedOnceWithoutChanges(string $case): void
    {
        $id = $this->failedDelivery();
        $agent = new RetryOwnerMailAgent('1');
        $ask = $this->retryAsk($id);
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->changes->list = [];
        $grant = null;
        if ($case === 'write') {
            $grant = TruthSourceOperations::of(TruthSourceOperation::Remove);
        } elseif ($case === 'announcement') {
            SourceChangeBus::subscribe(new DeliveryRetryFailingMirror((string)$id));
        } else {
            self::freeze();
        }

        $this->deliverRetry($agent, $ask, $grant);

        $answer = $this->assertAnsweredOnce(
            $ask,
            $case === 'freeze' ? 'Delivery retry is unavailable during maintenance' : SignalConstants::ACTION_FAILED_REASON,
        );
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], self::queued($agent));
        self::assertSame(DeliveryStatus::FAILED, Hilos::$db->notificationDeliveries[$id]->status);
        switch ($case) {
            case 'write':
                self::assertSame('WriteNotAllowedException', $answer->errorType);
                self::assertSame([], $this->changes->list);
                self::assertCount(1, $agent->errors);
                self::assertStringStartsWith("Delivery retry failed for #{$id}: ", $agent->errors[0]);
                break;
            case 'announcement':
                self::assertSame('SourceChangeSubscriberException', $answer->errorType);
                self::assertSame([TableMutationType::Update, TableMutationType::Update], array_map(
                    static fn (SourceChange $change): TableMutationType => $change->mutationType, $this->changes->list,
                ), 'The mirrors hear the reset and then the rollback taking it back');
                self::assertSame(DeliveryStatus::FAILED, $this->changes->list[1]->row[EntityNotificationDelivery::status]);
                self::assertCount(1, $agent->errors);
                break;
            case 'freeze':
                self::assertSame([null, null], [$answer->errorType, $answer->errorDetail]);
                self::assertSame([], $this->changes->list);
                self::assertSame([], $agent->errors);
                break;
        }
    }

    /** Another payload under the retry name stays a protocol error: no write and no answer. */
    public function testAnotherPayloadUnderTheRetryNameIsAProtocolError(): void
    {
        $id = $this->failedDelivery();
        $agent = new RetryOwnerMailAgent('1');
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->claim($agent->getId());
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), fn () => $agent->onSignalAgent(
                new AgentSignalData(new NotificationDeliverSignalData($id, $this->notificationId, MailDeliveryChannel::NAME, 1)),
                '',
                HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY,
            ));
            self::fail('A deliver frame under the retry name must be refused');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertStringContainsString(NotificationDeliveryRetrySignalData::class, $refusal->getMessage());
        }

        self::assertSame([], self::agentFrames());
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], self::queued($agent));
    }

    /**
     * @param int $id Delivery primary key
     * @return list<list<?string>> Status, attempts, last error and delivered moment, read past the ORM
     */
    private function savedRow(int $id): array
    {
        return array_map('array_values', Database::sql(
            'SELECT status, attempts, last_error, delivered_at FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /**
     * @param SourceChange $change Announcement the delivery journal hears live
     * @param int $id Delivery the journal row stands for
     * @param string $status Status the journal row must show
     * @param int $attempts Attempts the journal row must show
     * @param ?string $error Last error the journal row must show
     */
    private function assertJournal(SourceChange $change, int $id, string $status, int $attempts, ?string $error): void
    {
        $mutation = new HilosNotificationDeliveriesTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        $row = $mutation->row;
        self::assertInstanceOf(HilosNotificationDeliveryTableRow::class, $row);
        self::assertSame([$id, $status, $attempts, $error], [$row->rowKey, $row->status, $row->attempts, $row->lastError]);
    }
}

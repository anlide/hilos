<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Saves the notification behind every delivery, records the delivery announcements, and hands
 * the email shard owner the library's retry request the way its worker does: under the owner's
 * own frame, with the asker's accept key and request id as the origin of the write.
 */
abstract class DeliveryRetryOwnerIntegrationTestCase extends NotificationDeliveryOwnershipIntegrationTestCase
{
    protected const string ACCEPT_KEY = 'retry-accept-key';
    protected const string REQUEST_ID = 'retry-request';

    protected int $notificationId = 0;
    protected DeliveryRetryChanges $changes;

    /** Gives the owner's answer a queue to land in; the harness restores the previous router. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$sr = new SignalRouter();
    }

    /** Saves the notification behind every delivery and starts recording delivery announcements. */
    protected function setUpInTransaction(): void
    {
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1910, 'Retry recipient')");
        Database::sql(
            'INSERT INTO hilos_notification (user_id, type, title, created_at) VALUES (1910, ?, ?, 1)',
            ['test.retry', 'Retry notification'],
        );
        $this->notificationId = (int)Database::sql('SELECT MAX(id) AS id FROM hilos_notification')->firstRow()['id'];
        $this->changes = new DeliveryRetryChanges();
        SourceChangeBus::subscribe($this->changes);
    }

    /**
     * @param string $channel Channel the delivery is saved on
     * @param int $routingKey Saved key selecting the shard
     * @param ?string $owner Shard that fails the delivery through its own item actions, the email shard 1 when null
     * @return int A delivery its owner failed through its own item actions
     */
    protected function failedDelivery(string $channel = MailDeliveryChannel::NAME, int $routingKey = 0, ?string $owner = null): int
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, $channel, $routingKey)->id;
        $this->asClaimant($owner ?? self::mailOwner(), static function () use ($id): void {
            Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt();
            Hilos::$db->notificationDeliveries[$id]->actions->recordFailure('smtp down', 1);
        });

        return $id;
    }

    /**
     * The library's request as it would address the current owner: the saved coordinates and their shard now.
     *
     * @param int $id Saved delivery the admin picked
     * @param ?string $requestId Request id of a tracked submit, or null for an untracked one
     * @return NotificationDeliveryRetrySignalData The page's ask, carried to the owner
     */
    protected function retryAsk(int $id, ?string $requestId = self::REQUEST_ID): NotificationDeliveryRetrySignalData
    {
        $saved = Hilos::$db->notificationDeliveries->readIdentity($id);
        self::assertNotNull($saved);
        $owner = DeliveryOwnerResolver::forDelivery($saved);
        self::assertNotNull($owner);

        return new NotificationDeliveryRetrySignalData(
            deliveryId: $saved->deliveryId,
            notificationId: $saved->notificationId,
            channel: $saved->channel,
            routingKey: $saved->routingKey,
            shardKey: $owner->index === null ? null : (int)$owner->index,
            replySignal: HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: $requestId,
            action: HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            successMessage: null,
        );
    }

    /**
     * @param NotificationDeliveryRetrySignalData $ask Request to copy
     * @param array<string, int|string|null> $change Payload fields to replace
     * @return NotificationDeliveryRetrySignalData The same request with those fields replaced
     */
    protected static function changedAsk(NotificationDeliveryRetrySignalData $ask, array $change): NotificationDeliveryRetrySignalData
    {
        return NotificationDeliveryRetrySignalData::fromArray(array_replace($ask->toArray(), $change));
    }

    /**
     * Runs the owner's handler as its worker does on receipt of an ask: the owner's frame, the asker as origin.
     *
     * @param AbstractDeliveryChannelAgent $agent Channel/shard instance receiving the request
     * @param NotificationDeliveryRetrySignalData $ask Request routed to it
     * @param ?TruthSourceOperations $grant Narrower operations the owner's claim carries, the channel's own when null
     */
    protected function deliverRetry(
        AbstractDeliveryChannelAgent $agent,
        NotificationDeliveryRetrySignalData $ask,
        ?TruthSourceOperations $grant = null,
    ): void {
        $this->claim($agent->getId());
        if ($grant !== null) {
            TruthSourceRegistry::register(HilosDbContext::notificationDeliveries, TruthSourceKeys::all(), $agent->getId(), $grant);
        }
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => ExecutionContext::withOrigin(
            $ask->acceptKey,
            $ask->requestId,
            static fn () => $agent->onSignalAgent(new AgentSignalData($ask), '', HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY),
        ));
    }

    /** @param AbstractDeliveryChannelAgent $agent Channel/shard instance to advance one tick */
    protected function tick(AbstractDeliveryChannelAgent $agent): void
    {
        $this->asClaimant($agent->getId(), $agent->onTick(...));
    }

    /**
     * @param NotificationDeliveryRetrySignalData $ask The request the answer must belong to
     * @param ?string $error Refusal the page must read, or null for a delivery taken back
     * @return HandoverAnswerSignalData The only agent frame queued since the last look
     */
    protected function assertAnsweredOnce(NotificationDeliveryRetrySignalData $ask, ?string $error): HandoverAnswerSignalData
    {
        $answers = self::agentFrames();
        self::assertCount(1, $answers, 'The owner answers every request exactly once');
        [$name, $sender, $answer] = $answers[0];
        self::assertSame([$ask->replySignal, MailDeliveryChannelAgent::AGENT_TYPE], [$name, $sender]);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer);
        self::assertSame(
            [$ask->acceptKey, $ask->requestId, $ask->action, $ask->successMessage, $error],
            [$answer->acceptKey, $answer->requestId, $answer->action, $answer->successMessage, $answer->error],
        );

        return $answer;
    }

    /** @return list<array{string, string, SignalDataInterface}> Name, sender type and payload of every agent frame queued since the last look */
    protected static function agentFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === SignalTypeConstants::AGENT_SIGNAL) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                $frames[] = [$signal->signalName->getName(), $signal->signalSource->getType(), $signal->data->data];
            }
        }

        return $frames;
    }

    /** Puts this node under the freeze that silences the delivery writers, through the regular mount. */
    protected static function freeze(): void
    {
        Hilos::$rt = new DeliveryRetryRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }

    /**
     * @param AbstractDeliveryChannelAgent $agent Channel/shard instance
     * @return list<int> Delivery ids waiting in its private queue
     */
    protected static function queued(AbstractDeliveryChannelAgent $agent): array
    {
        return array_keys(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'ops')->getValue($agent));
    }

    /**
     * @param AbstractDeliveryChannelAgent $agent Channel/shard instance
     * @return list<int> Delivery ids it still holds back until a retry backoff has passed, whatever the clock says
     */
    protected static function backingOff(AbstractDeliveryChannelAgent $agent): array
    {
        return array_keys(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'nextAttemptMs')->getValue($agent));
    }
}

/** Records the delivery announcements the immediate mirrors hear. */
final class DeliveryRetryChanges implements SourceMirrorSubscriberInterface
{
    /** @var list<SourceChange> */
    public array $list = [];

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::notificationDeliveries) {
            $this->list[] = $change;
        }
    }
}

/** The email shard with its transport replaced by attempts handed out ready: no network and no clock. */
final class RetryOwnerMailAgent extends MailDeliveryChannelAgent
{
    /** @var list<DeliveryAttempt> Attempts handed out in order, one per started send */
    public array $script = [];

    /** @var list<array{string, ?int}> Recipient address and notification id of every started send */
    public array $started = [];

    /** @var list<string> Errors the shard wrote to its log */
    public array $errors = [];

    public int $attemptCeiling = 1;

    /** @return AbstractDeliveryChannel The email descriptor with a fixed recipient address */
    protected function channel(): AbstractDeliveryChannel
    {
        return new RetryOwnerMailChannel();
    }

    /**
     * @param string $address Resolved recipient address
     * @param ObjectNotification $notification Notification being delivered
     * @return DeliveryAttempt The next scripted attempt
     */
    protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        $this->started[] = [$address, $notification->id];

        return array_shift($this->script) ?? TestCase::fail('No scripted attempt is left for this send');
    }

    /** @return int Attempt ceiling chosen by the case */
    protected function maxAttempts(): int
    {
        return $this->attemptCeiling;
    }

    /** @return int One send at a time */
    protected function maxConcurrent(): int
    {
        return 1;
    }

    /** @param string $message Error the shard would log */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

final class RetryOwnerMailChannel extends MailDeliveryChannel
{
    public const string ADDRESS = 'retry-recipient@example.com';

    /**
     * @param int $userId Recipient user id, unused
     * @return string Fixed recipient address, without an identity table
     */
    public function resolveAddress(int $userId): string
    {
        return self::ADDRESS;
    }
}

/** A send already settled - or still busy - when the shard receives it. */
final class SettledRetryAttempt implements DeliveryAttempt
{
    public bool $closed = false;

    /**
     * @param ?string $error Failure detail, or null for a delivered send
     * @param bool $busy Whether the send never settles
     */
    public function __construct(private readonly ?string $error, private readonly bool $busy = false)
    {
    }

    /** @param float $nowMs Current time, unused */
    public function tick(float $nowMs): void
    {
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isDelivered(): bool
    {
        return $this->error === null;
    }

    public function errorDetail(): ?string
    {
        return $this->error;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

/** A mirror whose repair of one delivery's first announcement fails, the way a broken journal mirror would. */
final class DeliveryRetryFailingMirror implements SourceMirrorSubscriberInterface
{
    private bool $failed = false;

    /** @param string $deliveryId Delivery whose first announcement fails */
    public function __construct(private readonly string $deliveryId)
    {
    }

    /**
     * @param SourceChange $change Immediate source fact
     * @param SourceChangeProvenance $provenance Source path, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$this->failed && $change->sourceKey === HilosDbContext::notificationDeliveries && $change->sourceId === $this->deliveryId) {
            $this->failed = true;
            throw new RuntimeException('The journal mirror could not take the change');
        }
    }
}

/** The framework mount supplies the node's freeze alias; no project collections are required. */
final class DeliveryRetryRtContext extends RtContext
{
    /** Leaves project state empty so only the mounted protected-mode singleton is read. */
    public function configure(): void
    {
    }
}

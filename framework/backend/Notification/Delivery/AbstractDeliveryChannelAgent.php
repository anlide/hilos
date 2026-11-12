<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use DateTimeImmutable;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\DbWriterPartition;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Sms\Exception\SmsTemplateNotInCatalogException;
use Hilos\Sms\Exception\SmsTemplateParamMissingException;
use Hilos\WiringRefusal;
use Throwable;

/**
 * AbstractDeliveryChannelAgent - the async owner of one channel's deliveries (HIL-196).
 *
 * The tick-loop half of a delivery channel (email 197, sms 285, push 199, telegram
 * 198), modeled on {@see AbstractOAuthAgent}. It owns the
 * whole pipeline — intake, the in-flight pool with a concurrency ceiling, bounded
 * retries, and the hilos_notification_delivery row bookkeeping — so a channel leaf
 * supplies only its descriptor ({@see channel()}) and its transport wrapped as a
 * {@see DeliveryAttempt} ({@see createAttempt()}). Network I/O never blocks the
 * worker loop: each attempt is pumped one non-blocking step per tick.
 *
 * The dispatcher ({@see NotificationDispatcher}) hands one delivery over the
 * channel's deliver agent signal (routed to this agent by the leaf's AGENT_SIGNALS,
 * with an INDEX_FIELD of `shardKey` when the channel is pooled). The agent reloads
 * the notification and its pending delivery row, resolves the address, and drives
 * the send to `sent` or, after {@see maxAttempts()} tries, `failed`.
 *
 * A channel leaf registers a concrete subclass under its own agent type in
 * Hilos::AGENTS, as a monopolistic singleton or an indexed pool, via its daemon
 * ({@see AbstractDeliveryChannelAgentDaemon}).
 *
 * The library ({@see AbstractNotificationsLibraryAgent}) creates delivery rows and passes an admin
 * retry on to their owner. Each channel or shard updates its own attempts, resets its own failed row
 * for that retry and removes its expired terminal rows. The claim is {@see self::OWNS_DB} and is
 * merged down every subclass.
 *
 * Under a freeze that silences the writers the roster walk leaves running
 * ({@see ProtectedModeRuntime::silencesUnstoppedWriters()}) the pipeline drops everything it
 * holds and refuses new deliveries, because nothing of a database being replaced may be acted
 * on in its successor; the rows stay pending. Only the mail pool meets this in practice - the
 * freeze stops the other channels outright (HIL-1060).
 * The shard picks up its pending rows on the first tick the freeze no longer silences,
 * and every new instance does the same after a worker death, daemon restart, or move to
 * another node. This is at-least-once delivery: a process that dies after the provider
 * accepts a send but before the row is settled can send it again (HIL-1135).
 */
abstract class AbstractDeliveryChannelAgent extends AbstractAgent
{
    /**
     * @var list<string> Notifications reloaded for delivery and settings read when a retention pass starts.
     *     The delivery journal is omitted because {@see self::OWNS_DB} already supplies its owner's reader interest.
     */
    public const array READS_DB = [HilosDbContext::notifications, HilosDbContext::settings];

    /**
     * Updates and removes this channel/shard's rows; the library creates them.
     * The per-item partition proof constrains both operations to the saved owner coordinates,
     * the reset of a row an admin retries included; the library holds no Update beside it.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [HilosDbContext::notificationDeliveries => TruthSourceOperation::BY_KIND];

    /** Fresh saved channel/shard coordinates constrain every item write. */
    public const ?DbWriterPartition DB_WRITER_PARTITION = DbWriterPartition::NotificationChannelShard;

    /** Maximum number of pending identities inspected in one tick. */
    private const int PENDING_ADOPTION_BATCH_SIZE = 100;

    /** Default ceiling on concurrently pumped attempts (outbound I/O, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default attempt ceiling after which a delivery fails terminally. */
    private const int DEFAULT_MAX_ATTEMPTS = 3;

    /** Base retry backoff in milliseconds, doubled per prior attempt. */
    private const float RETRY_BACKOFF_BASE_MS = 1000.0;

    /** Daily delivery-journal retention schedule on the project clock. */
    private const string RETENTION_SCHEDULE = '20 3 * * *';

    /** Existing framework rule name, shared by the channel owners. */
    private const string RETENTION_RULE = CronRule::FRAMEWORK_NAME_PREFIX . 'delivery_log_prune';

    /** Bounds per-row deletion work to keep the tick under 0.1 seconds beside live sends. */
    private const int RETENTION_PAGE_SIZE = 25;

    /** @var array<int, NotificationDeliveryIdentity> Immutable ops awaiting a start or retry, keyed by delivery id. */
    private array $ops = [];

    /** @var array<int, DeliveryAttempt> In-flight attempts, keyed by delivery id. */
    private array $inFlight = [];

    /** @var array<int, float> Earliest next-attempt time (ms) per op, for retry backoff. */
    private array $nextAttemptMs = [];

    /** Whether this shard owes a read of its pending rows, from birth or after a silencing freeze (HIL-1135). */
    private bool $rereadOwed = true;

    /** Last inspected delivery id, including rows owned by other shards. */
    private int $pendingCursor = 0;

    /** Null when the project has no delivery-journal feature. */
    private ?CronRule $retentionRule = null;

    /** Exclusive age boundary for this pass; null means no pass is running. */
    private ?int $retentionCutoffMs = null;

    /** Creation moment of the last inspected row, including foreign shards. */
    private int $retentionAfterCreatedAt = 0;

    /** Delivery id breaking ties at the inspected creation moment. */
    private int $retentionAfterId = 0;

    /** Number of rows this instance has removed in the current pass. */
    private int $retentionRemoved = 0;

    /**
     * Returns this agent's channel descriptor (identity, address, signal seams).
     *
     * @return AbstractDeliveryChannel The channel this agent delivers
     */
    abstract protected function channel(): AbstractDeliveryChannel;

    /**
     * Wraps the channel transport for one send as a non-blocking attempt.
     *
     * @param string $address Resolved recipient address for this channel
     * @param ObjectNotification $notification The notification to deliver
     * @return DeliveryAttempt The started, non-blocking attempt
     * @throws DatabaseException When the channel reads the delivery payload from the database
     * @throws MailBusyException When the mail transport is already carrying a message
     * @throws MailTemplateNotInCatalogException When the mail template key is not in the catalog
     * @throws MailTemplateParamMissingException When a param the mail template requires is absent
     * @throws SmsTemplateNotInCatalogException When the SMS template key is not in the catalog
     * @throws SmsTemplateParamMissingException When a param the SMS template requires is absent
     * @throws DbCollectionNotReadableException When nothing here reads what the channel needs, or its readiness is on its way
     */
    abstract protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt;

    /**
     * A channel updates its attempts and removes its expired terminal delivery rows.
     * Creation stays with the notifications library; both writes retain the channel/shard proof.
     *
     * @return TruthSourceOperations Updating and removing this owner's rows
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Update, TruthSourceOperation::Remove);
    }

    /**
     * Registers retention only for projects with the delivery journal enabled.
     *
     * @throws CronRuleNameException When the retention rule name does not match framework origin
     */
    public function onStart(): void
    {
        if (Hilos::hasFeature(HilosFeature::NOTIFICATION_DELIVERY)) {
            $this->retentionRule = new CronRule(self::RETENTION_RULE, self::RETENTION_SCHEDULE, CronRuleOrigin::FRAMEWORK);
        }
    }

    /**
     * @return int Maximum number of attempts pumped concurrently
     */
    protected function maxConcurrent(): int
    {
        return self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * @return int Attempt ceiling after which a delivery fails terminally
     */
    protected function maxAttempts(): int
    {
        return self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Handles this channel's durable intake and its retry request, and ignores unrelated signal names.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender identity, unused
     * @param string $name Routed agent-signal name
     * @throws InvalidAgentSignalPayloadException When the deliver or retry name carries another payload type
     * @throws InvalidArgumentException When the answer to a retry request cannot be named or queued
     * @throws HilosException When saved coordinates cannot be read or the pool configuration is invalid
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case $this->channel()->deliverSignalName():
                $payload = $data->data;
                if (!$payload instanceof NotificationDeliverSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, NotificationDeliverSignalData::class, $payload);
                }
                $this->admitDelivery($payload);
                break;

            case $this->channel()->retrySignalName():
                $payload = $data->data;
                if (!$payload instanceof NotificationDeliveryRetrySignalData) {
                    throw new InvalidAgentSignalPayloadException($name, NotificationDeliveryRetrySignalData::class, $payload);
                }
                $this->retryDelivery($payload);
        }
    }

    /**
     * Pumps in-flight and queued sends, then advances one retention page.
     *
     * Under a freeze that silences this pipeline nothing of it runs: the first such tick drops
     * what is held and says how much, the later ones find nothing, and neither touches the
     * database.
     *
     * @throws HilosException When pool configuration, delivery bookkeeping or the channel transport fails
     */
    public function onTick(): void
    {
        if ($this->freezeSilencesDeliveries()) {
            $dropped = $this->abandonDeliveries();
            $this->rereadOwed = true;
            $this->retentionCutoffMs = null;
            if ($dropped > 0) {
                $this->logAgentWarning(
                    "protected mode holds the node: {$dropped} durable deliveries dropped, their rows stay pending",
                );
            }

            return;
        }

        if ($this->rereadOwed) {
            $this->adoptPendingRows();
        }

        $nowMs = microtime(true) * TimeConstants::MS_PER_SECOND;
        $this->pumpInFlight($nowMs);
        $this->startQueued($nowMs);
        $this->tickRetention();
    }

    /**
     * Abandons every in-flight attempt on shutdown, releasing its transport.
     */
    public function onStop(): void
    {
        $this->abandonDeliveries();
    }

    /** @return list<CronRule> Current delivery-journal retention schedule */
    protected function cronRules(): array
    {
        return $this->retentionRule === null ? [] : [$this->retentionRule];
    }

    /** Retention errors abandon this pass without interrupting the delivery pipeline. */
    private function tickRetention(): void
    {
        try {
            if ($this->retentionRule?->shouldRun() === true) {
                $this->beginRetentionPass();
            }
            if ($this->retentionCutoffMs !== null) {
                $this->retentionPage();
            }
        } catch (Throwable $e) {
            $this->logAgentError('Delivery-log retention failed: ' . $e->getMessage());
            $this->retentionCutoffMs = null;
        }
    }

    /**
     * Samples the current policy once for a new pass, retaining no settings between passes.
     *
     * @throws SettingException When the retention setting cannot be read as an integer
     * @throws DatabaseException When the persisted retention setting cannot be read
     */
    private function beginRetentionPass(): void
    {
        $setting = Hilos::$setting;
        if ($setting === null) {
            return;
        }

        $this->retentionCutoffMs = new DeliveryLogRetention()->cutoff(
            $setting[DeliveryLogRetention::RETENTION_SETTING_KEY]->int(), new DateTimeImmutable(),
        );
        $this->retentionAfterCreatedAt = 0;
        $this->retentionAfterId = 0;
        $this->retentionRemoved = 0;
    }

    /**
     * Advances across every shard's rows and deletes only this instance's still-terminal rows.
     *
     * @throws HilosException When selection, ownership, deletion or its announcement fails
     */
    private function retentionPage(): void
    {
        $page = Hilos::$db->notificationDeliveries->expiredIdentitiesAfter(
            $this->channel()->name(), $this->retentionCutoffMs,
            $this->retentionAfterCreatedAt, $this->retentionAfterId, self::RETENTION_PAGE_SIZE,
        );
        foreach ($page as $ref) {
            $this->retentionAfterCreatedAt = $ref->createdAt;
            $this->retentionAfterId = $ref->identity->deliveryId;
            if ($this->ownsIdentity($ref->identity)
                && isset(Hilos::$db->notificationDeliveries[$ref->identity->deliveryId])
                && Hilos::$db->notificationDeliveries[$ref->identity->deliveryId]->status !== DeliveryStatus::PENDING) {
                Hilos::$db->notificationDeliveries[$ref->identity->deliveryId]->actions->delete();
                $this->retentionRemoved++;
            }
        }
        if (count($page) < self::RETENTION_PAGE_SIZE) {
            if ($this->retentionRemoved > 0) {
                $this->logAgentInfo("Delivery-log retention removed {$this->retentionRemoved} rows");
            }
            $this->retentionCutoffMs = null;
        }
    }

    /**
     * Captures an immutable identity only when every coordinate and the current route agree.
     *
     * @param NotificationDeliverSignalData $delivery Parsed frame with the persisted delivery id
     * @throws HilosException When saved coordinates cannot be read or the pool configuration is invalid
     */
    private function admitDelivery(NotificationDeliverSignalData $delivery): void
    {
        if ($delivery->channel !== $this->channel()->name()) {
            return;
        }
        if ($this->freezeSilencesDeliveries()) {
            $this->abandonDeliveries();
            $this->rereadOwed = true;

            return;
        }
        $saved = Hilos::$db->notificationDeliveries->readIdentity($delivery->deliveryId);
        if ($saved === null || $saved->deliveryId !== $delivery->deliveryId
            || $saved->notificationId !== $delivery->notificationId
            || $saved->channel !== $delivery->channel || !$this->ownsIdentity($saved)) {
            return;
        }
        $owner = DeliveryOwnerResolver::forDelivery($saved);
        if ($delivery->shardKey !== ($owner->index === null ? null : (int)$owner->index)) {
            return;
        }

        $this->ops[$saved->deliveryId] ??= $saved;
    }

    /**
     * Takes one failed delivery back for an admin, then answers the page that asked (HIL-1910).
     *
     * The library addressed this instance by the identity the row was saved with, and the row
     * may have moved on since; so the request is judged here, where the row is written
     * ({@see self::takeBack()}). Exactly one answer leaves, straight to the page, under the
     * reply name the page put on its ask and with its accept key and request id. It is queued
     * outside the judgement, so a failure to queue it cannot answer a second time.
     *
     * @param NotificationDeliveryRetrySignalData $retry Which delivery, as the library saw it, and who is waiting
     * @throws InvalidArgumentException When the answer cannot be named or queued
     */
    private function retryDelivery(NotificationDeliveryRetrySignalData $retry): void
    {
        $this->sendToAgent($retry->replySignal, HandoverAnswerSignalData::to($retry, $this->takeBack($retry)));
    }

    /**
     * Resets the saved failed row and queues it on this instance, or says why not.
     *
     * A freeze that silences the pipeline refuses before anything is read. Then the row must
     * still stand under the same four coordinates the request names, and the current pool must
     * still give it to this instance under the shard key the request was routed by. The reset
     * judges the saved status and the notification behind the row itself. It runs in a
     * transaction of its own, so a write or an announcement that fails takes the row back with
     * it: a refusal leaves the row as it was. Only a reset that stands puts the saved identity
     * into the queue, in place of anything this instance still held under the id; the next tick
     * starts it like an adopted pending row, with no deliver frame and no transport opened here.
     * An expected refusal is said; a failure becomes the refusal a page may read, and one held
     * back from the page is logged here.
     *
     * @param NotificationDeliveryRetrySignalData $retry Saved coordinates the library addressed this instance by
     * @return ?ActionRefusal Why the delivery was not taken back, or null when it waits in this instance's queue
     */
    private function takeBack(NotificationDeliveryRetrySignalData $retry): ?ActionRefusal
    {
        if ($this->freezeSilencesDeliveries()) {
            return ActionRefusal::said('Delivery retry is unavailable during maintenance');
        }
        try {
            $saved = Hilos::$db->notificationDeliveries->readIdentity($retry->deliveryId);
            if ($saved === null) {
                return ActionRefusal::said("Unknown delivery: {$retry->deliveryId}");
            }
            if (!$this->retryReachedOwner($retry, $saved)) {
                return ActionRefusal::said('Delivery retry reached a different channel or shard');
            }
            Database::transaction(EntityNotificationDelivery::getDatabaseName(), static function () use ($saved): void {
                Hilos::$db->notificationDeliveries[$saved->deliveryId]->actions->resetForRetry();
            });
        } catch (WiringRefusal $refusal) {
            // Answered rather than raised, like the failures below: the page waits on this answer. The
            // refusal's own words name a collection nobody here reads, so they ride only as the detail.
            return $this->retryFailure($retry, $refusal);
        } catch (Throwable $e) {
            return $this->retryFailure($retry, $e);
        }

        $this->finishOp($saved->deliveryId);
        $this->ops[$saved->deliveryId] = $saved;

        return null;
    }

    /**
     * Reduces a failed retry to the refusal the page may read, logging the failure the page is not told.
     *
     * @param NotificationDeliveryRetrySignalData $retry Request that failed
     * @param Throwable $e Failure raised while judging or resetting the row
     * @return ActionRefusal Refusal to answer the page with
     */
    private function retryFailure(NotificationDeliveryRetrySignalData $retry, Throwable $e): ActionRefusal
    {
        $refusal = ActionRefusal::fromThrowable($e);
        if ($refusal->isInternal()) {
            $this->logAgentError("Delivery retry failed for #{$retry->deliveryId}: {$e->getMessage()}");
        }

        return $refusal;
    }

    /**
     * @param NotificationDeliveryRetrySignalData $retry Coordinates and shard key the library addressed this instance by
     * @param NotificationDeliveryIdentity $saved Fresh saved coordinates under the same id
     * @return bool Whether the request names the saved row unchanged and the current pool routes it to this instance
     * @throws EnvException When the descriptor's pool configuration is unreadable or invalid
     * @throws InvalidArgumentException When the saved key or pool width cannot be reduced
     */
    private function retryReachedOwner(NotificationDeliveryRetrySignalData $retry, NotificationDeliveryIdentity $saved): bool
    {
        return $saved->deliveryId === $retry->deliveryId && $saved->notificationId === $retry->notificationId
            && $saved->channel === $retry->channel && $saved->routingKey === $retry->routingKey
            && $this->ownsIdentity($saved)
            && $retry->shardKey === ($this->agentIndex === null ? null : (int)$this->agentIndex);
    }

    /**
     * Whether this node's freeze silences the durable deliveries.
     *
     * A null row means this process holds no runtime state, never "the mode is off for this
     * project" (docs/agents/architecture/protected-mode.md, 'Reading The Runtime Row, And What
     * Null Means'), and the mode cannot be entered without it.
     *
     * @return bool Whether the pipeline must write nothing right now
     */
    private function freezeSilencesDeliveries(): bool
    {
        return Hilos::$rt?->hilosProtectedModeRuntime?->silencesUnstoppedWriters() === true;
    }

    /**
     * Drops every delivery this agent holds, releasing the transport of each in-flight attempt.
     *
     * The rows are not touched and stay pending. The count is taken from the queue alone: an
     * in-flight attempt is always also queued ({@see startQueued()} starts only queued ops,
     * {@see finishOp()} forgets both).
     *
     * @return int Number of deliveries dropped
     */
    private function abandonDeliveries(): int
    {
        $dropped = count($this->ops);
        foreach ($this->inFlight as $attempt) {
            $attempt->close();
        }
        $this->inFlight = [];
        $this->ops = [];
        $this->nextAttemptMs = [];
        $this->pendingCursor = 0;

        return $dropped;
    }

    /**
     * Inspects one bounded page, advancing past foreign rows as well as this shard's.
     * A completed recovery scan stays complete until restart or a silencing freeze.
     * A configuration failure leaves the failing identity owed on the next tick.
     *
     * @throws HilosException When pending identities cannot be read or the pool configuration is invalid
     */
    private function adoptPendingRows(): void
    {
        if (Schema::getTable(EntityNotificationDelivery::_table) === null) {
            $this->rereadOwed = false;

            return;
        }

        $pending = Hilos::$db->notificationDeliveries->pendingIdentitiesAfter(
            $this->channel()->name(), $this->pendingCursor, self::PENDING_ADOPTION_BATCH_SIZE,
        );
        foreach ($pending as $saved) {
            if ($this->ownsIdentity($saved)) {
                $this->ops[$saved->deliveryId] ??= $saved;
            }
            $this->pendingCursor = $saved->deliveryId;
        }
        $this->rereadOwed = count($pending) === self::PENDING_ADOPTION_BATCH_SIZE;
    }

    /**
     * Checks the saved identity and current owner before advancing or settling an attempt.
     *
     * @param float $nowMs Current time in milliseconds
     * @throws HilosException When ownership, pool configuration, transport or settlement fails
     */
    private function pumpInFlight(float $nowMs): void
    {
        foreach ($this->inFlight as $deliveryId => $attempt) {
            if (!$this->opIsCurrent($deliveryId)) {
                $this->finishOp($deliveryId);
                continue;
            }
            $attempt->tick($nowMs);
            if ($attempt->isBusy()) {
                continue;
            }
            if (!$this->opIsCurrent($deliveryId)) {
                $this->finishOp($deliveryId);
                continue;
            }
            if ($attempt->isDelivered()) {
                Hilos::$db->notificationDeliveries[$deliveryId]->actions->markSent();
                $this->finishOp($deliveryId);
                continue;
            }

            $error = $attempt->errorDetail() ?? 'delivery failed';
            $attempt->close();
            unset($this->inFlight[$deliveryId]);
            $this->failOrRetry($deliveryId, $error, $nowMs);
        }
    }

    /**
     * @param float $nowMs Current time in milliseconds
     * @throws HilosException When ownership, pool configuration or starting an attempt fails
     */
    private function startQueued(float $nowMs): void
    {
        foreach ($this->ops as $deliveryId => $op) {
            if (isset($this->inFlight[$deliveryId]) || $nowMs < ($this->nextAttemptMs[$deliveryId] ?? 0.0)) {
                continue;
            }
            if (count($this->inFlight) >= $this->maxConcurrent()) {
                return;
            }

            $this->startOp($deliveryId);
        }
    }

    /**
     * Counts the attempt and opens its transport only while the saved id still belongs here.
     *
     * @param int $deliveryId Captured delivery primary key
     * @throws HilosException When ownership, pool configuration, bookkeeping or the channel transport fails
     */
    private function startOp(int $deliveryId): void
    {
        if (!$this->opIsCurrent($deliveryId)) {
            $this->finishOp($deliveryId);

            return;
        }
        $notification = Hilos::$db->notifications[$this->ops[$deliveryId]->notificationId] ?? null;
        if (!$this->opIsCurrent($deliveryId)) {
            $this->finishOp($deliveryId);

            return;
        }
        Hilos::$db->notificationDeliveries[$deliveryId]->actions->beginAttempt();
        if ($notification === null) {
            $this->failOrRetry($deliveryId, 'notification unavailable', microtime(true) * TimeConstants::MS_PER_SECOND);

            return;
        }
        $address = $this->channel()->resolveAddress($notification->userId);
        if ($address === null) {
            $this->failOrRetry($deliveryId, 'address unresolved', microtime(true) * TimeConstants::MS_PER_SECOND);

            return;
        }
        if (!$this->opIsCurrent($deliveryId)) {
            $this->finishOp($deliveryId);

            return;
        }

        $this->inFlight[$deliveryId] = $this->createAttempt($address, $notification->getObject());
    }

    /**
     * @param int $deliveryId Captured delivery primary key
     * @param string $error Failure detail
     * @param float $nowMs Current time in milliseconds
     * @throws HilosException When ownership, pool configuration or recording the failure fails
     */
    private function failOrRetry(int $deliveryId, string $error, float $nowMs): void
    {
        if (!$this->opIsCurrent($deliveryId)) {
            $this->finishOp($deliveryId);

            return;
        }
        Hilos::$db->notificationDeliveries[$deliveryId]->actions->recordFailure($error, $this->maxAttempts());
        if (Hilos::$db->notificationDeliveries[$deliveryId]->status !== DeliveryStatus::PENDING) {
            $this->finishOp($deliveryId);

            return;
        }
        $this->nextAttemptMs[$deliveryId] = $nowMs + $this->backoffMs(Hilos::$db->notificationDeliveries[$deliveryId]->attempts);
    }

    /**
     * @param int $deliveryId Captured delivery primary key
     * @return bool Whether fresh coordinates, owner and pending state still match this local op
     * @throws HilosException When saved coordinates cannot be read or the pool configuration is invalid
     */
    private function opIsCurrent(int $deliveryId): bool
    {
        $saved = Hilos::$db->notificationDeliveries->readIdentity($deliveryId);
        $op = $this->ops[$deliveryId];

        return $saved !== null && $saved->deliveryId === $op->deliveryId && $saved->notificationId === $op->notificationId
            && $saved->channel === $op->channel && $saved->routingKey === $op->routingKey
            && $this->ownsIdentity($saved)
            && isset(Hilos::$db->notificationDeliveries[$deliveryId])
            && Hilos::$db->notificationDeliveries[$deliveryId]->status === DeliveryStatus::PENDING;
    }

    /**
     * @param NotificationDeliveryIdentity $saved Immutable saved coordinates
     * @return bool Whether the registered current route addresses this channel/shard instance
     * @throws EnvException When the descriptor's pool configuration is unreadable or invalid
     * @throws InvalidArgumentException When the saved key or pool width cannot be reduced
     */
    private function ownsIdentity(NotificationDeliveryIdentity $saved): bool
    {
        if ($saved->channel !== $this->channel()->name()) {
            return false;
        }
        $owner = DeliveryOwnerResolver::forDelivery($saved);

        return $owner !== null && $owner->type === static::AGENT_TYPE && $owner->index === $this->agentIndex;
    }

    /**
     * @param int $deliveryId Op key whose transport and bookkeeping must be forgotten
     */
    private function finishOp(int $deliveryId): void
    {
        if (isset($this->inFlight[$deliveryId])) {
            $this->inFlight[$deliveryId]->close();
        }
        unset($this->inFlight[$deliveryId], $this->ops[$deliveryId], $this->nextAttemptMs[$deliveryId]);
    }

    /**
     * Exponential retry backoff for the next attempt.
     *
     * @param int $attempts Attempts made so far
     * @return float Backoff in milliseconds before the next attempt
     */
    private function backoffMs(int $attempts): float
    {
        $exponent = max(0, $attempts - 1);

        return self::RETRY_BACKOFF_BASE_MS * (2 ** $exponent);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;

/**
 * AbstractDeliveryChannelAgent - the async owner of one channel's deliveries (HIL-196).
 *
 * The tick-loop half of a delivery channel (email 197, sms 285, push 199, telegram
 * 198), modeled on {@see \Hilos\Auth\OAuth\Agent\AbstractOAuthAgent}. It owns the
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
 */
abstract class AbstractDeliveryChannelAgent extends AbstractAgent
{
    /** Default ceiling on concurrently pumped attempts (outbound I/O, not CPU). */
    private const int DEFAULT_MAX_CONCURRENT = 16;

    /** Default attempt ceiling after which a delivery fails terminally. */
    private const int DEFAULT_MAX_ATTEMPTS = 3;

    /** Base retry backoff in milliseconds, doubled per prior attempt. */
    private const float RETRY_BACKOFF_BASE_MS = 1000.0;

    /** @var array<int, NotificationDeliverSignalData> Ops awaiting a start or a retry, keyed by notification id. */
    private array $ops = [];

    /** @var array<int, DeliveryAttempt> In-flight attempts, keyed by notification id. */
    private array $inFlight = [];

    /** @var array<int, float> Earliest next-attempt time (ms) per op, for retry backoff. */
    private array $nextAttemptMs = [];

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
     */
    abstract protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt;

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
     * Adopts a dispatched delivery for this channel, keyed by its notification id.
     *
     * The single intake: a deliver signal whose channel matches this agent is queued
     * for the tick loop; a mismatched channel or a malformed payload is ignored (the
     * router only routes this channel's signal here, so a mismatch means a
     * misconfiguration and must not drive a foreign send).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name (this channel's deliver signal)
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if (!$data->data instanceof NotificationDeliverSignalData) {
            $this->logAgentWarning($name . ' payload must be ' . NotificationDeliverSignalData::class);

            return;
        }
        if ($data->data->channel !== $this->channel()->name()) {
            $this->logAgentWarning(
                "delivery for channel '{$data->data->channel}' reached the '{$this->channel()->name()}' agent",
            );

            return;
        }

        $this->ops[$data->data->notificationId] = $data->data;
    }

    /**
     * Pumps every in-flight attempt one step, then starts freshly queued ops.
     */
    public function onTick(): void
    {
        $nowMs = microtime(true) * 1000;
        $this->pumpInFlight($nowMs);
        $this->startQueued($nowMs);
    }

    /**
     * Abandons every in-flight attempt on shutdown, releasing its transport.
     */
    public function onStop(): void
    {
        foreach ($this->inFlight as $attempt) {
            $attempt->close();
        }
        $this->inFlight = [];
        $this->ops = [];
        $this->nextAttemptMs = [];
    }

    /**
     * Advances each in-flight attempt and records its terminal outcome.
     *
     * A settled attempt marks its delivery row sent, or records a failure that either
     * schedules a backed-off retry or fails the row terminally once the attempt
     * ceiling is reached. A row that vanished or already settled elsewhere just drops
     * the attempt.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function pumpInFlight(float $nowMs): void
    {
        foreach (array_keys($this->inFlight) as $notificationId) {
            $attempt = $this->inFlight[$notificationId];
            $attempt->tick($nowMs);
            if ($attempt->isBusy()) {
                continue;
            }

            $deliveries = $this->deliveries();
            $delivery = $deliveries->findFor($notificationId, $this->channel()->name());
            if ($delivery === null || $delivery->isSettled()) {
                $this->finishOp($notificationId, $attempt);
                continue;
            }

            if ($attempt->isDelivered()) {
                $deliveries->markSent($delivery);
                $this->finishOp($notificationId, $attempt);
                continue;
            }

            $deliveries->recordFailure(
                $delivery,
                $attempt->errorDetail() ?? 'delivery failed',
                $this->maxAttempts(),
            );
            $attempt->close();
            unset($this->inFlight[$notificationId]);
            if ($delivery->isSettled()) {
                unset($this->ops[$notificationId], $this->nextAttemptMs[$notificationId]);
                continue;
            }
            $this->nextAttemptMs[$notificationId] = $nowMs + $this->backoffMs($delivery->attempts);
        }
    }

    /**
     * Starts every queued op that is due and under the concurrency ceiling.
     *
     * An op whose delivery row is gone or already settled is dropped; a due op with a
     * free pool slot begins an attempt (counting the try), unless the address no
     * longer resolves, which is recorded as a failed attempt like any other.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function startQueued(float $nowMs): void
    {
        foreach ($this->ops as $notificationId => $op) {
            if (isset($this->inFlight[$notificationId])) {
                continue;
            }
            if ($nowMs < ($this->nextAttemptMs[$notificationId] ?? 0.0)) {
                continue;
            }
            if (count($this->inFlight) >= $this->maxConcurrent()) {
                return;
            }

            $this->startOp($notificationId);
        }
    }

    /**
     * Begins one attempt: load the row and notification, count the try, and open the send.
     *
     * @param int $notificationId Notification id to deliver
     */
    private function startOp(int $notificationId): void
    {
        $deliveries = $this->deliveries();
        $delivery = $deliveries->findFor($notificationId, $this->channel()->name());
        if ($delivery === null || $delivery->isSettled()) {
            unset($this->ops[$notificationId], $this->nextAttemptMs[$notificationId]);

            return;
        }

        $notification = $this->notifications()->get($notificationId);
        if (!$notification instanceof ObjectNotification || $notification->userId === null) {
            $deliveries->beginAttempt($delivery);
            $this->failOrRetry($delivery, 'notification unavailable', $notificationId, microtime(true) * 1000);

            return;
        }

        $deliveries->beginAttempt($delivery);
        $address = $this->channel()->resolveAddress($notification->userId);
        if ($address === null) {
            $this->failOrRetry($delivery, 'address unresolved', $notificationId, microtime(true) * 1000);

            return;
        }

        $this->inFlight[$notificationId] = $this->createAttempt($address, $notification);
    }

    /**
     * Records a pre-send failure, dropping the op when it fails terminally or scheduling a retry.
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row
     * @param string $error Failure detail
     * @param int $notificationId Op key
     * @param float $nowMs Current time in milliseconds
     */
    private function failOrRetry(
        ObjectNotificationDelivery $delivery,
        string $error,
        int $notificationId,
        float $nowMs,
    ): void {
        $this->deliveries()->recordFailure($delivery, $error, $this->maxAttempts());
        if ($delivery->isSettled()) {
            unset($this->ops[$notificationId], $this->nextAttemptMs[$notificationId]);

            return;
        }
        $this->nextAttemptMs[$notificationId] = $nowMs + $this->backoffMs($delivery->attempts);
    }

    /**
     * Drops a completed op: closes its attempt and forgets its bookkeeping.
     *
     * @param int $notificationId Op key
     * @param DeliveryAttempt $attempt Settled attempt to close
     */
    private function finishOp(int $notificationId, DeliveryAttempt $attempt): void
    {
        $attempt->close();
        unset($this->inFlight[$notificationId], $this->ops[$notificationId], $this->nextAttemptMs[$notificationId]);
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

    /**
     * Resolves the framework-owned notification-deliveries object collection.
     *
     * @return ObjectNotificationDeliveries Delivery persistence primitives
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$collection instanceof ObjectNotificationDeliveries) {
            throw new LogicException('Notification deliveries object collection is not configured');
        }

        return $collection;
    }

    /**
     * Resolves the framework-owned notifications object collection.
     *
     * @return ObjectNotifications Notification persistence primitives
     */
    private function notifications(): ObjectNotifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notifications);
        if (!$collection instanceof ObjectNotifications) {
            throw new LogicException('Notifications object collection is not configured');
        }

        return $collection;
    }
}

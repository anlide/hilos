<?php

declare(strict_types=1);

namespace Hilos\Push\Delivery;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryAttempt;

/**
 * PushDeliveryAttempt - one notification's fan-out across a recipient's device endpoints (HIL-199).
 *
 * Push is multi-address, so a single notification's delivery is not one send but a set: this attempt
 * holds one {@see PushEndpointSend} per subscribed device and pumps them all each {@see tick}. It
 * settles when every endpoint has, and reports {@see isDelivered()} true when at least one device
 * accepted the push - the delivery row is one row for the whole `push` channel, so any reachable
 * device counts as delivered. As it settles it prunes every endpoint the transport reported gone
 * (404/410) from {@see ObjectPushSubscriptions}, best-effort: a prune that fails leaves the stale row
 * to be reported gone and pruned on the next send. An attempt built over zero endpoints (a
 * subscription list that emptied between dispatch and send, or a channel that could not be
 * configured) settles immediately as a non-delivered failure.
 */
final class PushDeliveryAttempt implements DeliveryAttempt
{
    /** Settled aggregate outcome, latched once every endpoint has settled; null while in flight. */
    private ?bool $delivered = null;

    /** Settled aggregate failure detail, or null while in flight or when a device was reached. */
    private ?string $error = null;

    /**
     * @param list<PushEndpointSend> $sends Per-endpoint sends for this notification (possibly empty)
     */
    public function __construct(private readonly array $sends)
    {
    }

    /**
     * Pumps every endpoint send one step, then settles the aggregate once all have settled.
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void
    {
        if ($this->delivered !== null) {
            return;
        }

        $busy = false;
        foreach ($this->sends as $send) {
            $send->tick($nowMs);
            $busy = $busy || $send->isBusy();
        }
        if ($busy) {
            return;
        }

        $this->settle();
    }

    /**
     * @return bool True while at least one endpoint send has not settled
     */
    public function isBusy(): bool
    {
        return $this->delivered === null;
    }

    /**
     * @return bool True when at least one device accepted the push
     */
    public function isDelivered(): bool
    {
        return $this->delivered ?? false;
    }

    /**
     * @return ?string The aggregate failure detail, or null when a device was reached or still in flight
     */
    public function errorDetail(): ?string
    {
        return $this->error;
    }

    /**
     * Releases every endpoint send's client socket.
     */
    public function close(): void
    {
        foreach ($this->sends as $send) {
            $send->close();
        }
    }

    /**
     * Latches the aggregate outcome and prunes every gone endpoint.
     */
    private function settle(): void
    {
        $delivered = false;
        $failures = [];
        $gone = [];
        foreach ($this->sends as $send) {
            if ($send->isDelivered()) {
                $delivered = true;
                continue;
            }
            if ($send->isGone()) {
                $gone[] = $send->endpoint;
            }
            $failures[] = $send->errorDetail() ?? 'push send failed';
        }

        $this->pruneGone($gone);

        $this->delivered = $delivered;
        if ($delivered) {
            return;
        }
        $this->error = $this->sends === []
            ? 'no push endpoints for recipient'
            : count($failures) . ' of ' . count($this->sends) . ' push endpoints failed: ' . $failures[0];
    }

    /**
     * Removes every gone endpoint from the subscription store, best-effort.
     *
     * @param list<string> $endpoints Endpoints the transport reported gone (404/410)
     */
    private function pruneGone(array $endpoints): void
    {
        if ($endpoints === []) {
            return;
        }

        $subscriptions = Hilos::$db?->getObjectCollection(HilosDbContext::pushSubscriptions);
        if (!$subscriptions instanceof ObjectPushSubscriptions) {
            return;
        }

        foreach ($endpoints as $endpoint) {
            try {
                $subscriptions->unsubscribe($endpoint);
            } catch (DatabaseException) {
                // Best-effort prune: a stale row is reported gone and pruned again on the next send.
            }
        }
    }
}

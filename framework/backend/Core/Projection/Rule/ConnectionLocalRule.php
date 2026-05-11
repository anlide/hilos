<?php

declare(strict_types=1);

namespace Hilos\Core\Projection\Rule;

use Closure;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\ProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Rule for connection-local frontend state.
 *
 * Subscribe snapshot is built per accept key, and broadcast targets a single
 * accept key or a small set instead of the full page audience.
 *
 * Examples: self-connection singleton row, attachment drafts visible only to the
 * owning websocket connection.
 */
final readonly class ConnectionLocalRule implements ProjectionRule
{
    /**
     * Creates a connection-local projection rule from project callbacks.
     *
     * @param list<string> $triggers Source keys that trigger this rule (typically RT)
     * @param Closure(string $acceptKey, PageRouteParams $params): FrontendChangesDTO $snapshotForAcceptKey
     *        Builds a connection-local snapshot for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Builds deliveries targeting affected accept keys inside the audience
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshotForAcceptKey,
        public Closure $broadcast,
    ) {
    }

    /**
     * Returns source keys that can affect connection-local frontend state.
     *
     * @return list<string> Source keys observed by this rule
     */
    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    /**
     * Adds this subscriber's connection-local full state to the snapshot.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Snapshot accumulator for the current subscription
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Page route params for the current subscription
     */
    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void {
        $changes = ($this->snapshotForAcceptKey)($acceptKey, $params);
        $accumulator->mergeFrontendFull($changes);
    }

    /**
     * Builds addressed incremental deliveries through the connection-local callback.
     *
     * @param SourceChange $change Matching DB/RT source change
     * @param list<string> $audienceAcceptKeys Accept keys subscribed to the owning page or group
     * @return iterable<ProjectionDelivery> Addressed deliveries produced by the callback
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

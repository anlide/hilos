<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Page\PageRouteParams;

/**
 * One declarative projection rule attached to a page or group projection.
 *
 * Each rule answers three questions:
 * - which source keys can trigger it (DB or RT collection keys);
 * - how it contributes data to the subscribe snapshot of one subscriber;
 * - how it produces incremental wire deliveries when a matching source change
 *   is recorded in this worker.
 */
interface ProjectionRule
{
    /**
     * Returns DB/RT source keys that can trigger this rule.
     *
     * @return list<string> Collection keys observed by this rule
     */
    public function sourceTriggers(): array;

    /**
     * Contributes full-state data for one page subscription.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Snapshot accumulator shared by all page rules
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Page subscription route params
     */
    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void;

    /**
     * Builds incremental deliveries for one matching source change.
     *
     * @param SourceChange $change DB/RT source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the owning page or group
     * @return iterable<ProjectionDelivery> Addressed WebSocket deliveries to queue
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable;
}

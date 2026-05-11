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
     * @return list<string>
     */
    public function sourceTriggers(): array;

    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void;

    /**
     * @param list<string> $audienceAcceptKeys
     * @return iterable<ProjectionDelivery>
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable;
}

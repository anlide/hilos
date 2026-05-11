<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

/**
 * Group-scoped projection rule set.
 *
 * Mirror of {@see PageProjection} for group subscriptions. Subscribe-snapshot
 * for groups is intentionally not part of the contract: the framework currently
 * routes group subscribe through other channels and groups carry no initial
 * payload. Only incremental broadcast deliveries are emitted from group
 * projections.
 */
abstract class GroupProjection
{
    /**
     * Group identifier this projection serves.
     */
    abstract public function group(): string;

    /**
     * Builds projection deliveries for one DB/RT source change.
     *
     * @param SourceChange $change DB/RT source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to this group in this worker
     * @return iterable<ProjectionDelivery>
     */
    abstract public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable;
}

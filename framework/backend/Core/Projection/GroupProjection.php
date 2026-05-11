<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

/**
 * Group-scoped projection rule set.
 *
 * Mirrors {@see PageProjection} for group subscriptions. Groups currently have
 * no subscribe-snapshot contract because group subscribe is routed through other
 * channels and carries no initial payload; group projections only emit
 * incremental broadcast deliveries.
 */
abstract class GroupProjection
{
    /**
     * Returns the group identifier this projection serves.
     *
     * @return string Group key from the subscription catalog
     */
    abstract public function group(): string;

    /**
     * Builds projection deliveries for one DB/RT source change.
     *
     * @param SourceChange $change DB/RT source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to this group in this worker
     * @return iterable<ProjectionDelivery> Addressed deliveries for subscribed accept keys
     */
    abstract public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable;
}

<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\ClusterContext;

/**
 * Inert placement observer used when no observer is registered.
 *
 * The coordinator always reports a degradation into an observer; outside a daemon (or
 * before the daemon registers itself) there is nobody to react, so
 * {@see ClusterContext::placementObserver()} returns this no-op rather than a nullable the
 * coordinator would have to guard on every event.
 */
final class NullPlacementObserver implements PlacementObserver
{
    public function onPlacementDegraded(string $agentType, ?string $agentIndex): void
    {
    }
}

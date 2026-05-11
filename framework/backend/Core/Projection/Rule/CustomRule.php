<?php

declare(strict_types=1);

namespace Hilos\Core\Projection\Rule;

use Closure;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\ProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;

/**
 * Escape hatch for projection logic that does not fit the typed rule kinds.
 *
 * Both snapshot contribution and broadcast generation are fully driven by
 * project-supplied closures.
 */
final readonly class CustomRule implements ProjectionRule
{
    /**
     * Creates a fully custom projection rule from project callbacks.
     *
     * @param list<string> $triggers Source keys that trigger this rule
     * @param Closure(SubscribeSnapshotAccumulator $accumulator, string $acceptKey, PageRouteParams $params): void $snapshot
     *        Contributes to the subscribe snapshot accumulator
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Builds broadcast deliveries for one source change
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshot,
        public Closure $broadcast,
    ) {
    }

    /**
     * Returns source keys handled by the custom callbacks.
     *
     * @return list<string> Source keys observed by this rule
     */
    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    /**
     * Lets the custom snapshot callback add data to the accumulator.
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
        ($this->snapshot)($accumulator, $acceptKey, $params);
    }

    /**
     * Lets the custom broadcast callback build deliveries for this source change.
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

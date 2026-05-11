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
 * Rule projecting one whole RT collection into the page snapshot and broadcasting
 * incremental RT changes for the same sourceKey through a project-supplied closure.
 *
 * Snapshot data lands in the page's frontend changes block: RT collections do
 * not expose DB collection-compatible payloads, so the rule asks the project
 * to provide a precomputed list of row arrays to seed the snapshot.
 */
final readonly class RtCollectionRule implements ProjectionRule
{
    /**
     * Creates an RT collection projection rule from snapshot and broadcast callbacks.
     *
     * @param string $sourceKey RT collection key
     * @param string $frontendKey Frontend collection key inside the full changes block
     * @param Closure(string $acceptKey, PageRouteParams $params): list<array<string, mixed>> $snapshotRows
     *        Builds the full snapshot rows for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Builds broadcast deliveries for one source change
     */
    public function __construct(
        public string $sourceKey,
        public string $frontendKey,
        public Closure $snapshotRows,
        public Closure $broadcast,
    ) {
    }

    /**
     * Returns the RT source key observed by this collection rule.
     *
     * @return list<string> Single RT collection key observed by this rule
     */
    public function sourceTriggers(): array
    {
        return [$this->sourceKey];
    }

    /**
     * Adds RT rows to the frontend full-state snapshot for this subscriber.
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
        $rows = ($this->snapshotRows)($acceptKey, $params);
        $accumulator->addFrontendFull($this->frontendKey, $rows);
    }

    /**
     * Builds addressed incremental deliveries through the RT broadcast callback.
     *
     * @param SourceChange $change Matching RT source change
     * @param list<string> $audienceAcceptKeys Accept keys subscribed to the owning page or group
     * @return iterable<ProjectionDelivery> Addressed deliveries produced by the callback
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

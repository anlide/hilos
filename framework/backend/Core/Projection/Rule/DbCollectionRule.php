<?php

declare(strict_types=1);

namespace Hilos\Core\Projection\Rule;

use Closure;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\ProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Rule projecting one whole DB collection into the page snapshot and broadcasting
 * incremental DB changes for the same sourceKey through a project-supplied closure.
 */
final readonly class DbCollectionRule implements ProjectionRule
{
    /**
     * Creates a DB collection projection rule from a lazy collection getter.
     *
     * @param string $sourceKey DB collection key (e.g. DbChatContext::events)
     * @param Closure(): DbCollection $collection Lazy collection getter for snapshot
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Builds broadcast deliveries for one source change
     * @param bool $replaceFullOnSnapshot When true, snapshot marks this sourceKey as a full replace
     */
    public function __construct(
        public string $sourceKey,
        public Closure $collection,
        public Closure $broadcast,
        public bool $replaceFullOnSnapshot = true,
    ) {
    }

    /**
     * Returns the DB source key observed by this collection rule.
     *
     * @return list<string> Single DB collection key observed by this rule
     */
    public function sourceTriggers(): array
    {
        return [$this->sourceKey];
    }

    /**
     * Adds the lazy DB collection as a full entities snapshot.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Snapshot accumulator for the current subscription
     * @param string $acceptKey Subscribing WebSocket accept key; unused for shared DB snapshots
     * @param PageRouteParams $params Page route params; unused for shared DB snapshots
     */
    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void {
        $collection = ($this->collection)();
        $accumulator->addEntitiesFull($this->sourceKey, $collection, $this->replaceFullOnSnapshot);
    }

    /**
     * Builds addressed incremental deliveries through the DB broadcast callback.
     *
     * @param SourceChange $change Matching DB source change
     * @param list<string> $audienceAcceptKeys Accept keys subscribed to the owning page or group
     * @return iterable<ProjectionDelivery> Addressed deliveries produced by the callback
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

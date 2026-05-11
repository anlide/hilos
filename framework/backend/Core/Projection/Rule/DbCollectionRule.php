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
     * @param string $sourceKey DB collection key (e.g. DbChatContext::events)
     * @param Closure(): DbCollection $collection Lazy collection getter for snapshot
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast Build broadcast deliveries for one source change
     * @param bool $replaceFullOnSnapshot When true, snapshot marks this sourceKey as a full replace
     */
    public function __construct(
        public string $sourceKey,
        public Closure $collection,
        public Closure $broadcast,
        public bool $replaceFullOnSnapshot = true,
    ) {
    }

    public function sourceTriggers(): array
    {
        return [$this->sourceKey];
    }

    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void {
        $collection = ($this->collection)();
        $accumulator->addEntitiesFull($this->sourceKey, $collection, $this->replaceFullOnSnapshot);
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

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
 * Snapshot data lands in the page's FrontendChangesDTO block: RT collections do
 * not expose DbCollection-compatible payloads, so the rule asks the project to
 * provide a precomputed list of row arrays to seed the snapshot.
 */
final readonly class RtCollectionRule implements ProjectionRule
{
    /**
     * @param string $sourceKey RT collection key
     * @param string $frontendKey Frontend collection key inside FrontendChangesDTO::full
     * @param Closure(string $acceptKey, PageRouteParams $params): list<array<string, mixed>> $snapshotRows
     *        Build the full snapshot rows for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Build broadcast deliveries for one source change
     */
    public function __construct(
        public string $sourceKey,
        public string $frontendKey,
        public Closure $snapshotRows,
        public Closure $broadcast,
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
        $rows = ($this->snapshotRows)($acceptKey, $params);
        $accumulator->addFrontendFull($this->frontendKey, $rows);
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

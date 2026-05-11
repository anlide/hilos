<?php

declare(strict_types=1);

namespace Hilos\Core\Projection\Rule;

use Closure;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\ProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Rule projecting derived frontend rows that join DB and RT sources.
 *
 * Snapshot is delivered through {@see FrontendChangesDTO}: the project closure
 * builds the joined rows for the subscriber. Broadcast is a project closure
 * that knows which incremental updates to emit for one source change.
 */
final readonly class JoinedProjectionRule implements ProjectionRule
{
    /**
     * @param list<string> $triggers Source keys that trigger this rule (DB or RT)
     * @param Closure(string $acceptKey, PageRouteParams $params): FrontendChangesDTO $snapshotChanges
     *        Build the full snapshot FrontendChangesDTO for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Build broadcast deliveries for one source change
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshotChanges,
        public Closure $broadcast,
    ) {
    }

    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void {
        $changes = ($this->snapshotChanges)($acceptKey, $params);
        $accumulator->mergeFrontendFull($changes);
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

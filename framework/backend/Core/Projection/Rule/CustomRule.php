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
     * @param list<string> $triggers Source keys that trigger this rule
     * @param Closure(SubscribeSnapshotAccumulator $accumulator, string $acceptKey, PageRouteParams $params): void $snapshot
     *        Contribute to the subscribe snapshot accumulator
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Build broadcast deliveries for one source change
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshot,
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
        ($this->snapshot)($accumulator, $acceptKey, $params);
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

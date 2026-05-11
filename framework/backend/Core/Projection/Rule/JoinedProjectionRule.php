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
 * Snapshot data is returned as FrontendChangesDTO: the project closure builds
 * the joined rows for the subscriber. Broadcast is a project closure that knows
 * which incremental updates to emit for one source change.
 */
final readonly class JoinedProjectionRule implements ProjectionRule
{
    /**
     * Creates a joined frontend projection rule from project callbacks.
     *
     * @param list<string> $triggers Source keys that trigger this rule (DB or RT)
     * @param Closure(string $acceptKey, PageRouteParams $params): FrontendChangesDTO $snapshotChanges
     *        Builds the full snapshot changes for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Builds broadcast deliveries for one source change
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshotChanges,
        public Closure $broadcast,
    ) {
    }

    /**
     * Returns source keys that can affect the joined frontend projection.
     *
     * @return list<string> DB/RT source keys observed by this rule
     */
    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    /**
     * Adds joined frontend full-state rows for this subscriber.
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
        $changes = ($this->snapshotChanges)($acceptKey, $params);
        $accumulator->mergeFrontendFull($changes);
    }

    /**
     * Builds addressed incremental deliveries through the joined broadcast callback.
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

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
 * Rule for connection-local frontend state: subscribe snapshot is built per accept key,
 * and broadcast targets a single accept key (or a small set) instead of the full page audience.
 *
 * Examples: self-connection singleton row, attachment drafts visible only to the
 * owning websocket connection.
 */
final readonly class ConnectionLocalRule implements ProjectionRule
{
    /**
     * @param list<string> $triggers Source keys that trigger this rule (typically RT)
     * @param Closure(string $acceptKey, PageRouteParams $params): FrontendChangesDTO $snapshotForAcceptKey
     *        Build connection-local snapshot for one subscriber
     * @param Closure(SourceChange $change, list<string> $audienceAcceptKeys): iterable<ProjectionDelivery> $broadcast
     *        Build deliveries targeting affected accept keys (intersect with audience)
     */
    public function __construct(
        public array $triggers,
        public Closure $snapshotForAcceptKey,
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
        $changes = ($this->snapshotForAcceptKey)($acceptKey, $params);
        $accumulator->mergeFrontendFull($changes);
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return ($this->broadcast)($change, $audienceAcceptKeys);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Page-scoped projection rule set.
 *
 * A concrete page projection lists declarative ProjectionRule instances in
 * rules(). The framework drives both the initial subscribe
 * snapshot and the incremental broadcast through this single rule list:
 *
 * - buildSubscribeSnapshot() walks every rule, asks it to contribute to a
 *   SubscribeSnapshotAccumulator, then delegates to wrapSnapshot() to produce
 *   the page-specific wire DTO.
 * - buildBroadcastDeliveries() dispatches one source change to every rule
 *   whose sourceTriggers() contains the change's source key.
 *
 * Subclasses may override buildExtraBroadcastDeliveries() for rare cross-rule
 * effects that do not map to a single rule.
 */
abstract class PageProjection
{
    /**
     * Returns the page identifier served by this projection.
     *
     * @return string Page key from the page catalog or constants
     */
    abstract public function page(): string;

    /**
     * Returns the signal name used for the initial subscribe snapshot.
     *
     * @return string Server-to-client signal name for page subscription data
     */
    abstract public function subscribeSnapshotSignalName(): string;

    /**
     * Lists the declarative rules used for snapshots and source-change broadcasts.
     *
     * @return iterable<ProjectionRule>
     */
    abstract protected function rules(): iterable;

    /**
     * Wraps the accumulated snapshot state into the page-specific wire DTO.
     *
     * Returning null suppresses the subscribe-snapshot signal entirely (used for
     * pages whose subscription is a marker without payload).
     *
     * @param SubscribeSnapshotAccumulator $accumulator Full state collected from this projection's rules
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Page subscription route params
     * @return ?SignalDataInterface Page-specific subscribe payload, or null to send no snapshot
     * @throws PageSubscriptionException When page route params or resources reject the subscription
     */
    abstract protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface;

    /**
     * Builds the initial page snapshot payload for one subscriber.
     *
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Page subscription route params
     * @return ?SignalDataInterface Page-specific subscribe payload, or null to send no snapshot
     * @throws PageSubscriptionException When the page-specific wrapper rejects the subscription
     */
    public function buildSubscribeSnapshot(string $acceptKey, PageRouteParams $params): ?SignalDataInterface
    {
        $accumulator = new SubscribeSnapshotAccumulator();
        foreach ($this->rules() as $rule) {
            $rule->contributeToSnapshot($accumulator, $acceptKey, $params);
        }
        return $this->wrapSnapshot($accumulator, $acceptKey, $params);
    }

    /**
     * Builds incremental projection deliveries for one source change.
     *
     * @param SourceChange $change DB/RT source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the page
     * @return iterable<ProjectionDelivery> Addressed WebSocket deliveries to queue
     */
    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        foreach ($this->rules() as $rule) {
            if (!in_array($change->sourceKey, $rule->sourceTriggers(), true)) {
                continue;
            }
            yield from $rule->buildBroadcastDeliveries($change, $audienceAcceptKeys);
        }
        yield from $this->buildExtraBroadcastDeliveries($change, $audienceAcceptKeys);
    }

    /**
     * Hook for deliveries that span multiple rules and cannot be expressed as one.
     *
     * @param SourceChange $change DB/RT source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the page
     * @return iterable<ProjectionDelivery> Extra addressed WebSocket deliveries to queue
     */
    protected function buildExtraBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return [];
    }
}

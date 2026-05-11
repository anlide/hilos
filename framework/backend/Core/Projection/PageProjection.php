<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Page-scoped projection rule set.
 *
 * A concrete page projection lists declarative {@see ProjectionRule} instances
 * in {@see self::rules()}. The framework drives both the initial subscribe
 * snapshot and the incremental broadcast through this single rule list:
 *
 * - {@see self::buildSubscribeSnapshot()} walks every rule, asks it to contribute
 *   to a {@see SubscribeSnapshotAccumulator}, then delegates to
 *   {@see self::wrapSnapshot()} to produce the page-specific wire DTO.
 * - {@see self::buildBroadcastDeliveries()} dispatches one source change to
 *   every rule whose {@see ProjectionRule::sourceTriggers()} contains the
 *   change's source key.
 *
 * Subclasses may override {@see self::buildExtraBroadcastDeliveries()} for
 * rare cross-rule effects that do not map to a single rule.
 */
abstract class PageProjection
{
    abstract public function page(): string;

    abstract public function subscribeSnapshotSignalName(): string;

    /**
     * @return iterable<ProjectionRule>
     */
    abstract protected function rules(): iterable;

    /**
     * Wraps the accumulated snapshot state into the page-specific wire DTO.
     *
     * Returning null suppresses the subscribe-snapshot signal entirely (used for
     * pages whose subscription is a marker without payload).
     */
    abstract protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface;

    public function buildSubscribeSnapshot(string $acceptKey, PageRouteParams $params): ?SignalDataInterface
    {
        $accumulator = new SubscribeSnapshotAccumulator();
        foreach ($this->rules() as $rule) {
            $rule->contributeToSnapshot($accumulator, $acceptKey, $params);
        }
        return $this->wrapSnapshot($accumulator, $acceptKey, $params);
    }

    /**
     * @param list<string> $audienceAcceptKeys
     * @return iterable<ProjectionDelivery>
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
     * @param list<string> $audienceAcceptKeys
     * @return iterable<ProjectionDelivery>
     */
    protected function buildExtraBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        return [];
    }
}

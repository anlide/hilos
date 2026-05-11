<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;

/**
 * Worker-local projection accumulator and dispatcher.
 *
 * Projection state is intentionally not stored in the daemon. DB_SYNC/RT_SYNC
 * facts invalidate the projection of every worker that receives them, including
 * facts that originated in another worker. During flush, each worker targets
 * only accept keys present in its own subscription mirror inside SignalRouter,
 * so the daemon remains a transport for already-addressed WebSocket frames.
 *
 * Subclasses register concrete PageProjection and GroupProjection instances in
 * configure(). Each projection owns the mapping from
 * source facts to wire deliveries for one page or one group.
 */
abstract class ProjectionContext
{
    private SourceChangeSet $changes;

    /** @var array<string, PageProjection> Map of page key to projection */
    private array $pages = [];

    /** @var array<string, GroupProjection> Map of group key to projection */
    private array $groups = [];

    /**
     * Starts with an empty worker-local source-change buffer.
     */
    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    /**
     * Registers concrete page and group projections.
     *
     * Called once during Hilos initialization. Subclasses call
     * register() for each rule instance.
     */
    abstract public function configure(): void;

    /**
     * Registers a page or group projection instance.
     *
     * @param PageProjection|GroupProjection $projection Projection to register by its contract key
     */
    public function register(PageProjection|GroupProjection $projection): void
    {
        if ($projection instanceof PageProjection) {
            $this->pages[$projection->page()] = $projection;
            return;
        }
        $this->groups[$projection->group()] = $projection;
    }

    /**
     * Records a DB/RT sync fact in the worker-local projection buffer.
     *
     * @param SourceChange $change Source change to dispatch on the next flush
     */
    public function record(SourceChange $change): void
    {
        $this->changes->add($change);
    }

    /**
     * Reports whether any source changes are buffered awaiting flush.
     *
     * @return bool Whether any source changes are buffered awaiting flush
     */
    public function hasChanges(): bool
    {
        return !$this->changes->isEmpty();
    }

    /**
     * Sends the initial subscribe-snapshot for a page subscription to one accept key.
     *
     * Finds the registered PageProjection for the page, asks it to build
     * the snapshot payload, and queues the resulting WS_USER signal addressed
     * to the subscribing client. Pages without a registered projection are a
     * no-op. Pages whose snapshot is null (marker subscriptions) emit no signal.
     *
     * @param string $page Page key from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Page subscription route params
     * @throws PageSubscriptionException When the page projection rejects the subscription
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if (Hilos::$sr === null) {
            return;
        }
        $projection = $this->pages[$page] ?? null;
        if ($projection === null) {
            return;
        }

        $payload = $projection->buildSubscribeSnapshot($acceptKey, $params);
        if ($payload === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($projection->subscribeSnapshotSignalName()),
            signalData: new WebSocketSignalData(
                data: $payload,
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Drains the buffered source changes through every registered projection.
     *
     * For each change the framework resolves the per-page and per-group audience
     * from the SignalRouter subscription mirror, asks each projection to build
     * deliveries, and queues each delivery as an addressed WS_USER signal.
     */
    public function flushToSignalRouter(): void
    {
        if ($this->changes->isEmpty() || Hilos::$sr === null) {
            return;
        }

        $changes = $this->drainChanges();
        $this->dispatchChangesToPagesAndGroups($changes);
    }

    /**
     * Drains the buffered source changes and resets the accumulator.
     *
     * Subclasses that override flushToSignalRouter() call this
     * helper to take ownership of the buffered changes before producing extra
     * deliveries (for example, project-level global broadcasts).
     *
     * @return SourceChangeSet Buffered changes captured before the reset
     */
    protected function drainChanges(): SourceChangeSet
    {
        $changes = $this->changes;
        $this->changes = new SourceChangeSet();
        return $changes;
    }

    /**
     * Runs registered page and group projections against the given changes.
     *
     * Reusable from subclasses that interleave per-page dispatch with their
     * own global broadcast pass.
     *
     * @param SourceChangeSet $changes Source changes to project to pages and groups
     */
    protected function dispatchChangesToPagesAndGroups(SourceChangeSet $changes): void
    {
        if (Hilos::$sr === null) {
            return;
        }
        foreach ($changes->all() as $change) {
            foreach ($this->pages as $pageProjection) {
                $audience = Hilos::$sr->getAcceptKeysForPage($pageProjection->page());
                if ($audience === []) {
                    continue;
                }
                $this->queueDeliveries($pageProjection->buildBroadcastDeliveries($change, $audience));
            }
            foreach ($this->groups as $groupProjection) {
                $audience = $this->getAcceptKeysForGroup($groupProjection->group());
                if ($audience === []) {
                    continue;
                }
                $this->queueDeliveries($groupProjection->buildBroadcastDeliveries($change, $audience));
            }
        }
    }

    /**
     * Queues addressed WebSocket deliveries produced by projections.
     *
     * Invalid deliveries and empty target accept keys are ignored.
     *
     * @param iterable<ProjectionDelivery> $deliveries Deliveries to queue through SignalRouter
     */
    protected function queueDeliveries(iterable $deliveries): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        foreach ($deliveries as $delivery) {
            if (!$delivery instanceof ProjectionDelivery || $delivery->targetAcceptKey === '') {
                continue;
            }
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName($delivery->wireSignalName),
                signalData: new WebSocketSignalData(
                    data: $delivery->payload,
                    targetAcceptKey: $delivery->targetAcceptKey,
                ),
            );
        }
    }

    /**
     * Returns accept keys subscribed to a group in this worker's subscription mirror.
     *
     * Placeholder: the current SignalRouter exposes group subscription state through
     * subscribe/update/unsubscribe APIs but has no read accessor by group. Group
     * projections in this codebase are stubs awaiting first use; once a real group
     * subscription appears, add SignalRouter::getAcceptKeysForGroup() and route through it.
     *
     * @param string $group Group key
     * @return list<string> Accept keys subscribed to the group on this worker
     */
    private function getAcceptKeysForGroup(string $group): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Generator;
use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Daemon\SignalHoldIndex;
use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Page\PageResource;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use WeakReference;

/**
 * SubscriptionRegistry - Worker-local store of client page and group subscriptions.
 *
 * Owns the subscription state that SignalRouter delegates to. In a worker this is
 * the local mirror used by browser fan-out; in the daemon it is the global routing
 * registry used for broadcasts.
 */
final class SubscriptionRegistry
{
    /** @var array<string, PageSubscription> Page subscriptions keyed by accept key */
    private array $pages = [];

    private int $nodePageSequence = 0;

    /** @var array<string, int> Original node-addressed subscription slots by connection */
    private array $nodePageSlots = [];

    /** @var array<int, string> Scalar connection names in those original slots */
    private array $nodePageConnections = [];

    /** @var array<string, string> Requested node parameters, including an offline node served by fallback */
    private array $pageNodes = [];

    /** @var array<string, SignalHoldIndex> Original node-addressed slots by requested node */
    private array $pagesByNode = [];

    /** @var array<string, array<string, GroupSubscription>> Membership incarnations keyed by accept key, then group */
    private array $groups = [];

    /** @var array<string, array<string, TableViewportSubscription>> Table viewports keyed by accept key, then table key */
    private array $tableViewports = [];

    /**
     * @var array<string, array<string, array<string, list<int|float|string|bool>>>> Options to count beside each
     *     table's filters, keyed by accept key, then table key, then filter key
     *
     * Beside the viewports and not inside them: a viewport is rebuilt from every window frame, and a
     * list kept on it would be lost the first time the reader turned a page. The view declares the
     * list once, when it mounts, and it has to outlive every window after that.
     */
    private array $tableFacetRequests = [];

    /**
     * @var array<string, array<string, string>> Row each connection holds in focus for an open dialog, keyed by
     *     accept key, then table key
     *
     * Beside the viewport for the facets' reason, and for one of its own: the rows of a window take
     * part in the places of their neighbors and in the edges of the page, and a row that has left the
     * window would skew that count if it were kept among them. The focus outlives every window the
     * dialog stays open through; the window does not outlive the next page turn.
     */
    private array $tableFocus = [];

    /**
     * @var array<string, true> Accept keys already told their page could not be delivered
     *
     * One bit per subscription, not per failure. A broken declaration or a refused read is
     * present on every flush, and the fan-out reaches it once per subscriber per flush; without
     * this the connection would be handed the same error frame ten times a second for as long
     * as the defect lasted. Kept beside the viewports because it is the same kind of state —
     * what this connection has been served — and is dropped at the same three moments.
     */
    private array $pageDeliveryFailures = [];

    /**
     * @var array<string, PageResource> DB row an open page is about, keyed by accept key
     *
     * Worker-local, like the page debt beside it. The row is private to this connection's
     * answer and is not read by another worker or node, so it does not belong in runtime
     * state. subscribeToPage() does not drop it: that write runs after the answer has
     * already bound the row.
     */
    private array $pageResources = [];

    /**
     * @var array<string, array<string, true>> Windows owed whole, keyed by accept key, then table key
     *
     * One debt per window, whoever set it: a freeze of the live road (HIL-1139) or a refusal of the
     * window (HIL-1350). Only a window that left for the tab pays; a refusal never pays. The debt
     * lives and dies with the window. The rule is docs/agents/architecture/browser-source-fanout.md,
     * "Coming Back Without A Reload".
     */
    private array $tableWindowDebts = [];

    private DeliveryCleanup $cleanup;

    private int $subscriptionSequence = 0;

    private SignalHoldIndex $pageIndex;

    /** @var array<string, int> Stable page insertion positions by connection */
    private array $pageSlots = [];

    /** @var array<int, string> Live page connections in insertion order */
    private array $pageConnections = [];

    /** @var array<string, int> Latest immutable page incarnation by connection */
    private array $pageGenerations = [];

    private SignalHoldIndex $groupConnectionIndex;

    /** @var array<string, int> Stable outer group-roster positions, independent of individual joins */
    private array $groupConnectionSlots = [];

    /** @var array<int, string> Live group connections in their original outer-map order */
    private array $groupConnections = [];

    /** @var array<string, SignalHoldIndex> Original membership insertion positions for each connection */
    private array $groupNameIndexes = [];

    /** @var array<string, array<string, int>> Membership name to its stable insertion position */
    private array $groupNameSlots = [];

    /** @var array<string, array<int, string>> Scalar names in each connection's membership order */
    private array $groupNames = [];

    /** @var array<string, array<string, int>> Latest immutable membership incarnation */
    private array $groupGenerations = [];

    /** @var array<string, int> Latest change to each connection's membership set, including removals */
    private array $groupRevisions = [];

    /** @var array<string, array<string, WeakReference<GroupOperationBasis>>> Active exact-name observations only */
    private array $groupOperationNames = [];

    /** @var array<string, array<string, WeakReference<GroupOperationBasis>>> Active absent-prefix observations only */
    private array $groupOperationPrefixes = [];

    /** Standalone worker mirrors consume cleanup; a master replaces this owner at binding. */
    public function __construct()
    {
        $this->cleanup = new DeliveryCleanup();
        $this->pageIndex = new SignalHoldIndex();
        $this->groupConnectionIndex = new SignalHoldIndex();
    }

    /** @param DeliveryCleanup $cleanup Existing guarded master owner of retired connection state */
    public function setCleanup(DeliveryCleanup $cleanup): void
    {
        $this->cleanup = $cleanup;
    }

    /**
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier
     * @param array<string, mixed> $params Route params
     */
    public function subscribeToPage(string $acceptKey, string $page, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->forgetNodePage($acceptKey);
        $generation = ++$this->subscriptionSequence;
        if (!isset($this->pages[$acceptKey])) {
            $this->pageSlots[$acceptKey] = $generation;
            $this->pageConnections[$generation] = $acceptKey;
            $this->pageIndex->add($generation);
        }
        $this->pageGenerations[$acceptKey] = $generation;
        $this->pages[$acceptKey] = new PageSubscription($page, $params);
        // The viewports are NOT dropped here, and the moment matters: this runs after the
        // subscription has been answered, and the answer is now where a page's windows are
        // opened (HIL-642). Dropping them here would throw away the windows the frame just
        // delivered, leaving the tables with rows on the screen and no address for a live
        // change. A page that is left drops its windows where it is left — unsubscribeFromPage()
        // below and unsubscribeFromAll() — which covers every way one page replaces another;
        // what remains is a connection re-subscribing the page it is already on, where the
        // answer overwrites each of its windows with the one it just built.
        // Window debts are not dropped here. The subscription's answer already settled each of
        // its tables — a window in `windows` paid, one in `refusedWindows` set the debt
        // (BrowserContext::subscribeTableWindow()) — and on the worker this write happens AFTER
        // the answer (WorkerManager::rememberPageSubscriptionAfterSubscribe(), the PAGE_SUBSCRIBE
        // case and the method in framework/backend/Core/Daemon/WorkerManager.php), so a reset here
        // would erase the debt the answer just set. The page debt follows the same ordering:
        // the worker clears the old debt before dispatch, and this write preserves the new answer's debt.
        // The page resource follows the page debt. The answer binds it before this write, so
        // dropping it here would forget the row the frame just named.
    }

    /**
     * Returns a connection's page subscription, or null when it holds none.
     *
     * @param string $acceptKey Client accept key
     * @return ?PageSubscription Stored subscription, or null when the connection holds none
     */
    public function pageSubscription(string $acceptKey): ?PageSubscription
    {
        return $this->pages[$acceptKey] ?? null;
    }

    /**
     * @param string $acceptKey Existing page subscription
     * @param ?string $nodeId Requested node parameter, absent for a page without a usable node address
     */
    public function indexNodePage(string $acceptKey, ?string $nodeId): void
    {
        if (!isset($this->pages[$acceptKey]) || ($this->pageNodes[$acceptKey] ?? null) === $nodeId) {
            return;
        }
        $this->forgetNodePage($acceptKey);
        if ($nodeId !== null) {
            $slot = ++$this->nodePageSequence;
            $this->nodePageSlots[$acceptKey] = $slot;
            $this->nodePageConnections[$slot] = $acceptKey;
            $this->pageNodes[$acceptKey] = $nodeId;
            ($this->pagesByNode[$nodeId] ??= new SignalHoldIndex())->add($slot);
        }
    }

    /**
     * @param string $nodeId Node whose membership changed
     * @return Generator<int, int> Finite original node slots, without a subscription roster snapshot
     */
    public function nodePageCandidates(string $nodeId): Generator
    {
        return ($this->pagesByNode[$nodeId] ?? new SignalHoldIndex())->candidates();
    }

    /**
     * @param int $slot Original node-addressed subscription slot
     * @param string $nodeId Original requested node
     * @return ?string Original connection, absent after leave, replacement or address change
     */
    public function nodePageAcceptKey(int $slot, string $nodeId): ?string
    {
        $acceptKey = $this->nodePageConnections[$slot] ?? null;
        return $acceptKey !== null && ($this->pageNodes[$acceptKey] ?? null) === $nodeId ? $acceptKey : null;
    }

    /**
     * Records which agent serves a connection's page subscription.
     *
     * Written by the master once the address is resolved, and read by routing for every
     * later signal of the same subscription: an unsubscribe carries no params, so nothing
     * downstream could resolve the instance a second time (HIL-627).
     *
     * The page is named and has to match the stored one, the way it does for an update and
     * for an unsubscribe: an address is resolved from the signal that carried it, and a
     * signal about a page this connection has already left must not re-address the page it
     * is on now.
     *
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier that must match the stored page
     * @param string $agentType Agent type serving the subscription
     * @param ?string $agentIndex Instance index, or null to serve the subscription unindexed
     */
    public function bindPageAgent(string $acceptKey, string $page, string $agentType, ?string $agentIndex, ?string $agentNode = null): void
    {
        if (!isset($this->pages[$acceptKey])) {
            return;
        }

        $subscription = $this->pages[$acceptKey];
        if ($subscription->page !== $page) {
            return;
        }

        $this->pages[$acceptKey] = $subscription->withPageAgent($agentType, $agentIndex, $agentNode);
        $this->pageGenerations[$acceptKey] = ++$this->subscriptionSequence;
    }

    /**
     * Merges new params into an existing page subscription.
     *
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier being updated
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @throws PageSubscriptionNotFoundException If no subscription exists for the accept key
     * @throws PageSubscriptionMismatchException If the stored page differs from the page being updated
     */
    public function updatePageSubscription(string $acceptKey, string $page, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->pages[$acceptKey])) {
            throw new PageSubscriptionNotFoundException($acceptKey);
        }

        $subscription = $this->pages[$acceptKey];
        if ($subscription->page !== $page) {
            throw new PageSubscriptionMismatchException($subscription->page, $page);
        }

        $this->pages[$acceptKey] = $subscription->withMergedParams($params);
        $this->pageGenerations[$acceptKey] = ++$this->subscriptionSequence;
    }

    /**
     * Removes a page subscription when the stored page matches.
     *
     * @param string $acceptKey Client accept key
     * @param string $page Page identifier that must match the stored page
     */
    public function unsubscribeFromPage(string $acceptKey, string $page): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->pages[$acceptKey])) {
            return;
        }
        if ($this->pages[$acceptKey]->page !== $page) {
            return;
        }

        $this->forgetNodePage($acceptKey);
        $this->forgetPageState($acceptKey);
    }

    /**
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     * @param array<string, mixed> $params Route params
     */
    public function subscribeToGroup(string $acceptKey, string $group, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        $this->invalidateGroupOperationBases($acceptKey, $group);
        $generation = ++$this->subscriptionSequence;
        if (!isset($this->groups[$acceptKey])) {
            $this->groups[$acceptKey] = [];
            $this->groupConnectionSlots[$acceptKey] = $generation;
            $this->groupConnections[$generation] = $acceptKey;
            $this->groupConnectionIndex->add($generation);
            $this->groupNameIndexes[$acceptKey] = new SignalHoldIndex();
        }
        if (!isset($this->groups[$acceptKey][$group])) {
            $this->groupNameSlots[$acceptKey][$group] = $generation;
            $this->groupNames[$acceptKey][$generation] = $group;
            $this->groupNameIndexes[$acceptKey]->add($generation);
        }
        $this->groupGenerations[$acceptKey][$group] = $generation;
        $this->groups[$acceptKey][$group] = new GroupSubscription($params);
        $this->groupRevisions[$acceptKey] = $generation;
    }

    /**
     * Merges new params into an existing group subscription.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     * @param array<string, mixed> $params Params to merge on top of the current ones
     * @throws GroupSubscriptionNotFoundException If the group is not currently subscribed
     */
    public function updateGroupSubscription(string $acceptKey, string $group, array $params): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->groups[$acceptKey][$group])) {
            throw new GroupSubscriptionNotFoundException($acceptKey, $group);
        }

        $this->invalidateGroupOperationBases($acceptKey, $group);
        $this->groups[$acceptKey][$group] = $this->groups[$acceptKey][$group]->withMergedParams($params);
        $this->groupGenerations[$acceptKey][$group] = ++$this->subscriptionSequence;
        $this->groupRevisions[$acceptKey] = $this->subscriptionSequence;
    }

    /**
     * An absent name lookup may keep its ordinary refusal only while this set is unchanged.
     * A later join never inherits the authority of a lookup that found no original membership.
     *
     * @param string $acceptKey Original connection
     * @return ?int Membership-set revision, absent for an empty set
     */
    public function groupSubscriptionRevision(string $acceptKey): ?int
    {
        return $this->groupRevisions[$acceptKey] ?? null;
    }

    /**
     * @param string $acceptKey Original connection
     * @param string $group Exact name or absent lookup prefix
     * @param bool $prefix Whether descendant names also invalidate the observation
     * @return GroupOperationBasis Shared observation for the current name lifetime
     */
    public function groupOperationBasis(string $acceptKey, string $group, bool $prefix): GroupOperationBasis
    {
        $observations = &$this->groupOperationNames;
        if ($prefix) {
            $observations = &$this->groupOperationPrefixes;
        }
        $basis = ($observations[$acceptKey][$group] ?? null)?->get();
        if ($basis === null) {
            $basis = new GroupOperationBasis(WeakReference::create($this), $acceptKey, $group, $prefix);
            $observations[$acceptKey][$group] = WeakReference::create($basis);
        }
        return $basis;
    }

    /**
     * @param GroupOperationBasis $basis Captured local observation
     * @return bool Whether this exact observation still occupies its name slot
     */
    public function isCurrentGroupOperationBasis(GroupOperationBasis $basis): bool
    {
        return ($basis->prefix ? ($this->groupOperationPrefixes[$basis->acceptKey][$basis->group] ?? null)
            : ($this->groupOperationNames[$basis->acceptKey][$basis->group] ?? null))?->get() === $basis;
    }

    /** @param GroupOperationBasis $basis Last observer retiring; a newer observation keeps its own slot */
    public function releaseGroupOperationBasis(GroupOperationBasis $basis): void
    {
        $observations = &$this->groupOperationNames;
        if ($basis->prefix) {
            $observations = &$this->groupOperationPrefixes;
        }
        if (($observations[$basis->acceptKey][$basis->group] ?? null)?->get() === $basis) {
            unset($observations[$basis->acceptKey][$basis->group]);
            if ($observations[$basis->acceptKey] === []) {
                unset($observations[$basis->acceptKey]);
            }
        }
    }

    /**
     * Resolves which group this connection holds under a name the client wrote.
     *
     * The client names a group of "my" entity WITHOUT the entity - the server built the full
     * name at the join out of the identity behind the socket ({@see GroupAddressSource}) - so a
     * later frame about that membership arrives naming its head. Matching it back is a read of
     * what this connection actually holds rather than a second build of the name: rebuilding
     * would ask the identity seam again, and an identity that changed in between would name a
     * membership this connection never had.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group name as the client wrote it
     * @return ?string Full group name this connection holds, or null when it holds none
     */
    public function groupSubscriptionName(string $acceptKey, string $group): ?string
    {
        $walk = $this->groupNameCandidates($acceptKey, $group);
        foreach ($walk as $_) {
        }

        return $walk->getReturn();
    }

    /**
     * @param string $acceptKey Connection whose own memberships are being searched
     * @param string $group Name written by the browser
     * @return Generator<int, void, void, ?string> Bounded original-membership visits returning the first live full name
     */
    public function groupNameCandidates(string $acceptKey, string $group): Generator
    {
        return $this->walkGroupNames(
            ($this->groupNameIndexes[$acceptKey] ?? new SignalHoldIndex())->candidates(),
            $acceptKey,
            $group,
            $this->subscriptionSequence,
            $this->groupNameSlots[$acceptKey][$group] ?? null,
        );
    }

    /**
     * Removes a group subscription, dropping the client entry when it becomes empty.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group identifier
     */
    public function unsubscribeFromGroup(string $acceptKey, string $group): void
    {
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->groups[$acceptKey][$group])) {
            return;
        }

        $this->invalidateGroupOperationBases($acceptKey, $group);
        $slot = $this->groupNameSlots[$acceptKey][$group];
        $this->groupNameIndexes[$acceptKey]->remove($slot);
        unset($this->groupNameSlots[$acceptKey][$group], $this->groupNames[$acceptKey][$slot],
            $this->groupGenerations[$acceptKey][$group], $this->groups[$acceptKey][$group]);
        $this->groupRevisions[$acceptKey] = ++$this->subscriptionSequence;
        if ($this->groups[$acceptKey] === []) {
            $this->retireGroupMemberships($acceptKey);
        }
    }

    /**
     * Removes every group subscription of a client and nothing else.
     *
     * The person behind a connection changing ends every group membership it held
     * (HIL-1284); the page subscription, table windows, facet requests, focus and
     * delivery marks belong to the page and stay, so this is not {@see unsubscribeFromAll()}.
     * Explicit leave_all revokes all pending group operations even when no memberships remain.
     *
     * @param string $acceptKey Client accept key
     */
    public function unsubscribeFromAllGroups(string $acceptKey): void
    {
        if ($acceptKey === '') {
            return;
        }

        if (isset($this->groupOperationNames[$acceptKey])) {
            $this->cleanup->retire($this->groupOperationNames[$acceptKey]);
        }
        if (isset($this->groupOperationPrefixes[$acceptKey])) {
            $this->cleanup->retire($this->groupOperationPrefixes[$acceptKey]);
        }
        unset($this->groupOperationNames[$acceptKey], $this->groupOperationPrefixes[$acceptKey]);
        $this->retireGroupMemberships($acceptKey);
    }

    /**
     * Removes all page and group subscriptions for a client.
     *
     * @param string $acceptKey Client accept key
     */
    public function unsubscribeFromAll(string $acceptKey): void
    {
        $this->forgetNodePage($acceptKey);
        $this->forgetPageState($acceptKey);
        $this->unsubscribeFromAllGroups($acceptKey);
    }

    /**
     * Records that a connection's page could not be delivered, and answers whether to say so.
     *
     * A test-and-set rather than a setter with a reader beside it: the answer has to be the
     * state BEFORE this call, and two calls would leave room for the second flush of the same
     * tick to read the flag this one had not written yet.
     *
     * @param string $acceptKey Client accept key
     * @return bool Whether this connection has not been told yet, and is owed the error frame
     */
    public function markPageDeliveryFailure(string $acceptKey): bool
    {
        if ($acceptKey === '' || isset($this->pageDeliveryFailures[$acceptKey])) {
            return false;
        }

        $this->pageDeliveryFailures[$acceptKey] = true;

        return true;
    }

    /**
     * Reads whether the subscription is owed a whole page, without reserving or paying it.
     *
     * @param string $acceptKey Client accept key
     * @return bool Whether the page is owed whole
     */
    public function hasPageDeliveryFailure(string $acceptKey): bool
    {
        return isset($this->pageDeliveryFailures[$acceptKey]);
    }

    /**
     * Clears the mark after a delivery succeeds, and answers whether one was standing.
     *
     * The answer is what the caller owes the connection: a subscription that was told its page
     * failed had its page scope wiped by the client's own refusal handling, so the delta this
     * delivery is carrying would land on nothing. It is owed a full snapshot instead, and only
     * a subscription that was actually told is.
     *
     * @param string $acceptKey Client accept key
     * @return bool Whether a failure was standing, and the connection is owed a full snapshot
     */
    public function clearPageDeliveryFailure(string $acceptKey): bool
    {
        if (!isset($this->pageDeliveryFailures[$acceptKey])) {
            return false;
        }

        unset($this->pageDeliveryFailures[$acceptKey]);

        return true;
    }

    /**
     * Binds the DB row an open page is about, or clears it when the page names none.
     *
     * An empty accept key is ignored, the way a page subscription for one is.
     *
     * @param string $acceptKey Client accept key
     * @param ?PageResource $resource Row the answer named, or null when the page names none
     */
    public function bindPageResource(string $acceptKey, ?PageResource $resource): void
    {
        if ($acceptKey === '') {
            return;
        }
        if ($resource === null) {
            unset($this->pageResources[$acceptKey]);

            return;
        }

        $this->pageResources[$acceptKey] = $resource;
    }

    /**
     * Returns the DB row an open page is about, or null when the page names none.
     *
     * @param string $acceptKey Client accept key
     * @return ?PageResource Bound row, or null when this connection's page names none
     */
    public function pageResource(string $acceptKey): ?PageResource
    {
        return $this->pageResources[$acceptKey] ?? null;
    }

    /**
     * Drops the DB row bound to a connection's open page.
     *
     * @param string $acceptKey Client accept key
     */
    public function clearPageResource(string $acceptKey): void
    {
        unset($this->pageResources[$acceptKey]);
    }

    /**
     * Records that one window is owed whole, and answers whether this call set the debt.
     *
     * A test-and-set: true when this call set the debt, false when it already stood or the accept
     * key is empty. The freeze reads the answer to decide whether to say the window froze and
     * whether to write the line — nothing else.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key of the window
     * @return bool Whether this call set the debt
     */
    public function oweTableWindow(string $acceptKey, string $tableKey): bool
    {
        if ($acceptKey === '' || isset($this->tableWindowDebts[$acceptKey][$tableKey])) {
            return false;
        }

        $this->tableWindowDebts[$acceptKey][$tableKey] = true;

        return true;
    }

    /**
     * Answers whether one window is owed whole, without clearing the debt.
     *
     * A read rather than a test-and-clear: the catch-up that reads it may fail to build the window,
     * and a debt cleared before the window left would let the next delivery send a delta onto rows
     * the connection never got back.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key of the window
     * @return bool Whether the window is owed whole
     */
    public function isTableWindowOwed(string $acceptKey, string $tableKey): bool
    {
        return isset($this->tableWindowDebts[$acceptKey][$tableKey]);
    }

    /**
     * Clears one window's debt once a built window is on its way to the tab, or the window is gone.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key of the window
     * @return bool Whether the debt was standing
     */
    public function clearTableWindowDebt(string $acceptKey, string $tableKey): bool
    {
        if (!isset($this->tableWindowDebts[$acceptKey][$tableKey])) {
            return false;
        }

        unset($this->tableWindowDebts[$acceptKey][$tableKey]);
        if ($this->tableWindowDebts[$acceptKey] === []) {
            unset($this->tableWindowDebts[$acceptKey]);
        }

        return true;
    }

    /**
     * Stores or replaces a connection's viewport for one table.
     *
     * @param string $acceptKey Client accept key
     * @param TableViewportSubscription $viewport Table viewport descriptor
     */
    public function setTableViewport(string $acceptKey, TableViewportSubscription $viewport): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->tableViewports[$acceptKey][$viewport->tableKey] = $viewport;
    }

    /**
     * Returns a connection's viewport for one table, or null when not set.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     * @return ?TableViewportSubscription Stored viewport or null
     */
    public function getTableViewport(string $acceptKey, string $tableKey): ?TableViewportSubscription
    {
        return $this->tableViewports[$acceptKey][$tableKey] ?? null;
    }

    /**
     * Returns all table viewports held by a connection, keyed by table key.
     *
     * @param string $acceptKey Client accept key
     * @return array<string, TableViewportSubscription> Viewports keyed by table key
     */
    public function getTableViewports(string $acceptKey): array
    {
        return $this->tableViewports[$acceptKey] ?? [];
    }

    /**
     * Removes a connection's viewport for one table.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     */
    public function forgetTableViewport(string $acceptKey, string $tableKey): void
    {
        unset($this->tableViewports[$acceptKey][$tableKey]);
        if (($this->tableViewports[$acceptKey] ?? null) === []) {
            unset($this->tableViewports[$acceptKey]);
        }
        $this->forgetTableFacets($acceptKey, $tableKey);
        $this->clearTableFocus($acceptKey, $tableKey);
        $this->clearTableWindowDebt($acceptKey, $tableKey);
    }

    /**
     * Stores or replaces the options a connection asked counts beside, for one table's filters.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key the options are for
     * @param array<string, list<int|float|string|bool>> $facets Options to count, by filter key
     */
    public function setTableFacets(string $acceptKey, string $tableKey, array $facets): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->tableFacetRequests[$acceptKey][$tableKey] = $facets;
    }

    /**
     * Returns the options a connection asked counts beside for one table, empty when it asked for none.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     * @return array<string, list<int|float|string|bool>> Options to count, by filter key
     */
    public function getTableFacets(string $acceptKey, string $tableKey): array
    {
        return $this->tableFacetRequests[$acceptKey][$tableKey] ?? [];
    }

    /**
     * Removes the options a connection asked counts beside for one table.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     */
    public function forgetTableFacets(string $acceptKey, string $tableKey): void
    {
        unset($this->tableFacetRequests[$acceptKey][$tableKey]);
        if (($this->tableFacetRequests[$acceptKey] ?? null) === []) {
            unset($this->tableFacetRequests[$acceptKey]);
        }
    }

    /**
     * Stores or replaces the row a connection holds in focus for one table, for an open dialog.
     *
     * One row per table per connection: a dialog opens over one row, and a second focus on the same
     * table is a second dialog, which replaces the first.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key the row belongs to
     * @param string $rowKey Row the connection holds in focus
     */
    public function setTableFocus(string $acceptKey, string $tableKey, string $rowKey): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->tableFocus[$acceptKey][$tableKey] = $rowKey;
    }

    /**
     * Returns the row a connection holds in focus for one table, or null when it holds none.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     * @return ?string Row the connection holds in focus, or null when it holds none for this table
     */
    public function getTableFocus(string $acceptKey, string $tableKey): ?string
    {
        return $this->tableFocus[$acceptKey][$tableKey] ?? null;
    }

    /**
     * Releases the row a connection held in focus for one table.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     */
    public function clearTableFocus(string $acceptKey, string $tableKey): void
    {
        unset($this->tableFocus[$acceptKey][$tableKey]);
        if (($this->tableFocus[$acceptKey] ?? null) === []) {
            unset($this->tableFocus[$acceptKey]);
        }
    }

    /**
     * Accept keys currently subscribed to a page, optionally filtered by one route param.
     *
     * @param string $page Page identifier to match subscriptions against
     * @param ?string $paramKey Route param key to filter on, or null for no filter
     * @param ?string $paramValue Expected route param value (compared as string), or null for no filter
     * @return list<string> Accept keys subscribed to the page
     */
    public function getAcceptKeysForPage(string $page, ?string $paramKey = null, ?string $paramValue = null): array
    {
        $keys = [];
        foreach ($this->pages as $acceptKey => $subscription) {
            if ($subscription->page !== $page) {
                continue;
            }
            if ($paramKey === null || $paramValue === null) {
                $keys[] = $acceptKey;
                continue;
            }
            if ($subscription->paramEquals($paramKey, $paramValue)) {
                $keys[] = $acceptKey;
            }
        }

        return $keys;
    }

    /**
     * Page subscriptions mirrored here, in browser-config shape.
     *
     * @return array<string, array{page: string, params: array<string, string>}> Subscriptions keyed by accept key
     */
    public function getPageSubscriptions(): array
    {
        $subscriptions = [];
        foreach ($this->pages as $acceptKey => $subscription) {
            if ($acceptKey === '' || $subscription->page === '') {
                continue;
            }

            $subscriptions[$acceptKey] = [
                SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY => $subscription->page,
                SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY => $subscription->stringParams(),
            ];
        }

        return $subscriptions;
    }

    /**
     * A copy-on-write snapshot of immutable records for a source master's bounded recovery walk.
     * The registry remains the only live owner; callers must recheck each record before use.
     *
     * @return array<string, PageSubscription> Subscription records keyed by accept key
     */
    public function pageSubscriptionRecords(): array
    {
        return $this->pages;
    }

    /**
     * @param string $agentType Serving agent type
     * @param ?string $agentIndex Serving agent index, or null for an unindexed agent
     * @return list<string> Accept keys whose page is bound to that agent
     */
    public function acceptKeysServedBy(string $agentType, ?string $agentIndex): array
    {
        $acceptKeys = [];
        foreach ($this->pages as $acceptKey => $subscription) {
            if ($subscription->agentType === $agentType && $subscription->agentIndex === $agentIndex) {
                $acceptKeys[] = $acceptKey;
            }
        }

        return $acceptKeys;
    }

    /**
     * @return list<string> Unique accept keys from page and group subscriptions
     */
    public function getSubscribedAcceptKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->pages),
            array_keys($this->groups),
        )));
    }

    /**
     * @return list<string> Accept keys holding any page subscription
     */
    public function pageAcceptKeys(): array
    {
        return array_keys($this->pages);
    }

    /**
     * @param string $group Group identifier
     * @return list<string> Accept keys subscribed to the group
     */
    public function acceptKeysForGroup(string $group): array
    {
        $keys = [];
        foreach ($this->groups as $acceptKey => $groups) {
            if (isset($groups[$group])) {
                $keys[] = $acceptKey;
            }
        }

        return $keys;
    }

    /**
     * Captures the scalar connection tail and incarnation cutoff before first advancement.
     * New joins never extend the cohort, and equal params never revive an old membership.
     *
     * @param string $group Group to match
     * @return Generator<string, ?GroupSubscription> Original key and still-current membership, or null
     */
    public function groupSubscriptionCandidates(string $group): Generator
    {
        return $this->walkGroupSubscriptions($this->groupConnectionIndex->candidates(), $group, $this->subscriptionSequence);
    }

    /**
     * @param string $acceptKey Original subscribed connection
     * @param string $group Original full group name
     * @param GroupSubscription $subscription Captured immutable membership
     * @return bool Whether this exact membership still authorizes delivery
     */
    public function isCurrentGroupSubscription(string $acceptKey, string $group, GroupSubscription $subscription): bool
    {
        return ($this->groups[$acceptKey][$group] ?? null) === $subscription;
    }

    /**
     * Captures scalar page positions and their incarnation cutoff before first advancement.
     * Changed subscriptions yield no recipient; departed slots can be skipped without payloads.
     *
     * @return Generator<string, ?PageSubscription> Original key and still-current page, or null
     */
    public function pageSubscriptionCandidates(): Generator
    {
        return $this->walkPageSubscriptions($this->pageIndex->candidates(), $this->subscriptionSequence);
    }

    /**
     * Retires membership storage without revoking independent operations already prepared on this connection.
     *
     * @param string $acceptKey Connection whose membership indices are no longer needed
     */
    private function retireGroupMemberships(string $acceptKey): void
    {
        $slot = $this->groupConnectionSlots[$acceptKey] ?? null;
        if ($slot === null) {
            return;
        }
        $index = $this->groupNameIndexes[$acceptKey];
        $memberships = [
            $this->groups[$acceptKey],
            $this->groupNameSlots[$acceptKey],
            $this->groupNames[$acceptKey],
            $this->groupGenerations[$acceptKey],
        ];
        $this->groupConnectionIndex->remove($slot);
        unset($this->groups[$acceptKey], $this->groupConnectionSlots[$acceptKey], $this->groupConnections[$slot],
            $this->groupNameIndexes[$acceptKey], $this->groupNameSlots[$acceptKey], $this->groupNames[$acceptKey],
            $this->groupGenerations[$acceptKey], $this->groupRevisions[$acceptKey]);
        $this->cleanup->retire($memberships);
        $this->cleanup->schedule(self::releaseGroupNameIndex($index));
    }

    /**
     * @param string $acceptKey Connection whose membership changed
     * @param string $group Changed full name
     */
    private function invalidateGroupOperationBases(string $acceptKey, string $group): void
    {
        unset($this->groupOperationNames[$acceptKey][$group]);
        if (($this->groupOperationNames[$acceptKey] ?? null) === []) {
            unset($this->groupOperationNames[$acceptKey]);
        }
        $prefix = $group;
        while (true) {
            unset($this->groupOperationPrefixes[$acceptKey][$prefix]);
            $separator = strrpos($prefix, ':');
            if ($separator === false) {
                break;
            }
            $prefix = substr($prefix, 0, $separator);
        }
        if (($this->groupOperationPrefixes[$acceptKey] ?? null) === []) {
            unset($this->groupOperationPrefixes[$acceptKey]);
        }
    }

    /** @param string $acceptKey Exact connection whose page state ends before any physical release */
    private function forgetPageState(string $acceptKey): void
    {
        $slot = $this->pageSlots[$acceptKey] ?? null;
        if ($slot !== null) {
            $this->pageIndex->remove($slot);
            unset($this->pageSlots[$acceptKey], $this->pageConnections[$slot], $this->pageGenerations[$acceptKey]);
        }
        $retired = [
            $this->pages[$acceptKey] ?? null,
            $this->tableViewports[$acceptKey] ?? [],
            $this->tableFacetRequests[$acceptKey] ?? [],
            $this->tableFocus[$acceptKey] ?? [],
            $this->pageResources[$acceptKey] ?? null,
            $this->tableWindowDebts[$acceptKey] ?? [],
        ];
        unset($this->pages[$acceptKey], $this->tableViewports[$acceptKey], $this->tableFacetRequests[$acceptKey],
            $this->tableFocus[$acceptKey], $this->pageDeliveryFailures[$acceptKey], $this->pageResources[$acceptKey],
            $this->tableWindowDebts[$acceptKey]);
        $this->cleanup->retire($retired);
    }

    /** @param string $acceptKey Subscription whose node-addressed incarnation ended */
    private function forgetNodePage(string $acceptKey): void
    {
        $nodeId = $this->pageNodes[$acceptKey] ?? null;
        if ($nodeId === null) {
            return;
        }
        $slot = $this->nodePageSlots[$acceptKey];
        $this->pagesByNode[$nodeId]->remove($slot);
        if ($this->pagesByNode[$nodeId]->isEmpty()) {
            unset($this->pagesByNode[$nodeId]);
        }
        unset($this->nodePageConnections[$slot], $this->nodePageSlots[$acceptKey], $this->pageNodes[$acceptKey]);
    }

    /**
     * @param Generator<int, int> $slots Original finite outer-roster positions
     * @param string $group Group to match
     * @param int $cutoff Last allowed immutable membership incarnation
     * @return Generator<string, ?GroupSubscription> Still-present original connections in insertion order
     */
    private function walkGroupSubscriptions(Generator $slots, string $group, int $cutoff): Generator
    {
        foreach ($slots as $slot) {
            $acceptKey = $this->groupConnections[$slot];
            $generation = $this->groupGenerations[$acceptKey][$group] ?? null;
            yield $acceptKey => $generation !== null && $generation <= $cutoff ? $this->groups[$acceptKey][$group] : null;
        }
    }

    /**
     * @param Generator<int, int> $slots Original scalar membership insertion positions
     * @param string $acceptKey Original connection
     * @param string $group Name written by the browser
     * @param int $cutoff Last allowed membership incarnation
     * @param ?int $exactSlot Original exact name position, absent when it was not joined
     * @return Generator<int, void, void, ?string> First still-current matching full name, or null
     */
    private function walkGroupNames(Generator $slots, string $acceptKey, string $group, int $cutoff, ?int $exactSlot): Generator
    {
        yield;
        if ($exactSlot !== null && ($this->groupNameSlots[$acceptKey][$group] ?? null) === $exactSlot
            && $this->groupGenerations[$acceptKey][$group] <= $cutoff) {
            return $group;
        }
        $prefix = $group . ':';
        foreach ($slots as $slot) {
            $name = $this->groupNames[$acceptKey][$slot] ?? null;
            if ($name !== null && str_starts_with($name, $prefix) && $this->groupGenerations[$acceptKey][$name] <= $cutoff) {
                return $name;
            }
            yield;
        }
        return null;
    }

    /**
     * @param Generator<int, int> $slots Original scalar page insertion positions
     * @param int $cutoff Last allowed immutable page incarnation
     * @return Generator<string, ?PageSubscription> Still-present original connections in insertion order
     */
    private function walkPageSubscriptions(Generator $slots, int $cutoff): Generator
    {
        foreach ($slots as $slot) {
            $acceptKey = $this->pageConnections[$slot];
            yield $acceptKey => $this->pageGenerations[$acceptKey] <= $cutoff ? $this->pages[$acceptKey] : null;
        }
    }

    /**
     * @param SignalHoldIndex $index Detached scalar membership positions, no longer authorizing joins
     * @return Generator<int, void> Effect-free admission followed by one logarithmic node removal per step
     */
    private static function releaseGroupNameIndex(SignalHoldIndex $index): Generator
    {
        yield;
        foreach ($index->candidates() as $slot) {
            $index->remove($slot);
            yield;
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageBinding;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceKind;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\DTO\BrowserTableWindow;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableFacetsDTO;
use Hilos\Database\Context\DbContext;
use Hilos\HilosException;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableViewportAnnounceDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableRowPlacement;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Utils\Logger;
use Throwable;
use ArrayAccess;
use Closure;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Base browser-facing context.
 *
 * Project subclasses provide topology and computed-field hooks when needed.
 */
abstract class BrowserContext
{
    /**
     * Machine-readable code the delivery-failure frame carries.
     *
     * The same code the subscribe path answers an unexpected failure with, because the client
     * is looking at the same thing: a page it cannot have, for a reason that is not about it.
     */
    private const string DELIVERY_FAILURE_CODE = 'internal_error';

    /**
     * Wording the delivery-failure frame carries, scrubbed of everything about this node.
     *
     * Deliberately not the subscribe path's wording. A subscription that failed never opened;
     * a delivery that failed means the page the person is looking at has stopped being fed, and
     * the two are different things to be told.
     */
    private const string DELIVERY_FAILURE_MESSAGE = 'Internal error while delivering the page';

    protected SourceChangeSet $changes;

    /**
     * Rows whose source freshness moved this tick, by RT collection and state id.
     *
     * A set and not a list: the same row can be named by a link dropping and by the snapshot
     * that follows it inside one tick, and a reader is owed one answer either way.
     *
     * @var array<string, array<string, true>>
     */
    private array $staleness = [];

    /** @var class-string<Hilos> Active project facade class for topology registry reads. */
    private string $hilosClass = Hilos::class;

    /**
     * Starts with an empty worker-local browser source-change buffer.
     */
    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    /**
     * Binds this browser context to the active project facade.
     *
     * @param class-string<Hilos> $hilosClass Active project facade class
     */
    final public function bindHilosFacade(string $hilosClass): void
    {
        $this->hilosClass = $hilosClass;
    }

    /**
     * Records a DB/RT sync fact in the worker-local browser buffer.
     *
     * @param SourceChange $change Source change to dispatch on the next browser flush
     */
    public function record(SourceChange $change): void
    {
        $this->changes->add($change);
    }

    /**
     * Records that the freshness of some rows of one RT collection has moved.
     *
     * Kept beside {@see self::record()} rather than travelling as a source change, because it
     * is not one: no field of those rows changed, and the trigger lists a table declares are
     * about fields — a change carrying an empty row would be swallowed by them without a word
     * (HIL-800). It is buffered the same way and flushed by the same
     * {@see self::flushToSignalRouter()}, so a freeze and the writes around it reach a window
     * in one tick and in the order they happened.
     *
     * Both directions ride this: the list a row ends up with is read off the store when the
     * flush builds the answer, so a thaw is the same call with the same rows.
     *
     * @param string $collectionKey RT collection whose rows froze or thawed
     * @param list<string> $stateIds Rows of that collection whose freshness moved
     */
    public function recordSourceStaleness(string $collectionKey, array $stateIds): void
    {
        foreach ($stateIds as $stateId) {
            $this->staleness[$collectionKey][$stateId] = true;
        }
    }

    /**
     * Reports whether any browser source changes are buffered.
     *
     * @return bool Whether the browser context has source changes waiting for flush
     */
    public function hasChanges(): bool
    {
        return !$this->changes->isEmpty();
    }

    /**
     * Judges one page subscription request before the page builds anything.
     *
     * The subscription path's entry into the guard core, called by
     * {@see PageSignalRouter::dispatchPageSubscribe} ahead of onSubscribe so a session
     * the guards refuse costs no payload build and no page side effect. The freeze is
     * read first, then the params and the guards the page declared.
     *
     * A page with no browser config declares neither, and passes on the freeze alone —
     * which is the point of judging here rather than inside the snapshot: the snapshot
     * has nothing to send for such a page and used to leave it unjudged entirely.
     *
     * The state the page draws rows from is judged before its own guards and not after,
     * because the guards read those rows too: a guard reaching a collection this process
     * does not hold yet would be refused by the read guard and answer the subscription an
     * internal error, where the truth is that the page is a moment early.
     *
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageServiceUnavailableException When the freeze locks this connection out, or a collection the page reads is not here yet
     * @throws PageSubscriptionException When a param or a guard rejects the subscription, or a declaration is malformed
     */
    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if ($this->protectedModeLocksOut($acceptKey)) {
            throw new PageServiceUnavailableException();
        }

        $this->assertPageSourcesReady($page);

        $pageConfig = $this->pageConfig($page);
        if ($pageConfig === null) {
            return;
        }

        $this->validateParams($pageConfig->paramConfigs(), $params);
        $this->assertGuardConfigs($pageConfig, $acceptKey, $params->toArray());
    }

    /**
     * Refuses a subscription this process cannot yet answer out of its own copy.
     *
     * The worker takes up what the page reads and waits for it before the frame is judged
     * ({@see WorkerManager::takeUpPageSources}), so reaching this refusal means the state did not arrive inside
     * that wait. The answer is the transient one the freeze already uses: nothing about the
     * request is wrong, the process is simply a moment early, and the client is told to try
     * the page again rather than shown a page built out of rows nobody has.
     *
     * Which collection is missing stays in the log. The message crosses the wire, and the
     * wire protocol keeps engine detail off it.
     *
     * @param string $page Page name from the subscription request
     * @throws PageServiceUnavailableException When a collection the page reads has not arrived here yet
     */
    private function assertPageSourcesReady(string $page): void
    {
        foreach ($this->rtSourceKeysOfPage($page) as $collectionKey) {
            if (SourceInterestRegistry::isReady(SourceChange::KIND_RT, $collectionKey)) {
                continue;
            }

            Logger::error(
                'Page subscription is ahead of its state: '
                    . "page={$page}, collection={$collectionKey}, "
                    . 'declared=' . (SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, $collectionKey) ? 'yes' : 'no'),
            );

            throw new PageServiceUnavailableException();
        }
    }

    /**
     * Whether a page's verdict can turn on who is behind the connection (HIL-621).
     *
     * Asked by {@see PageSignalRouter::dispatchPageAccessReassess} to skip the pages a
     * rights change cannot possibly move. The declared ACCESS_LEVEL is the caller's half
     * of the question; this is the other half - the page's own browser guards, which are
     * where a PUBLIC page can still refuse a particular person.
     *
     * A page with no browser config declares no guards and answers false, which is the
     * whole point: without this the sweep would push a full page answer into every open
     * chat tab of the person on every grant.
     *
     * @param string $page Page name from the subscription mirror
     * @return bool Whether the page declares at least one browser guard
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    public function pageAccessDependsOnIdentity(string $page): bool
    {
        $pageConfig = $this->pageConfig($page);

        return $pageConfig !== null && $pageConfig->guardConfigs() !== [];
    }

    /**
     * Sends a full browser snapshot for one page subscription.
     *
     * The snapshot uses the same page/table browser config as incremental
     * source-change delivery, addressed directly to the subscribing accept key.
     * It judges nothing: the params and the guards are settled before the page is
     * asked for anything, by {@see self::assertSubscriptionAccess}.
     *
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws InvalidArgumentException When the page-response signal cannot be named
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $pageConfig = $this->pageConfig($page);
        if ($pageConfig === null) {
            return;
        }

        if ($pageConfig->signalName === null) {
            return;
        }

        $pageParams = $params->toArray();
        $reportedWindows = Hilos::$sr->takeReportedTableWindows($acceptKey);

        $tables = [];
        $windows = [];
        foreach ($this->pageBindings($page) as $pageBinding) {
            $browserKey = $pageBinding->browserKey;

            $viewportTable = $this->viewportTable($browserKey);
            if ($viewportTable !== null) {
                // A viewport table answers with a window rather than with the whole set, and
                // that window now travels in this same frame: the second round trip it used to
                // cost was the one known exception to "one subscription answers everything the
                // page renders", and this leaf is the debt that rule named (HIL-641, HIL-642).
                $window = $this->subscribeTableWindow(
                    page: $page,
                    acceptKey: $acceptKey,
                    tableKey: $browserKey,
                    table: $viewportTable,
                    reported: $reportedWindows[$browserKey] ?? null,
                );
                if ($window !== null) {
                    $windows[$browserKey] = $window;
                }

                continue;
            }

            $browserConfig = $this->browserConfig($browserKey);
            if ($browserConfig === null || $browserConfig->isEmpty()) {
                continue;
            }

            $browserParams = $this->browserParams($pageBinding, $acceptKey, $pageParams);
            $tables[$browserKey] = [
                BrowserPageSignalData::rows => $this->buildBrowserSnapshotRows(
                    browserKey: $browserKey,
                    browserConfig: $browserConfig,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    browserParams: $browserParams,
                ),
            ];
        }

        $payload = $this->pagePayloadFromBrowser($tables, $windows);
        if ($payload->isEmpty()) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            signalData: new WebSocketSignalData(
                data: new PageResponseSignalData($page, $payload),
                targetAcceptKey: $acceptKey,
            ),
        );

        // The counts beside a table's filter options follow the answer rather than ride in it: they
        // are a frame of their own, and the client has somewhere to put them only once the answer
        // has opened the table's window.
        foreach (array_keys($windows) as $tableKey) {
            $viewport = Hilos::$sr->getTableViewport($acceptKey, (string) $tableKey);
            if ($viewport !== null) {
                $this->sendTableFacetCounts($page, $acceptKey, $viewport);
            }
        }
    }

    /**
     * Builds and sends one table's window snapshot to a subscribing connection.
     *
     * Runs the table's windowed query for the viewport descriptor, serializes the
     * window rows, replies a table_window signal addressed to the accept key, and
     * records the delivered row-id keys on the viewport so live deltas can be
     * scoped to them. Any viewport table is served here — a self-snapshot table
     * (settings) or a source-fanned one (the Hilos users table) alike.
     *
     * The answer is the verdict on one delivery, not a success flag: a window that does
     * not arrive is a normal outcome here — a guard-failed subscription is kept alive
     * and served nothing until the guard passes. It is answered rather than swallowed
     * because the caller is owed something to log ({@see PageSignalRouter::dispatchTableViewport}):
     * refusing a client's own viewport frame in silence left no trace anywhere on the
     * server, which is why this door's share of the identity race was found by accident
     * twice and never by its own log line. The update door settled the same question the
     * other way ({@see PageSignalRouter::dispatchPageUpdateSubscription}): it answers no
     * bool at all any more, and writes a log line of its own for each outcome the single
     * bit used to blur together.
     *
     * @param string $page Page the table belongs to
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param TableViewportSubscription $viewport Window descriptor; its delivered rows are updated
     * @return bool Whether the window was delivered to the connection
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     * @throws HilosException When the table's own sources refuse the reads its rows need
     * @throws InvalidArgumentException When the table-window signal cannot be named
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        if (Hilos::$sr === null) {
            return false;
        }

        $table = Hilos::$table?->get($viewport->tableKey);
        if (!$table instanceof ViewportTable) {
            return false;
        }

        // Re-check the page guards before serving the window: a guard-failed
        // subscription (kept alive for live-promotion) gets no table data while the
        // guard fails, and resumes the instant it passes.
        //
        // Reading the declaration is inside the trap, not before it: this path is
        // dispatched bare (PageSignalRouter::dispatchTableViewport, and the
        // TABLE_VIEWPORT case in WorkerManager), so a broken declaration would reach
        // the worker's exit and crash-loop it the same way the reactive fan-out would
        // — see the catch in emitBrowserSignals() for why that costs every other
        // subscriber too.
        //
        // Any failure and not only a broken declaration, for the same reason the fan-out
        // gives: the blast radius is what is being contained, and it does not depend on
        // which exception the guards happened to reach. A refused read reaches them now.
        try {
            $pageConfig = $this->pageConfig($page);
            if ($pageConfig !== null) {
                $pageParams = Hilos::$sr->getPageSubscriptions()[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];
                if (!$this->pageGuardsAllow($page, $pageConfig, $acceptKey, $pageParams)) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            Logger::error("Browser window skipped a broken declaration: page={$page}, error={$e->getMessage()}");
            $this->tellPageDeliveryFailed($page, $acceptKey);

            return false;
        }

        // The guards passed, so whatever stopped this connection's page is over. A window
        // landing on a scope the client wiped would be a table nothing holds, so the page goes
        // out whole first and the window then has somewhere to arrive.
        if (Hilos::$sr->clearPageDeliveryFailure($acceptKey)) {
            // Written here rather than handed back: this path answers a bool and has no list for
            // the worker's tick to write, so a re-send that failed would otherwise leave the
            // journal with nothing at all - the very silence the frame above exists to end.
            foreach ($this->resendWholePage($page, $acceptKey) as $contained) {
                Logger::error(
                    "Browser window could not re-send the page it owed: page={$page}, "
                    . "acceptKey={$acceptKey}, error={$contained->failure->getMessage()}",
                );
            }
        }

        $window = $this->buildTableWindow($table, $viewport, $page);
        if ($window === null) {
            return false;
        }

        $snapshot = $window->snapshot;

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalTypeConstants::TABLE_WINDOW),
            signalData: new WebSocketSignalData(
                data: new TableWindowSignalData(
                    page: $page,
                    tableKey: $viewport->tableKey,
                    rows: $window->rows,
                    totalCount: $snapshot->totalCount,
                    totalExact: $snapshot->totalExact,
                    limit: $snapshot->limit,
                    firstAnchor: $snapshot->firstAnchor,
                    lastAnchor: $snapshot->lastAnchor,
                ),
                targetAcceptKey: $acceptKey,
            ),
        );

        return true;
    }

    /**
     * Sends the counts beside the options of one table's filters to the connection that asked for them.
     *
     * The options are the ones this connection declared for the table, and the set they are counted
     * over is the one its window describes, so every number agrees with the window the reader is
     * looking at. `$only` narrows the counts to the filters whose numbers moved: changing one filter
     * moves the counts of every other filter and leaves its own where they were.
     *
     * The page guards are re-checked first, as a window re-checks them: the counts are data from the
     * table, and a subscription the guards refuse is served none of it.
     *
     * Nothing here fails the caller. A table that cannot count answers null and no frame goes out,
     * which the dropdown reads as "no numbers". A count that fails does the same and leaves a line in
     * the log: the window these numbers follow is already on its way and whole, and a number beside
     * an option is not worth it.
     *
     * @param string $page Page the table belongs to
     * @param string $acceptKey Connection the counts are for
     * @param TableViewportSubscription $viewport Window whose search and filters describe the set
     * @param ?list<string> $only Filters to count again, or null for every filter the connection declared
     * @throws InvalidArgumentException When the facet-counts signal cannot be named
     */
    public function sendTableFacetCounts(string $page, string $acceptKey, TableViewportSubscription $viewport, ?array $only = null): void
    {
        if (Hilos::$sr === null) {
            return;
        }

        $table = Hilos::$table?->get($viewport->tableKey);
        if (!$table instanceof ViewportTable) {
            return;
        }

        $wanted = Hilos::$sr->getTableFacets($acceptKey, $viewport->tableKey);
        if ($only !== null) {
            $wanted = array_intersect_key($wanted, array_flip($only));
        }
        if ($wanted === []) {
            return;
        }

        try {
            $pageConfig = $this->pageConfig($page);
            $pageParams = Hilos::$sr->getPageSubscriptions()[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];
            if ($pageConfig !== null && !$this->pageGuardsAllow($page, $pageConfig, $acceptKey, $pageParams)) {
                return;
            }

            $facets = $table->facetCounts($table->scopeSearch($this->viewportQuery($viewport)), $wanted);
        } catch (Throwable $e) {
            // Without this line a dropdown with no numbers because the count failed would look
            // exactly like one whose table never counted - the same silence the live count's row
            // question was given a line for.
            Logger::error(
                "Facet counts were not sent after the count failed: table={$viewport->tableKey}, "
                    . "page={$page}, acceptKey={$acceptKey}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}, "
                    . 'at=' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return;
        }

        if ($facets === null || $facets === []) {
            return;
        }

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_FACET_COUNTS,
            new TableFacetCountsSignalData($page, $viewport->tableKey, new TableFacetsDTO($facets)),
            $acceptKey,
        );
    }

    /**
     * Runs one window and records what it delivered to the connection that asked for it.
     *
     * Both frames that carry a window end here — the page subscription's `windows` section and
     * the table_window reply — because the work is the same on either road: ask the table for
     * the window the descriptor names, put each row into its wire shape, and tell the viewport
     * what this connection was given so a later delta can be judged against it. Two copies of
     * this would drift apart at the first change to the row shape.
     *
     * A table that cannot build its window answers null rather than throwing: a window that
     * does not arrive is a normal outcome on both roads — the page still ships without that
     * section, and the viewport reply is refused — and the line in the log is the only place
     * the refusal is said at all.
     *
     * @param ViewportTable $table Table the window is taken from
     * @param TableViewportSubscription $viewport Window descriptor; its delivered rows are updated
     * @param string $page Page the table belongs to, named in the failure line
     * @return ?BrowserTableWindow The built window, or null when the table could not build it
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     * @throws HilosException When the table's own sources refuse the reads its rows need
     */
    private function buildTableWindow(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        string $page,
    ): ?BrowserTableWindow {
        $query = $this->viewportQuery($viewport);
        try {
            $snapshot = $table->getPage($query);
        } catch (Throwable $e) {
            // The window simply does not arrive, and without this line nothing
            // anywhere says so: a row that refuses its own payload would trade
            // one invisibility for another.
            Logger::error(
                "Browser window skipped a table that failed to build: table={$viewport->tableKey}, "
                . "page={$page}, error={$e->getMessage()}",
            );

            return null;
        }

        $rows = [];
        $wireRows = [];
        $rowAnchors = [];
        foreach ($snapshot->rows as $row) {
            if (!$row instanceof AbstractTableRow) {
                continue;
            }
            $browserRow = $table->browserRow($row);
            $wireRow = $this->browserRowToWire($browserRow);
            $rowKey = (string) $browserRow[BrowserPageSignalData::rowKey];
            $rows[] = $wireRow;
            $wireRows[$rowKey] = $wireRow;
            $rowAnchors[$rowKey] = $table->anchorForRow($row, $query);
        }

        $viewport->recordWindow(
            $wireRows,
            $snapshot->totalCount,
            $snapshot->totalExact,
            $snapshot->firstAnchor,
            $snapshot->lastAnchor,
            $rowAnchors,
        );

        return new BrowserTableWindow($rows, $snapshot);
    }

    /**
     * Opens one viewport table's window as part of answering a page subscription.
     *
     * The subscription is where a window is born now: a live change arriving between the
     * subscription and the first render used to have nowhere to be addressed, because the
     * viewport only existed once the client had asked for it. The window is recorded on the
     * registry BEFORE the answer goes out, so that gap is closed rather than narrowed.
     *
     * Which window it is, is settled in three steps and the first one that answers wins: the
     * descriptor this tab reported for the table, then the window this connection is already
     * holding, then what the table declares for itself. The first is a tab coming back after a
     * broken socket — it is the only side that still remembers what was on the screen. The
     * second is a page re-sent to a connection that never went anywhere, where resetting the
     * reader to the first page would be a window nobody asked for. The third is the cold entry.
     *
     * Nothing is thrown out of here: the page answers with the sections it could build, and a
     * table that could not build its window is left out of the `windows` section entirely,
     * which is the state the tab reads as "the window has not arrived yet" (HIL-781, HIL-943).
     *
     * The work this table has running rides out with the window, under the section's `progress`
     * key, so a tab opening in the middle of a run sees the bars at once instead of at the next
     * stir of a source. The key is written only when there are bars: an empty list would reach
     * the wire as a JSON array, and a table with no window has no entry to carry it in either.
     * A table that cannot name its work loses its bars and keeps its rows — a narrower
     * containment than the window's, because by then there is a window worth showing.
     *
     * @param string $page Page the table belongs to
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param string $tableKey Table key the window is for
     * @param ViewportTable $table Table the window is taken from
     * @param ?TableWindowDescriptorDTO $reported Window this tab reported holding, or null when it reported none
     * @return ?array<string, mixed> The `windows` section entry for this table, or null when it has none
     */
    private function subscribeTableWindow(
        string $page,
        string $acceptKey,
        string $tableKey,
        ViewportTable $table,
        ?TableWindowDescriptorDTO $reported,
    ): ?array {
        $viewport = $this->subscriptionViewport($acceptKey, $tableKey, $table, $reported);
        Hilos::$sr?->setTableViewport($acceptKey, $viewport);
        if ($reported !== null) {
            // The options a tab asked counts beside travel in its report for the reason its window
            // does: after a broken socket the tab is the only side that still knows them.
            Hilos::$sr?->setTableFacets($acceptKey, $tableKey, $reported->facets);
        }

        try {
            $window = $this->buildTableWindow($table, $viewport, $page);
        } catch (Throwable $e) {
            // Contained rather than propagated: this runs inside the page's own answer, and a
            // row that refuses its payload would otherwise cost the subscriber the whole page
            // instead of one table on it.
            Logger::error(
                "Browser window skipped a table whose rows refused their payload: table={$tableKey}, "
                . "page={$page}, error={$e->getMessage()}",
            );

            return null;
        }

        if ($window === null) {
            return null;
        }

        $section = [
            TableWindowSignalData::rows => $window->rows,
            TableWindowDescriptorDTO::SORT => $viewport->sort?->toArray() ?? [],
            TableWindowSignalData::limit => $window->snapshot->limit,
            TableWindowSignalData::totalCount => $window->snapshot->totalCount,
            TableWindowSignalData::totalExact => $window->snapshot->totalExact,
            TableWindowSignalData::firstAnchor => $window->snapshot->firstAnchor?->toArray(),
            TableWindowSignalData::lastAnchor => $window->snapshot->lastAnchor?->toArray(),
        ];

        try {
            $progress = $table->progressSnapshot();
        } catch (Throwable $e) {
            // Contained apart from the window and narrower than it: the rows are already built
            // and the table is worth showing without its bars, which the tab reads as nothing
            // running. Uncontained this would cost the subscriber the whole page for a bar.
            Logger::error(
                "Browser window skipped the work a table could not name: table={$tableKey}, "
                . "page={$page}, error={$e->getMessage()}",
            );

            return $section;
        }

        if ($progress !== []) {
            $section[TableProgressSignalData::progress] = array_map(
                static fn (TableProgressDTO $bar): array => $bar->toArray(),
                $progress,
            );
        }

        return $section;
    }

    /**
     * Settles which window a subscribing connection gets for one table.
     *
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param string $tableKey Table key the window is for
     * @param ViewportTable $table Table whose own declaration answers for a cold entry
     * @param ?TableWindowDescriptorDTO $reported Window this tab reported holding, or null when it reported none
     * @return TableViewportSubscription Viewport the window is built from
     */
    private function subscriptionViewport(
        string $acceptKey,
        string $tableKey,
        ViewportTable $table,
        ?TableWindowDescriptorDTO $reported,
    ): TableViewportSubscription {
        if ($reported !== null) {
            return new TableViewportSubscription(
                tableKey: $tableKey,
                filter: $reported->filter,
                sort: $reported->sort,
                limit: $reported->limit,
                anchor: $reported->anchor,
                anchorDirection: $reported->anchorDirection,
                pageIndex: $reported->pageIndex,
            );
        }

        $held = Hilos::$sr?->getTableViewport($acceptKey, $tableKey);
        if ($held !== null) {
            return $held;
        }

        // The three fields not named here are what HIL-787 already settled and nobody declares:
        // no filter, no anchor and the edge of the set, which together are the first window.
        return new TableViewportSubscription(
            tableKey: $tableKey,
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
        );
    }

    /**
     * Builds the table query for a viewport descriptor.
     *
     * Generic filter resolution: the `search` filter-map key is lifted into the
     * query search term, and the whole open filter map is carried through so a
     * table with custom filters (the delivery-logs channel/status/period filters,
     * HIL-201) resolves them into its own WHERE inside {@see TableDefinition::getPage()}.
     *
     * @param TableViewportSubscription $viewport Window descriptor
     * @return TableQueryDTO Table query for the window
     */
    private function viewportQuery(TableViewportSubscription $viewport): TableQueryDTO
    {
        $search = $viewport->filter[TableConstants::FILTER_KEY_SEARCH] ?? null;

        return new TableQueryDTO(
            search: is_string($search) ? $search : null,
            sort: $viewport->sort,
            limit: $viewport->limit,
            filter: $viewport->filter,
            anchor: $viewport->anchor,
            anchorDirection: $viewport->anchorDirection,
            pageIndex: $viewport->pageIndex,
        );
    }

    /**
     * Converts an internal browser row to its wire form.
     *
     * The internal envelope keys the source fragments under `sources`; the wire
     * row the frontend normalizer ingests keys them under `slots`. Every frame a
     * table row rides — page_response, table_window, table_viewport_delta,
     * table_viewport_append, table_viewport_own_create — goes through here, so a
     * field added to the row is on the wire in all of them or in none.
     *
     * The freshness list travels only when something in the row is frozen. A fresh
     * row is the overwhelmingly common one, and it pays nothing: no key, no bytes,
     * and no digest change to raise a content delta out of (HIL-800).
     *
     * @param array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} $browserRow Internal browser row
     * @return array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} Wire row
     */
    private function browserRowToWire(array $browserRow): array
    {
        $wireRow = [
            PagePayload::rowKey => $browserRow[BrowserPageSignalData::rowKey],
            PagePayload::slots => $browserRow[BrowserPageSignalData::sources],
        ];

        $staleSources = $this->staleSourcesOfRow($browserRow);
        if ($staleSources !== []) {
            $wireRow[PagePayload::staleSources] = $staleSources;
        }

        return $wireRow;
    }

    /**
     * Reads the frozen source keys off an already-built browser row.
     *
     * Both row builders write the same key on the same envelope — the declarative one in
     * {@see self::buildBrowserRow()}, the typed one in the table's own
     * {@see ViewportTable::browserRow()} — and both are read back here, so the two roads a
     * row can travel cannot come to disagree about what counts as frozen. A table that
     * declares nothing writes nothing, and answers an empty list.
     *
     * @param array<string, mixed> $browserRow Internal browser row
     * @return list<string> Frozen source keys of the row, empty when all of it is current
     */
    private function staleSourcesOfRow(array $browserRow): array
    {
        $staleSources = $browserRow[BrowserPageSignalData::staleSources] ?? [];
        if (!is_array($staleSources)) {
            return [];
        }

        return array_values(array_filter($staleSources, is_string(...)));
    }

    /**
     * Drains browser source changes at the end of the worker tick.
     *
     * A subscription whose fan-out failed is handed back rather than written here. The
     * worker's tick owns what a contained failure looks like in the journal and is the
     * one that offers it to the project; a record kept in two places under two sets of
     * rules is what let the master's two readers disagree about the same line.
     *
     * @return list<ContainedFailure> Subscriptions whose fan-out failed, in the order they failed
     * @throws InvalidArgumentException When a fanned-out signal cannot be named
     */
    public function flushToSignalRouter(): array
    {
        if ($this->changes->isEmpty() && $this->staleness === []) {
            return [];
        }

        try {
            $this->groupSourceChanges();

            return $this->emitBrowserSignals();
        } finally {
            // Dropped however the flush ended. A set held back because something threw is
            // the same frame again on the next tick, and on every tick after that one.
            $this->changes = new SourceChangeSet();
            $this->staleness = [];
        }
    }

    /**
     * Groups source changes before browser signal work by modifying $this->changes.
     *
     * Multiple changes for the same DB/RT source item collapse into one change
     * with later row fields taking precedence.
     */
    protected function groupSourceChanges(): void
    {
        if ($this->changes->isEmpty()) {
            return;
        }

        /** @var array<string, SourceChange> $groupedChanges */
        $groupedChanges = [];
        /** @var list<string> $groupedChangeKeys */
        $groupedChangeKeys = [];

        foreach ($this->changes->all() as $change) {
            $groupKey = $change->kind . "\0" . $change->sourceKey . "\0" . $change->sourceId;
            if (!isset($groupedChanges[$groupKey])) {
                $groupedChanges[$groupKey] = $change;
                $groupedChangeKeys[] = $groupKey;
                continue;
            }

            $groupedChanges[$groupKey] = $this->mergeSourceChange($groupedChanges[$groupKey], $change);
        }

        $this->changes = new SourceChangeSet();
        foreach ($groupedChangeKeys as $groupKey) {
            $this->changes->add($groupedChanges[$groupKey]);
        }
    }

    /**
     * Merges two source changes from the same source item.
     *
     * The later writer's origin wins, consistent with the row array_replace
     * last-wins: the connection whose value survives the merge is the one that
     * gets "own", so the loser's edit gates as pending against the winning value.
     * The action behind that write travels with it: an origin merged away from
     * one press and a request id left behind from another would name a result
     * the surviving value is not.
     *
     * @param SourceChange $current Earlier grouped source change
     * @param SourceChange $next Later source change to fold in
     * @return SourceChange Collapsed source change
     */
    private function mergeSourceChange(SourceChange $current, SourceChange $next): SourceChange
    {
        $row = $next->mutationType === TableMutationType::Create
            && $current->mutationType !== TableMutationType::Create
            ? $next->row
            : array_replace($current->row, $next->row);

        return new SourceChange(
            kind: $current->kind,
            sourceKey: $current->sourceKey,
            sourceId: $current->sourceId,
            mutationType: $this->mergeMutationType($current->mutationType, $next->mutationType),
            row: $row,
            origin: $next->origin,
            originRequestId: $next->originRequestId,
        );
    }

    /**
     * Collapses tick-local source lifecycle into one browser-visible mutation.
     *
     * @param TableMutationType $current Earlier grouped mutation type
     * @param TableMutationType $next Later mutation type to fold in
     * @return TableMutationType Browser-visible mutation type
     */
    private function mergeMutationType(TableMutationType $current, TableMutationType $next): TableMutationType
    {
        if ($next === TableMutationType::Clear || $current === TableMutationType::Clear) {
            return TableMutationType::Clear;
        }

        if ($next === TableMutationType::Delete) {
            return TableMutationType::Delete;
        }

        if ($current === TableMutationType::Create) {
            return TableMutationType::Create;
        }

        return TableMutationType::Update;
    }

    /**
     * Emits browser signals produced from grouped DB/RT source changes in $this->changes.
     *
     * A row that refuses to be built no longer leaves this method: the guard below
     * contains it per subscription. What is still raised comes from the last loop,
     * where the collected payloads are queued.
     *
     * The freshness moves buffered beside the changes are fanned out in a loop of their own,
     * over the same subscriptions and into the same accumulator, so a row that both changed
     * and froze in one tick reaches its reader in one page answer (HIL-800).
     *
     * @return list<ContainedFailure> Subscriptions whose fan-out failed, in the order they failed
     * @throws InvalidArgumentException When a fanned-out signal cannot be named
     */
    protected function emitBrowserSignals(): array
    {
        if (Hilos::$sr === null) {
            return [];
        }

        /** @var list<ContainedFailure> $contained */
        $contained = [];

        /** @var array<string, array<string, array<string, array<string, mixed>>>> $signalTables */
        $signalTables = [];
        // Per-acceptKey page-guard result, memoized for this flush: a guard-failed
        // subscription (kept alive for live-promotion) receives no reactive fan-out
        // while the guard fails, and resumes the instant it passes.
        $guardAllows = [];
        // Subscriptions this flush has already given up on. One failure per subscription and
        // not one per change: the cause is a declaration or the wiring, so every remaining
        // change of the same flush would reach it again, and the subscriber has by then been
        // told its page could not be delivered - rows arriving after that frame would deny it.
        $failedSubscriptions = [];
        foreach ($this->changes->all() as $change) {
            foreach (Hilos::$sr->getPageSubscriptions() as $acceptKey => $subscription) {
                if (isset($failedSubscriptions[$acceptKey])) {
                    continue;
                }

                $page = $subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY];
                try {
                    $this->addBrowserChange($signalTables, $guardAllows, $change, $page, $acceptKey, $subscription);
                } catch (Throwable $failure) {
                    // Whatever this one subscription's rows failed on, and for this
                    // subscription only: on the reactive path there is no catch between
                    // here and WorkerApplication's exit, so one mistake in one page's
                    // config would take the worker down on every flush and crash-loop it
                    // through ensureMinWorkers, taking every other subscriber with it.
                    // Any failure and not only a broken declaration, because the blast
                    // radius is the point and it does not depend on which exception the
                    // row-building happened to reach. The line is not written here: it
                    // belongs to the worker's tick, which is handed this list.
                    $contained[] = new ContainedFailure(
                        WorkerTickUnit::BROWSER_SUBSCRIPTION,
                        "page={$page} acceptKey={$acceptKey}",
                        $failure,
                    );
                    $this->tellPageDeliveryFailed((string) $page, (string) $acceptKey);
                    $failedSubscriptions[$acceptKey] = true;
                }
            }
        }

        foreach ($this->staleness as $collectionKey => $stateIds) {
            foreach (Hilos::$sr->getPageSubscriptions() as $acceptKey => $subscription) {
                if (isset($failedSubscriptions[$acceptKey])) {
                    continue;
                }

                $page = $subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY];
                try {
                    $this->addBrowserStaleness(
                        $signalTables,
                        $guardAllows,
                        (string) $collectionKey,
                        array_map(strval(...), array_keys($stateIds)),
                        (string) $page,
                        (string) $acceptKey,
                        $subscription,
                    );
                } catch (Throwable $failure) {
                    // Contained per subscription for the reason the loop above is: this runs on
                    // the same reactive path, with nothing between a throw and the worker's exit.
                    $contained[] = new ContainedFailure(
                        WorkerTickUnit::BROWSER_SUBSCRIPTION,
                        "page={$page} acceptKey={$acceptKey}",
                        $failure,
                    );
                    $this->tellPageDeliveryFailed((string) $page, (string) $acceptKey);
                    $failedSubscriptions[$acceptKey] = true;
                }
            }
        }

        foreach ($this->buildBrowserPayloads($signalTables) as $acceptKey => $pages) {
            if (isset($failedSubscriptions[$acceptKey])) {
                // Rows this subscription collected BEFORE it failed, and they are not going out.
                // It has been told its page could not be delivered, so a delta behind that frame
                // would land on the scope the client just wiped; and the mark it is holding must
                // survive the flush, or the next one would tell it the same thing all over again.
                continue;
            }

            foreach ($pages as $page => $tables) {
                $payload = $this->pagePayloadFromBrowser($tables);
                if ($payload->isEmpty()) {
                    continue;
                }

                $subscriber = (string) $acceptKey;
                $pageName = (string) $page;
                if (Hilos::$sr->clearPageDeliveryFailure($subscriber)) {
                    $contained = [...$contained, ...$this->resendWholePage($pageName, $subscriber)];

                    continue;
                }

                Hilos::$sr->queueSignal(
                    signalSource: new SignalSource(SignalSource::WORKER),
                    signalType: new SignalType(SignalTypeConstants::WS_USER),
                    signalName: new SignalName(SignalTypeConstants::PAGE_RESPONSE),
                    signalData: new WebSocketSignalData(
                        data: new PageResponseSignalData($pageName, $payload),
                        targetAcceptKey: $subscriber,
                    ),
                );
            }
        }

        return $contained;
    }

    /**
     * Serves a whole page to a connection whose last word about it was that it had failed.
     *
     * A delta would land on nothing. Told its page failed, the client wipes the page scope and
     * the frames it was holding, so that a stale `admin: true` cannot outlive the error - and
     * the fan-out sends only the rows that changed. Handing it those rows would replace the
     * error with a page that has three fields on it and no way to say what is missing.
     *
     * Contained the same way the row build above is, and for the same reason: this runs in the
     * second loop, where nothing stands between a throw and the worker's exit. A re-send that
     * fails is not a recovery, so the connection is told again rather than left believing the
     * page in front of it is live.
     *
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Subscriber accept key
     * @return list<ContainedFailure> The re-send's own failure, or an empty list when it landed
     */
    private function resendWholePage(string $page, string $acceptKey): array
    {
        $subscription = Hilos::$sr?->getPageSubscriptions()[$acceptKey] ?? [];
        $params = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];

        try {
            $this->subscribeSnapshot($page, $acceptKey, new PageRouteParams(is_array($params) ? $params : []));

            return [];
        } catch (Throwable $failure) {
            $this->tellPageDeliveryFailed($page, $acceptKey);

            return [new ContainedFailure(
                WorkerTickUnit::BROWSER_SUBSCRIPTION,
                "page={$page} acceptKey={$acceptKey}",
                $failure,
            )];
        }
    }

    /**
     * Tells one connection that its page could not be delivered, at most once per subscription.
     *
     * Until this frame existed the delivery paths failed in silence: the page a subscriber was
     * looking at simply stopped moving, with nothing on the wire to say so and no way for the
     * person in front of it to tell a broken node from a quiet one. It reuses the subscription
     * error frame rather than inventing a name, because the client already knows how to show
     * one, and it carries the scrubbed wording for the same reason the subscribe path does: the
     * text names the inside of the node and the subscriber can act on none of it.
     *
     * Once per subscription and not once per failure. The defect is in a declaration or in the
     * wiring, so it is present on every flush; a frame per flush would be ten a second for as
     * long as it lasted. The registry holds the bit, and a delivery that succeeds clears it.
     *
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Subscriber accept key
     */
    private function tellPageDeliveryFailed(string $page, string $acceptKey): void
    {
        if (Hilos::$sr === null || !Hilos::$sr->markPageDeliveryFailure($acceptKey)) {
            return;
        }

        try {
            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
                signalData: new WebSocketSignalData(
                    data: new PageSubscriptionErrorSignalData(
                        page: $page,
                        httpCode: HttpConstants::HTTP_INTERNAL_ERROR,
                        errorCode: self::DELIVERY_FAILURE_CODE,
                        message: self::DELIVERY_FAILURE_MESSAGE,
                    ),
                    targetAcceptKey: $acceptKey,
                ),
            );
        } catch (InvalidArgumentException $e) {
            // The frame that says the page failed could not be named, which is a mistake in the
            // constant above rather than anything about this subscription. Raising it here would
            // replace a page that does not update with a worker that does not run.
            Logger::error("Browser delivery error frame could not be sent: page={$page}, error={$e->getMessage()}");
        }
    }

    /**
     * Collects one subscription's browser rows for one source change.
     *
     * Split out of {@see self::emitBrowserSignals()} so the fan-out can catch a broken
     * declaration around exactly one subscription: the loop body is the unit that has
     * to survive on its own, and a `try` wrapped around the whole flush would drop every
     * other subscriber's data along with the offender's.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Collected
     *     rows, keyed by accept key, page and browser key
     * @param array<string, bool> $guardAllows Memoized page-guard result per accept key
     * @param SourceChange $change Grouped DB/RT source change
     * @param string $page Page name from the subscription mirror
     * @param string $acceptKey Subscriber accept key
     * @param array<string, mixed> $subscription Page subscription mirror entry
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws TableRowKeyMissingException When a mutated row is a placeholder and carries no key
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function addBrowserChange(
        array &$signalTables,
        array &$guardAllows,
        SourceChange $change,
        string $page,
        string $acceptKey,
        array $subscription,
    ): void {
        $pageConfig = $this->pageConfig($page);
        if ($pageConfig === null || $pageConfig->signalName === null) {
            return;
        }

        $pageParams = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY];
        $guardAllows[$acceptKey] ??= $this->pageGuardsAllow($page, $pageConfig, $acceptKey, $pageParams);
        if (!$guardAllows[$acceptKey]) {
            return;
        }

        foreach ($this->pageBindings($page) as $pageBinding) {
            $browserKey = $pageBinding->browserKey;

            $viewportTable = $this->viewportTable($browserKey);
            if ($viewportTable !== null) {
                $viewport = Hilos::$sr?->getTableViewport($acceptKey, $browserKey);
                if ($viewport !== null) {
                    $this->emitViewportDelta($viewportTable, $viewport, $change, $acceptKey, $page, $browserKey);
                    $this->emitTableProgress($viewportTable, $change, $acceptKey, $page, $browserKey);
                }
                // A viewport table is delivered only through its window and deltas; with or
                // without an active viewport it never uses the page_response table fan-out.
                // Lists and data are not viewport tables and fall through to the declarative
                // path below.
                continue;
            }

            $browserConfig = $this->browserConfig($browserKey);
            if ($browserConfig === null || !$this->browserObservesChange($browserConfig, $change)) {
                continue;
            }

            if ($change->mutationType === TableMutationType::Clear) {
                $this->addBrowserClear($signalTables, $acceptKey, $page, $browserKey);
                continue;
            }

            $browserParams = $this->browserParams($pageBinding, $acceptKey, $pageParams);
            $rowKey = $this->rowKeyForChange($browserConfig, $change, $browserParams);
            if ($rowKey === null) {
                continue;
            }

            $row = $this->buildBrowserRow(
                browserKey: $browserKey,
                browserConfig: $browserConfig,
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
                joinedItems: [],
            );

            if ($row === null) {
                $this->addBrowserDelete($signalTables, $acceptKey, $page, $browserKey, $rowKey);
                continue;
            }

            $this->addBrowserRow($signalTables, $acceptKey, $page, $browserKey, $rowKey, $row);
        }
    }

    /**
     * Collects one subscription's answer to a freshness move in one RT collection.
     *
     * The shape of {@see self::addBrowserChange()} and deliberately not that method: the two
     * questions differ in what reaches the reader. A table serving a window is told which of
     * its shown rows changed freshness and nothing else — the values did not move, and a
     * content delta would either wait behind the Apply gate (leaving a frozen number looking
     * fresh until it is pressed) or, sent live, resolve everything the reader has not accepted
     * yet. A table without a window has no gate at all, so its row simply goes out whole with
     * the list inside it (HIL-800).
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Collected
     *     rows, keyed by accept key, page and browser key
     * @param array<string, bool> $guardAllows Memoized page-guard result per accept key
     * @param string $collectionKey RT collection whose rows froze or thawed
     * @param list<string> $stateIds Rows of that collection whose freshness moved
     * @param string $page Page name from the subscription mirror
     * @param string $acceptKey Subscriber accept key
     * @param array<string, mixed> $subscription Page subscription mirror entry
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function addBrowserStaleness(
        array &$signalTables,
        array &$guardAllows,
        string $collectionKey,
        array $stateIds,
        string $page,
        string $acceptKey,
        array $subscription,
    ): void {
        $pageConfig = $this->pageConfig($page);
        if ($pageConfig === null || $pageConfig->signalName === null) {
            return;
        }

        $pageParams = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY];
        $guardAllows[$acceptKey] ??= $this->pageGuardsAllow($page, $pageConfig, $acceptKey, $pageParams);
        if (!$guardAllows[$acceptKey]) {
            return;
        }

        foreach ($this->pageBindings($page) as $pageBinding) {
            $browserKey = $pageBinding->browserKey;

            $viewportTable = $this->viewportTable($browserKey);
            if ($viewportTable !== null) {
                // A viewport table is delivered only through its window, exactly as it is for a
                // content change: with no window open there is nowhere for this to land, and it
                // never falls through to the page_response fan-out below.
                $viewport = Hilos::$sr?->getTableViewport($acceptKey, $browserKey);
                if ($viewport === null) {
                    continue;
                }

                foreach ($stateIds as $stateId) {
                    $this->emitViewportStaleness(
                        $viewportTable,
                        $viewport,
                        SourceChange::rtUpdated($collectionKey, $stateId, []),
                        $acceptKey,
                        $page,
                        $browserKey,
                    );
                }

                continue;
            }

            $browserConfig = $this->browserConfig($browserKey);
            if ($browserConfig === null) {
                continue;
            }

            $browserParams = $this->browserParams($pageBinding, $acceptKey, $pageParams);
            foreach ($stateIds as $stateId) {
                $rowKey = $this->staleRowKey($browserConfig, $collectionKey, $stateId, $browserParams);
                if ($rowKey === null) {
                    continue;
                }

                $row = $this->buildBrowserRow(
                    browserKey: $browserKey,
                    browserConfig: $browserConfig,
                    rowKey: $rowKey,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    browserParams: $browserParams,
                    joinedItems: [],
                );
                if ($row !== null) {
                    $this->addBrowserRow($signalTables, $acceptKey, $page, $browserKey, $rowKey, $row);
                }
            }
        }
    }

    /**
     * Tells one window that a row it shows changed which of its sources are current.
     *
     * The row is rebuilt through the table's own mutation builder — the same door a content
     * delta comes through — so the table names its own frozen slots; a typed table assembles
     * its fragments itself, and one of them can be a summary over many runtime rows. The row
     * is then thrown away: what goes out is the list alone, and the values the reader is
     * looking at are left exactly as they are (Flow F3).
     *
     * The delivered digest is not touched, and does not need to be: freshness is kept out of
     * it on purpose ({@see TableViewportSubscription::digest()}), so this row is still the
     * row the connection was given.
     *
     * A table that refuses to build the row leaves this window on its old marks and says so in
     * the log, rather than telling the subscriber its page failed. Freshness is the least of
     * what a page carries, and taking the page down over it would be the wrong trade
     * ({@see self::emitViewportDelta()} contains its build for the same reason).
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param SourceChange $address Address of the runtime row whose freshness moved
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     */
    private function emitViewportStaleness(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        SourceChange $address,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        try {
            $mutation = $table->buildMutationForSourceEvent($address);
            if ($mutation?->row === null || !$viewport->hasRow((string) $mutation->rowKey)) {
                return;
            }

            $staleSources = $this->staleSourcesOfRow($table->browserRow($mutation->row));
        } catch (Throwable $e) {
            Logger::error(
                "Viewport staleness skipped a row the table failed to build: table={$browserKey}, "
                    . "page={$page}, acceptKey={$acceptKey}, "
                    . "source={$address->sourceKey}#{$address->sourceId}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}",
            );

            return;
        }

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_DELTA,
            TableViewportDeltaDTO::rowStale($page, $browserKey, $mutation->rowKey, $staleSources),
            $acceptKey,
        );
    }

    /**
     * Resolves the browser row one RT row of a frozen collection belongs to.
     *
     * The trigger fields a row source may declare are deliberately not consulted: they name
     * the fields of the source whose change should rebuild the row, and freshness is not one
     * of them — asked, they would answer no every time and the mark would never leave here
     * ({@see self::rowConfigTriggersOnChange()}). The address handed to the row-key reader is
     * an address and nothing more: it names the collection and the row, carries no fields, and
     * never leaves this method.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config of the bound table
     * @param string $collectionKey RT collection whose row froze or thawed
     * @param string $stateId Row of that collection
     * @param array<string, mixed> $browserParams Resolved table params
     * @return int|string|null Browser row key, or null when this table draws no row from it
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When the source collection cannot be loaded
     * @throws LogicException When a database collection is not configured with its class constants
     */
    private function staleRowKey(
        BrowserSourceConfig $browserConfig,
        string $collectionKey,
        string $stateId,
        array $browserParams,
    ): int|string|null {
        $address = SourceChange::rtUpdated($collectionKey, $stateId, []);
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            if (!is_array($source)
                || $this->sourceType($source) !== SourceChange::KIND_RT
                || $this->sourceKey($source) !== $collectionKey
            ) {
                continue;
            }

            $rowKey = $this->rowKeyValue($rowConfig, $address, $browserParams);
            if ($rowKey !== null) {
                return $rowKey;
            }
        }

        return null;
    }

    /**
     * Computes a declared browser field for a logical table row.
     *
     * Project browser contexts override this for computed names listed in
     * browser table configs. Unknown computed fields resolve to null.
     *
     * @param string $browserKey Browser table key
     * @param string $field Computed field name
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when the field is unknown
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        return null;
    }

    /**
     * Resolves browser metadata for one page.
     *
     * Reads page metadata from the active project topology registry.
     * Page-table bindings are resolved separately.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Browser page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        $hilosClass = $this->hilosClass;
        $pageClass = $hilosClass::PAGES[$page] ?? null;
        if (!is_string($pageClass)) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = $pageClass::BROWSER;

        return BrowserPageConfig::fromArray($config);
    }

    /**
     * Resolves page table bindings from project topology.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        $hilosClass = $this->hilosClass;
        $lists = $hilosClass::PAGE_LISTS[$page] ?? [];
        $tables = $hilosClass::PAGE_TABLES[$page] ?? [];
        $data = $hilosClass::PAGE_DATA[$page] ?? [];
        $bindings = (is_array($lists) ? $lists : [])
            + (is_array($tables) ? $tables : [])
            + (is_array($data) ? $data : []);

        return BrowserPageBindings::fromArray($bindings);
    }

    /**
     * Names the RT collections one page reads, from topology alone.
     *
     * Asked before the page runs and by a caller that must not run it: a worker has to hold a
     * collection before a subscription can be answered out of it, and finding out what a page
     * reads by letting it read is the one order that cannot work. Topology carries the answer
     * already - every source a page's rows draw from is declared - so this walks the same
     * declarations {@see TopologyValidator} judges rather than any live state.
     *
     * What the page's TABLES draw from, which is not everything it reads: a collection the page
     * depends on without showing a row of it is named by {@see AbstractPage::READS_RT}, and the
     * two lists add up.
     *
     * @param string $page Page name from the subscription mirror
     * @return list<string> RT collection keys the page reads, each named once
     */
    final public function rtSourceKeysOfPage(string $page): array
    {
        return $this->sourceKeysOfPage($page, BrowserSourceType::RT);
    }

    /**
     * Names the DB collections one page reads, from topology alone.
     *
     * The database twin of the method above, and asked in the same breath and the same order
     * (HIL-750). What a worker owes a DB collection is not a copy - the rows are in the shared
     * database - but the master's word that frames about it are addressed here, and until that
     * word arrives a read is refused exactly as a runtime one is.
     *
     * What the page's TABLES read, which is not everything it reads: an action reaching past its
     * own tables is named by {@see AbstractPage::READS_DB}, and the two lists add up.
     *
     * @param string $page Page name from the subscription mirror
     * @return list<string> DB collection keys the page reads, each named once
     */
    final public function dbSourceKeysOfPage(string $page): array
    {
        return $this->sourceKeysOfPage($page, BrowserSourceType::DB);
    }

    /**
     * Walks the topology of one page and names the collections of one source kind it draws from.
     *
     * One walk for both kinds rather than a walk each, because the two answers are read off the
     * same declarations at the same moment: a copy of it per kind would be two places to keep
     * in step with a topology shape that neither of them owns.
     *
     * @param string $page Page name from the subscription mirror
     * @param string $sourceType Source kind to name the collections of, a constant of {@see BrowserSourceType}
     * @return list<string> Collection keys of that kind the page reads, each named once
     */
    private function sourceKeysOfPage(string $page, string $sourceType): array
    {
        $collectionKeys = [];
        foreach ($this->resolveBrowserPageBindings($page) as $binding) {
            $sourceConfig = $this->topologySourceConfig($binding->browserKey);
            if ($sourceConfig === null) {
                continue;
            }

            foreach ($sourceConfig->rowConfigs() as $rowConfig) {
                $source = $rowConfig[BrowserFieldKey::SOURCE] ?? null;
                if (!is_array($source) || $this->sourceType($source) !== $sourceType) {
                    continue;
                }

                $collectionKey = $this->sourceKey($source);
                if ($collectionKey !== null && !in_array($collectionKey, $collectionKeys, true)) {
                    $collectionKeys[] = $collectionKey;
                }
            }
        }

        return $collectionKeys;
    }

    /**
     * Resolves the browser config one page binding names, from topology alone.
     *
     * A browser-only source and a table registered in TABLES alike: a page showing a registered
     * table draws its rows from that table's sources exactly as it would from a browser-only one,
     * and walking past it left the page subscribed without interest in them - a collection nobody
     * else on the worker read was then refused and the table never drew (HIL-376). Unlike
     * {@see self::browserConfig()} it asks the facade's constants and not the live table context,
     * because the walk promises an answer out of declarations rather than out of live state.
     *
     * @param string $browserKey Browser source or registered table key
     * @return ?BrowserSourceConfig Browser config of the bound source, or null when topology names none
     */
    private function topologySourceConfig(string $browserKey): ?BrowserSourceConfig
    {
        $browserConfig = $this->resolveBrowserOnlyConfig($browserKey);
        if ($browserConfig !== null) {
            return $browserConfig;
        }

        $tableClass = $this->hilosClass::TABLES[$browserKey] ?? null;
        if (!is_string($tableClass)) {
            return null;
        }

        /** @var array<string, mixed> $tableBrowserConfig */
        $tableBrowserConfig = $tableClass::BROWSER;

        return BrowserSourceConfig::fromArray($tableBrowserConfig);
    }

    /**
     * Resolves a browser-only table config.
     *
     * Returning null lets the generic table context fallback resolve ordinary
     * registered table metadata without requiring it for browser-only topology.
     *
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Browser-only table config, or null when absent
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        $hilosClass = $this->hilosClass;
        $tableClass = $hilosClass::BROWSER_LISTS[$browserKey]
            ?? $hilosClass::BROWSER_TABLES[$browserKey]
            ?? $hilosClass::BROWSER_DATA[$browserKey]
            ?? null;
        if (!is_string($tableClass)) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = $tableClass::BROWSER;

        return BrowserSourceConfig::fromArray($config);
    }

    /**
     * Returns browser metadata for one page.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Browser page metadata
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    private function pageConfig(string $page): ?BrowserPageConfig
    {
        return $this->resolveBrowserPageConfig($page);
    }

    /**
     * Returns page table bindings from project topology.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    private function pageBindings(string $page): BrowserPageBindings
    {
        return $this->resolveBrowserPageBindings($page);
    }

    /**
     * Returns a browser table config from browser-only or registered table metadata.
     *
     * @param string $browserKey Browser or table context key
     * @return ?BrowserSourceConfig Browser source config
     */
    private function browserConfig(string $browserKey): ?BrowserSourceConfig
    {
        $browserConfig = $this->resolveBrowserOnlyConfig($browserKey);
        if ($browserConfig !== null) {
            return $browserConfig;
        }

        if (Hilos::$table === null) {
            return null;
        }

        $table = Hilos::$table->get($browserKey);
        if ($table === null) {
            return null;
        }

        /** @var array<string, mixed> $tableBrowserConfig */
        $tableBrowserConfig = $table::BROWSER;

        return BrowserSourceConfig::fromArray($tableBrowserConfig);
    }

    /**
     * Resolves the kind of a page-bound source, deciding its payload section.
     *
     * A source with no resolvable class — a project hook that returns an inline
     * config — is a table.
     *
     * @param string $browserKey Browser source key
     * @return string One of the BrowserSourceKind constants
     */
    private function browserKind(string $browserKey): string
    {
        $class = $this->resolveSourceClass($browserKey);

        return $class !== null ? $this->sourceKind($class) : BrowserSourceKind::TABLE;
    }

    /**
     * Resolves the declaring class of a page-bound source, when registered.
     *
     * @param string $browserKey Browser source key
     * @return ?string Source class name, or null when resolved without a class
     */
    private function resolveSourceClass(string $browserKey): ?string
    {
        $tableClass = $this->hilosClass::BROWSER_LISTS[$browserKey]
            ?? $this->hilosClass::BROWSER_TABLES[$browserKey]
            ?? $this->hilosClass::BROWSER_DATA[$browserKey]
            ?? null;
        if (is_string($tableClass)) {
            return $tableClass;
        }

        $table = Hilos::$table?->get($browserKey);

        return $table !== null ? $table::class : null;
    }

    /**
     * Reads the source kind a source class declares through its key constant.
     *
     * The constant name carries the kind: a `LIST` source feeds the lists
     * section, a `DATA` source the data section, and any other source is a
     * table.
     *
     * @param string $class Browser source class name
     * @return string One of the BrowserSourceKind constants
     */
    private function sourceKind(string $class): string
    {
        foreach ([BrowserSourceKind::LIST, BrowserSourceKind::DATA] as $kind) {
            if (defined("{$class}::" . strtoupper($kind))) {
                return $kind;
            }
        }

        return BrowserSourceKind::TABLE;
    }

    /**
     * Resolves table params for one page subscription.
     *
     * @param BrowserPageBinding $pageBinding Page table binding
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @return array<string, mixed> Resolved table params
     */
    private function browserParams(BrowserPageBinding $pageBinding, string $acceptKey, array $pageParams): array
    {
        $params = [];
        foreach ($pageBinding->paramRefs() as $paramKey => $ref) {
            if (!is_string($paramKey)) {
                continue;
            }
            $params[$paramKey] = $this->resolveReference($ref, $acceptKey, $pageParams, []);
        }

        return $params;
    }

    /**
     * Checks whether any row source in the table observes the source change.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when this table has a row source for the change
     */
    private function browserObservesChange(BrowserSourceConfig $browserConfig, SourceChange $change): bool
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if ($this->rowConfigMatchesChange($rowConfig, $change)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns row source configs for one browser table.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @return list<array<string, mixed>> Row source configs
     */
    private function rowConfigs(BrowserSourceConfig $browserConfig): array
    {
        return $browserConfig->rowConfigs();
    }

    /**
     * Checks whether a row source config matches the source change.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when the row source observes this change
     */
    private function rowConfigMatchesChange(array $rowConfig, SourceChange $change): bool
    {
        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];

        return is_array($source)
            && $this->sourceType($source) === $change->kind
            && $this->sourceKey($source) === $change->sourceKey
            && $this->rowConfigTriggersOnChange($rowConfig, $change);
    }

    /**
     * Checks whether a row source should react to the changed fields.
     *
     * Create and delete changes always invalidate the row. Update changes may
     * opt into a narrow trigger field list to avoid emitting browser rows for
     * backend-only source fields.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @return bool True when this row source should be rebuilt for the change
     */
    private function rowConfigTriggersOnChange(array $rowConfig, SourceChange $change): bool
    {
        if ($change->mutationType !== TableMutationType::Update) {
            return true;
        }

        $triggers = $rowConfig[BrowserFieldKey::TRIGGERS] ?? [];
        if (!is_array($triggers) || $triggers === []) {
            return true;
        }

        foreach ($triggers as $field) {
            if (is_string($field) && array_key_exists($field, $change->row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the logical browser row key affected by a source change.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param SourceChange $change Grouped DB/RT source change
     * @param array<string, mixed> $browserParams Resolved table params
     * @return int|string|null Browser row key, or null when no matching row source can resolve it
     */
    private function rowKeyForChange(BrowserSourceConfig $browserConfig, SourceChange $change, array $browserParams): int|string|null
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if (!$this->rowConfigMatchesChange($rowConfig, $change)) {
                continue;
            }

            $rowKey = $this->rowKeyValue($rowConfig, $change, $browserParams);
            if ($rowKey !== null) {
                return $rowKey;
            }
        }

        return null;
    }

    /**
     * Resolves a row key value from the source change, current source row, or table params.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param SourceChange $change Grouped DB/RT source change
     * @param array<string, mixed> $browserParams Resolved table params
     * @return int|string|null Browser row key
     */
    private function rowKeyValue(array $rowConfig, SourceChange $change, array $browserParams): int|string|null
    {
        $rowKey = $rowConfig[BrowserListFieldKey::ITEM_KEY] ?? $rowConfig[BrowserTableFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, '', [], $browserParams));
        }

        if (!is_string($rowKey) || $rowKey === '') {
            return $this->normalizeKey($change->sourceId);
        }

        if (array_key_exists($rowKey, $change->row)) {
            return $this->normalizeKey($change->row[$rowKey]);
        }

        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
        if (is_array($source)) {
            $sourceItem = $this->sourceItemById($source, $change->sourceId);
            if ($sourceItem !== null) {
                return $this->normalizeKey($this->fieldValue($sourceItem, $rowKey));
            }
        }

        return $this->normalizeKey($change->sourceId);
    }

    /**
     * Builds the page-shaped browser row for one logical row key.
     *
     * @param string $browserKey Browser table key
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, array<string, array<string, list<mixed>>>> $joinedItems Joined db items
     *     already read for the whole snapshot, by source key, join column and join value; empty
     *     when the row is built alone
     * @return ?array{rowKey: int|string, sources: array<string, mixed>} Browser row payload, or null when row is absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function buildBrowserRow(
        string $browserKey,
        BrowserSourceConfig $browserConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $joinedItems,
    ): ?array {
        $sources = [];
        $staleSources = [];
        $anchorChecked = false;
        $anchorFound = false;
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            if (!is_array($source)) {
                continue;
            }

            $sourceKey = $this->sourceKey($source);
            if ($sourceKey === null) {
                throw new PageInternalErrorException('Invalid browser source config: source names no key');
            }

            $isMany = ($rowConfig[BrowserFieldKey::MANY] ?? false) === true;
            $isAnchor = !$anchorChecked && !$isMany;
            if ($isAnchor) {
                $anchorChecked = true;
            }

            $items = $this->sourceItemsForRow(
                rowConfig: $rowConfig,
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
                sources: $sources,
                browserKey: $browserKey,
                joinedItems: $joinedItems,
            );
            if ($isMany) {
                $sources[$sourceKey] = array_map(
                    fn(mixed $item): array => $this->projectSourceItem(
                        browserKey: $browserKey,
                        rowConfig: $rowConfig,
                        item: $item,
                        rowKey: $rowKey,
                        acceptKey: $acceptKey,
                        pageParams: $pageParams,
                        browserParams: $browserParams,
                        sources: $sources,
                    ),
                    $items,
                );
                if ($this->itemsAreFrozen($items)) {
                    $staleSources[] = $sourceKey;
                }
                continue;
            }

            if ($items === []) {
                if ($isAnchor) {
                    return null;
                }
                continue;
            }

            if ($isAnchor) {
                $anchorFound = true;
            }

            $sources[$sourceKey] = $this->projectSourceItem(
                browserKey: $browserKey,
                rowConfig: $rowConfig,
                item: $items[0],
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
                sources: $sources,
            );
            if ($this->itemsAreFrozen([$items[0]])) {
                $staleSources[] = $sourceKey;
            }
        }

        if ($sources === [] || ($anchorChecked && !$anchorFound)) {
            return null;
        }

        $browserRow = [
            BrowserPageSignalData::rowKey => $rowKey,
            BrowserPageSignalData::sources => $sources,
        ];
        if ($staleSources !== []) {
            $browserRow[BrowserPageSignalData::staleSources] = $staleSources;
        }

        return $browserRow;
    }

    /**
     * Whether any of the items a slot was built from is a copy that stopped being updated.
     *
     * The question is asked of the item and not of the collection it came from: on a cluster a
     * collection holds this node's own rows beside replicas of somebody else's, and only some of
     * the replicas are behind a link that dropped ({@see RtItem::staleSince()}). A database item
     * is never asked, because a cluster shares one database and a database row has no other copy
     * to fall behind (HIL-800).
     *
     * One frozen item is enough for the whole slot. A slot built out of many rows shows one value
     * assembled from all of them, and a value assembled partly out of frozen rows is a frozen
     * value — there is no part of the cell to mark separately.
     *
     * @param list<mixed> $items Source items the slot was built from
     * @return bool Whether the slot draws on a copy that is no longer kept up to date
     */
    private function itemsAreFrozen(array $items): bool
    {
        foreach ($items as $item) {
            if ($item instanceof RtItem && $item->staleSince() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds all current browser rows for one page-bound table.
     *
     * @param string $browserKey Browser table key
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return list<array{rowKey: int|string, sources: array<string, mixed>}> Current browser rows
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function buildBrowserSnapshotRows(
        string $browserKey,
        BrowserSourceConfig $browserConfig,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): array {
        $rows = [];
        $rowKeys = $this->snapshotRowKeys($browserConfig, $acceptKey, $pageParams, $browserParams);
        $joinedItems = $this->joinedItemsForSnapshot($browserConfig, $rowKeys);
        foreach ($rowKeys as $rowKey) {
            $row = $this->buildBrowserRow(
                browserKey: $browserKey,
                browserConfig: $browserConfig,
                rowKey: $rowKey,
                acceptKey: $acceptKey,
                pageParams: $pageParams,
                browserParams: $browserParams,
                joinedItems: $joinedItems,
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Resolves a page-bound table as a viewport table, when it is one.
     *
     * @param string $browserKey Browser table key
     * @return ?ViewportTable Viewport table, or null when absent or not windowed
     */
    private function viewportTable(string $browserKey): ?ViewportTable
    {
        $table = Hilos::$table?->get($browserKey);

        return $table instanceof ViewportTable ? $table : null;
    }

    /**
     * Emits live viewport signals for a source change scoped to a connection's window.
     *
     * A change yields one or two addressed signals. A create whose row belongs at the tail of
     * this window is appended live (table_viewport_append, counts included) and nothing else is
     * sent; a create whose row belongs above the window or inside it is announced live
     * (table_viewport_announce, the same counts) and nothing else is sent either. Otherwise a
     * live table_viewport_count carries any total shift (navigation metadata the frontend applies
     * at once), and a pending table_viewport_delta carries an in-window row edit or removal.
     *
     * The originator is distinguished here: the delta is tagged `own` when the
     * grouped change's origin equals this receiver's accept key, so its own edit
     * applies at once in that tab while other connections keep the pending gate.
     * The server owns the decision (the client stays dumb); this closes the race a
     * client-side row-key mark left open — a concurrent change to the SAME row folds
     * into one grouped change whose origin is the later writer's, so exactly one
     * author gets `own` and the loser gates against the winning value.
     *
     * The author of a CREATE takes its own road first: the row placed where the live sort puts
     * it. Failing that, it falls through to the same arrival road as everyone else, and both
     * dangers that once kept it out are gone — a succeeded placed insert ends the judging with
     * its own return, so the row cannot be sent twice, and a row outside the author's filter is
     * turned back by the classifier itself, a window with a filter map being one whose place
     * cannot be read. What reaches the author that way is an announcement rather than a row: the
     * one thing the failure of its own road means, with no filter in play, is that its row landed
     * on another page.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its delivered rows and total are updated in place
     * @param SourceChange $change Grouped DB/RT source change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @throws TableRowKeyMissingException When a mutated row is a placeholder and carries no key
     */
    private function emitViewportDelta(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        SourceChange $change,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        try {
            $mutation = $table->buildMutationForSourceEvent($change);
        } catch (Throwable $e) {
            // This window stops receiving deltas and freezes on its old rows while
            // every neighboring window stays live. Without this line the refusal is
            // indistinguishable from the routine "this table is not touched" below.
            Logger::error(
                "Viewport delta skipped a change the table failed to build: table={$viewport->tableKey}, "
                    . "page={$page}, acceptKey={$acceptKey}, "
                    . "source={$change->kind}:{$change->sourceKey}#{$change->sourceId}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}, "
                    . 'at=' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return;
        }

        if ($mutation === null) {
            return;
        }

        $own = $change->origin !== null && $change->origin === $acceptKey;

        if ($own && $mutation->type === TableMutationType::Create) {
            if ($this->tryEmitViewportOwnCreate(
                $table,
                $viewport,
                $mutation,
                $acceptKey,
                $page,
                $browserKey,
                $change->originRequestId,
            )) {
                return;
            }
        }

        if ($this->tryEmitViewportArrival($table, $viewport, $mutation, $acceptKey, $page, $browserKey)) {
            return;
        }

        // The count and the classifier ask the table the same question about the same row. It is
        // asked once, and only when one of them needs it; the flag is load-bearing, because a
        // refusal answers null and without it the second reader would put the failed question again.
        $asked = false;
        $inSet = null;
        $membership = function () use (&$asked, &$inSet, $table, $viewport, $mutation, $page, $acceptKey): ?bool {
            if (!$asked) {
                $asked = true;
                $inSet = $this->viewportRowInSet($table, $viewport, $mutation, $this->viewportQuery($viewport), $page, $acceptKey);
            }

            return $inSet;
        };

        $this->emitViewportCount($table, $viewport, $mutation, $acceptKey, $page, $browserKey, $membership);

        $delta = $this->rowDeltaForMutation($viewport, $table, $mutation, $page, $browserKey, $own, $membership);
        if ($delta !== null) {
            $this->queueAddressedTableSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $delta, $acceptKey);
        }
    }

    /**
     * Sends one window the work a source change reports on its table, if it reports any.
     *
     * This runs beside the delta and not inside it, because a bar is not a row: the change that
     * moves a bar need move no row at all, and the one that moves a row usually moves no bar.
     * What the two share is the road, and they share it for the reason there is only one - work
     * is reported from inside a monopolistic agent, which holds no subscription registry, so a
     * bar reaches a tab by the agent writing runtime state and this worker fanning the change
     * out. Broadcasting from the agent would reach every socket on the node, subscribed to this
     * page or not.
     *
     * Nothing of the window's bookkeeping is touched here - no total, no delivered row, no
     * count. That absence is the whole of "a bar is not in the count and cannot be selected":
     * it holds by there being no code that puts it there, rather than by a rule somewhere
     * checking that nobody did.
     *
     * The addressees are the windows this page's guard already let through, and the open window
     * is the whole of the rest of the test: a tab without one is not drawing this table.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param SourceChange $change Source change that may report work on this table
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     */
    private function emitTableProgress(
        ViewportTable $table,
        SourceChange $change,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        try {
            $progress = $table->buildProgressForSourceEvent($change);
        } catch (Throwable $e) {
            // Contained for the reason the snapshot road contains it ({@see tableWindowSection()}):
            // a table that cannot name its work costs the tab that bar, not the change - the rows
            // of this very fan-out have already gone out beside it.
            Logger::error(
                "Browser fan-out skipped the work a table could not name: table={$browserKey}, "
                . "page={$page}, error={$e->getMessage()}",
            );

            return;
        }

        if ($progress === null) {
            return;
        }

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_PROGRESS,
            TableProgressSignalData::fromProgress($page, $browserKey, $progress),
            $acceptKey,
        );
    }

    /**
     * Sends the author its own new row already placed in its window, or returns false.
     *
     * The author is the one person who is looking at the result of a press, so its row
     * goes in at once and at the index a reload would give it. That index is not guessed:
     * the window is re-selected with the live filter, search, sort and page
     * ({@see self::viewportQuery()} builds the very query the window was built from), and
     * the row is looked up among the keys that come back. Missing from them means the row
     * belongs to another page or falls outside the filter, and then the author goes on to the
     * road every other window takes ({@see self::tryEmitViewportArrival()}), which announces the
     * row to it or leaves it with a count.
     *
     * The whole-window re-select is the expensive road, taken because the event is one
     * person's single press rather than a stream of foreign writes, and it stays: the
     * classifier that judges a foreign create ({@see self::viewportPlacement()}) answers about
     * the boundaries of the window, and the author needs the index its row goes in at. That
     * index costs nothing only to something holding both the set and the readers' windows,
     * which is the table's own agent (HIL-914) and not this path.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its delivered rows and total are updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $acceptKey Target accept key, which is also the author of the create
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @param ?string $requestId Request id of the action that created the row, or null when it was not tracked
     * @return bool Whether the row was sent placed (and no further signal is needed)
     * @throws TableRowKeyMissingException When the mutated row is a placeholder and carries no key
     */
    private function tryEmitViewportOwnCreate(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $acceptKey,
        string $page,
        string $browserKey,
        ?string $requestId,
    ): bool {
        if ($mutation->row === null || $viewport->hasRow((string) $mutation->rowKey)) {
            return false;
        }

        $query = $this->viewportQuery($viewport);
        try {
            $snapshot = $table->getPage($query);
        } catch (Throwable $e) {
            // False here means "not placed", and the author then gets the count path -
            // the same answer a row on another page gets. Without this line the two are
            // indistinguishable, and a table that refuses its own query looks like a
            // table whose author simply scrolled away.
            Logger::error(
                "Viewport own-create fell back to the count after its re-query failed: "
                    . "table={$viewport->tableKey}, page={$page}, acceptKey={$acceptKey}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}, "
                    . 'at=' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return false;
        }

        $rowKey = (string) $mutation->rowKey;
        $wireRows = [];
        $rowAnchors = [];
        $position = null;
        $wireRow = null;
        foreach ($snapshot->rows as $row) {
            if (!$row instanceof AbstractTableRow) {
                continue;
            }
            $browserRow = $table->browserRow($row);
            $windowRowKey = (string) $browserRow[BrowserPageSignalData::rowKey];
            $windowWireRow = $this->browserRowToWire($browserRow);
            if ($windowRowKey === $rowKey) {
                $position = count($wireRows);
                $wireRow = $windowWireRow;
            }
            $wireRows[$windowRowKey] = $windowWireRow;
            $rowAnchors[$windowRowKey] = $table->anchorForRow($row, $query);
        }

        if ($position === null || $wireRow === null) {
            return false;
        }

        $viewport->recordWindow(
            $wireRows,
            $snapshot->totalCount,
            $snapshot->totalExact,
            $snapshot->firstAnchor,
            $snapshot->lastAnchor,
            $rowAnchors,
        );

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE,
            new TableViewportOwnCreateDTO(
                $page,
                $browserKey,
                $wireRow,
                $position,
                $snapshot->totalCount,
                $snapshot->totalExact,
                $this->pageCount($snapshot->totalCount, $viewport->limit, $snapshot->totalExact),
                $requestId,
            ),
            $acceptKey,
        );

        return true;
    }

    /**
     * Sends a created row to one window, as the row itself or as word of it, or returns false.
     *
     * This is the one place a foreign create is judged against one window, and it asks the
     * classifier ({@see self::viewportPlacement()}) exactly once. Five answers come back and each
     * has its road:
     *
     * - the tail of a window that reaches the end of the set and has a free slot: the row arrives
     *   on its own ({@see self::emitViewportAppend()}), because its arrival shifts nothing shown;
     * - above the window, or between two rows it is showing: the row is announced and not sent
     *   ({@see self::emitViewportAnnounce()}) - putting it in would shift everything below it,
     *   and saying nothing would let the window drift away from the set unnoticed;
     * - below the window, and a window whose place cannot be read at all: nothing here, and the
     *   count path takes it as it always did. What such a window is missing is a number, not a
     *   row: a row on a later page was never shown and never will be until the page is turned.
     *
     * Both roads carry the new total and page count themselves, so no separate count signal
     * follows either of them.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its delivered rows and total are updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @return bool Whether the row was sent or announced (and no further signal is needed)
     * @throws TableRowKeyMissingException When the mutated row is a placeholder and carries no key
     */
    private function tryEmitViewportArrival(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): bool {
        if ($mutation->type !== TableMutationType::Create || $mutation->row === null) {
            return false;
        }
        if ($viewport->hasRow((string) $mutation->rowKey)) {
            return false;
        }
        $query = $this->viewportQuery($viewport);
        $placement = $this->viewportPlacement($table, $viewport, $mutation, $query);
        if ($placement === TableRowPlacement::Tail) {
            $this->emitViewportAppend($table, $viewport, $mutation, $query, $acceptKey, $page, $browserKey);

            return true;
        }
        if ($placement === TableRowPlacement::Above || $placement === TableRowPlacement::Inside) {
            $this->emitViewportAnnounce($viewport, $mutation, $placement, $acceptKey, $page, $browserKey);

            return true;
        }

        return false;
    }

    /**
     * Appends a created row to the tail of the window it belongs at.
     *
     * The frozen-viewport rule: a new row arrives on its own only where its arrival shifts
     * nothing already shown, which is the tail of a window that reaches the end of the set and
     * has a free slot. That the window is such a one is settled before this is called; the query
     * comes in as an argument for the same reason, the caller having built it to ask.
     *
     * The row is delivered whatever the count says. A window whose total has stopped at its
     * ceiling still gets its new row; what it does not get is a page count, and its total
     * travels as the ceiling with the word that it is not exact.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its delivered rows and total are updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableQueryDTO $query Query this window was served by
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @throws TableRowKeyMissingException When the mutated row is a placeholder and carries no key
     */
    private function emitViewportAppend(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableQueryDTO $query,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        $counted = $this->countedTotal($viewport, $viewport->totalCount() + 1);
        $totalCount = $counted[TableConstants::RESULT_KEY_TOTAL_COUNT];
        $totalExact = $counted[TableConstants::RESULT_KEY_TOTAL_EXACT];
        $wireRow = $this->browserRowToWire($table->browserRow($mutation->row));
        $viewport->recordTotal($totalCount, $totalExact);
        $viewport->recordRow((string) $mutation->rowKey, $wireRow, $table->anchorForRow($mutation->row, $query));

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_APPEND,
            new TableViewportAppendDTO(
                $page,
                $browserKey,
                $wireRow,
                $totalCount,
                $totalExact,
                $this->pageCount($totalCount, $viewport->limit, $totalExact),
            ),
            $acceptKey,
        );
    }

    /**
     * Announces a created row the window cannot show, without sending it.
     *
     * The window is told that the set grew under it and where the new row fell - above it or
     * between the rows it holds - and that is all: the key travels so the same row announced
     * twice counts once, the row body does not travel at all. Nothing is written into the
     * window's memory either, an announced row not being part of it; the next edit of that row
     * is therefore a change to a row this window never had, and no frame follows from it.
     *
     * The table is not asked anything here - no row for the wire, no anchor. This runs on every
     * foreign create in every window of every connection, and it makes no request of the source.
     *
     * The count arithmetic is the append's, and it is legitimate for the append's reason: a
     * window with a filter map never reaches this road, the classifier answering it "cannot say",
     * so with no filter one create is one more row in the set. A window whose count stopped at
     * its ceiling is announced to all the same - the early return the count path takes on an
     * inexact total is no model here, that one being about a number where this is about a row.
     *
     * @param TableViewportSubscription $viewport Connection's window; its total is updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableRowPlacement $placement Where the row falls against the window, above it or inside it
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     */
    private function emitViewportAnnounce(
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableRowPlacement $placement,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        $counted = $this->countedTotal($viewport, $viewport->totalCount() + 1);
        $totalCount = $counted[TableConstants::RESULT_KEY_TOTAL_COUNT];
        $totalExact = $counted[TableConstants::RESULT_KEY_TOTAL_EXACT];
        $viewport->recordTotal($totalCount, $totalExact);

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE,
            new TableViewportAnnounceDTO(
                $page,
                $browserKey,
                (string) $mutation->rowKey,
                $placement,
                $totalCount,
                $totalExact,
                $this->pageCount($totalCount, $viewport->limit, $totalExact),
            ),
            $acceptKey,
        );
    }

    /**
     * Reads where a created row falls against one connection's window.
     *
     * The place is read off the two boundaries the window was served with, in the order that
     * window asked for, and the table does the comparing because the boundaries are written in
     * its own names ({@see ViewportTable::placeRowAgainst()}). Nothing here asks the row source
     * for anything: this runs once per window per foreign write, and every window of every
     * connection watching the table runs it.
     *
     * Two windows are refused before any comparison, and both mean "the place cannot be read",
     * not "the row is elsewhere". A window with a filter map — a search, or a table's own
     * filters — is judged by whether the row is in its SET, and that question belongs to the
     * source rather than to the order; the count path already asks it. A window that asked for
     * no order is held in the row source's own sequence, and no comparison of field values
     * reproduces that.
     *
     * A zero from the comparison reads as "not above this boundary". The order is total, the
     * row key settling it (HIL-786), so a new row cannot sit exactly where a live one sits: a
     * boundary it matches is the place of a row that has since left the set.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableQueryDTO $query Query this window was served by
     * @return ?TableRowPlacement Where the row lands, or null when the window cannot say
     */
    private function viewportPlacement(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableQueryDTO $query,
    ): ?TableRowPlacement {
        $row = $mutation->row;
        if ($row === null || $viewport->filter !== [] || $query->sort === null) {
            return null;
        }

        $firstAnchor = $viewport->firstAnchor();
        $lastAnchor = $viewport->lastAnchor();
        if ($firstAnchor === null || $lastAnchor === null) {
            // An empty window shifts nothing by definition, but not every empty window holds the
            // end of the set: one that arrived at emptiness backwards - the client asked for the
            // rows before its anchor and they had all been deleted by then - is holding a hole in
            // the middle, and {@see TableViewportSubscription::reachesEnd()} says so by refusing
            // any anchor direction but After. Its new row can lie anywhere in the set, so this
            // window cannot say where, and a place it cannot read is not a place at its tail.
            return $this->viewportIsLastPageWithRoom($viewport) ? TableRowPlacement::Tail : null;
        }

        $againstFirst = $table->placeRowAgainst($row, $firstAnchor, $query);
        if ($againstFirst === null) {
            return null;
        }
        if ($againstFirst < 0) {
            return TableRowPlacement::Above;
        }

        $againstLast = $table->placeRowAgainst($row, $lastAnchor, $query);
        if ($againstLast === null) {
            return null;
        }
        if ($againstLast <= 0) {
            return TableRowPlacement::Inside;
        }

        return $this->viewportIsLastPageWithRoom($viewport) ? TableRowPlacement::Tail : TableRowPlacement::Below;
    }

    /**
     * Reads where an edit leaves a row the window is already showing.
     *
     * The question is not the one {@see self::viewportPlacement()} answers about an arriving
     * row: that one decides whether a row nobody sees may appear on its own, this one decides
     * whether a row somebody is looking at stays where it is. Both hand back a place rather than
     * a verdict, so the caller is the only one that turns a place into a frame.
     *
     * The boundaries are the places of the row's NEIGHBOURS — the first and the last delivered
     * row other than the edited one ({@see TableViewportSubscription::rowAnchors()}) — and never
     * the row's own former place, the rule {@see self::viewportRowIndex()} keeps for the slot.
     * Judged against itself, an edge row loses every comparison: its own old place is the
     * boundary, and any step outward reads as leaving a window that has nothing beyond it. The
     * delivered places are what the window is, and not the anchors of the snapshot: a window
     * collects rows after it was served — an appended tail row, a row re-sent by an earlier
     * delta — and the snapshot's boundaries stop describing it.
     *
     * A row past a boundary only claims to leave the window, and the claim is settled by the
     * SET: a window holding the start of the set ({@see TableViewportSubscription::reachesStart()})
     * has nowhere above it for the row to go, so the row stays inside and takes the top slot, and
     * the same holds below for a window holding the end. A window standing in the middle of the
     * set does not know what lies past its edges and answers the removal, so it never goes on
     * showing a row this page no longer has (owner's decision, HIL-987).
     *
     * "Cannot say" is answered wherever a place would be a guess: a window with no order, a
     * window whose rows were recorded without their places, a boundary the table could not name,
     * a comparison the table refused, and a window of one row that does not hold both ends of the
     * set. The caller sends the row as moved without a position then, which is honest in both
     * directions — the row is not claimed to have stayed, and it is not put at an index computed
     * from nothing.
     *
     * @param ViewportTable $table Table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableQueryDTO $query Query this window was served by
     * @return ?TableRowPlacement Where the edited row lands, or null when the window cannot say
     */
    private function viewportRowPlacementAfterUpdate(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableQueryDTO $query,
    ): ?TableRowPlacement {
        $row = $mutation->row;
        if ($row === null || $query->sort === null) {
            return null;
        }

        $anchors = $viewport->rowAnchors();
        if ($anchors === []) {
            return null;
        }

        unset($anchors[(string) $mutation->rowKey]);
        if ($anchors === []) {
            return $viewport->reachesStart() && $viewport->reachesEnd() ? TableRowPlacement::Inside : null;
        }

        $firstAnchor = reset($anchors);
        $lastAnchor = end($anchors);
        if ($firstAnchor === null || $lastAnchor === null) {
            return null;
        }

        $againstFirst = $table->placeRowAgainst($row, $firstAnchor, $query);
        if ($againstFirst === null) {
            return null;
        }
        if ($againstFirst < 0) {
            return $viewport->reachesStart() ? TableRowPlacement::Inside : TableRowPlacement::Above;
        }

        $againstLast = $table->placeRowAgainst($row, $lastAnchor, $query);
        if ($againstLast === null) {
            return null;
        }
        if ($againstLast > 0) {
            return $viewport->reachesEnd() ? TableRowPlacement::Inside : TableRowPlacement::Below;
        }

        return TableRowPlacement::Inside;
    }

    /**
     * Counts which slot of the window an edited row lands in.
     *
     * The row is placed against the places of its NEIGHBOURS and never against its own former
     * one, because the two questions have different answers: a size going from 1,1 GB to 1,4 GB
     * between neighbours of 2 GB and 0,5 GB stands at a new place and in the same slot, and a
     * window comparing the row with its past would mark it "will move" and then move it nowhere.
     *
     * The answer is the number of shown rows that stand above the edited one, which is that
     * row's index once it is put back among them. It is asked only of a row that stays inside
     * the window, so a count over the shown rows is the whole answer.
     *
     * @param ViewportTable $table Table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableQueryDTO $query Query this window was served by
     * @return ?int Zero-based slot the row lands in, or null when a neighbour could not be placed against
     */
    private function viewportRowIndex(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableQueryDTO $query,
    ): ?int {
        $row = $mutation->row;
        if ($row === null) {
            return null;
        }

        $rowKey = (string) $mutation->rowKey;
        $index = 0;
        foreach ($viewport->rowAnchors() as $key => $anchor) {
            if ((string) $key === $rowKey) {
                continue;
            }
            if ($anchor === null) {
                return null;
            }
            $against = $table->placeRowAgainst($row, $anchor, $query);
            if ($against === null) {
                return null;
            }
            if ($against > 0) {
                $index++;
            }
        }

        return $index;
    }

    /**
     * Whether the window reaches the dataset end and has a free slot for one row.
     *
     * A non-paginated window (no limit) always has room; otherwise the window must
     * hold fewer than its limit and reach the end of the set, so a new tail row neither
     * pushes a row out nor belongs to a later page.
     *
     * @param TableViewportSubscription $viewport Connection's window
     * @return bool Whether a tail row fits without shifting the window
     */
    private function viewportIsLastPageWithRoom(TableViewportSubscription $viewport): bool
    {
        $hasRoom = $viewport->limit === TableConstants::NO_LIMIT || count($viewport->rowIds()) < $viewport->limit;

        return $hasRoom && $viewport->reachesEnd();
    }

    /**
     * Emits the live count signal when a mutation shifts the filtered total.
     *
     * The count is navigation metadata, not row content, so it is delivered live
     * and never gated as pending. Unfiltered, the total moves by the mutation's
     * row-level type (create +1, delete -1, update none) with no re-query — the
     * type is row-level faithful because each table builds it that way (a presence
     * or other secondary-source change is always an update). With a filter active,
     * the row is placed against the set by asking the table about that one row, and
     * only a table that cannot answer falls back to the whole-set re-query.
     *
     * A window whose count already stopped at its ceiling is sent nothing at all. "At least 500"
     * is neither truer nor newer for one more row, and finding out whether the set has fallen
     * back under the ceiling would cost precisely the pass over it this path exists to avoid.
     * Such a window becomes exact again the next time it asks for a window - a page turn, a new
     * search, a resubscribe - and not before.
     *
     * The one signal that does cross that line is the crossing itself: an exact total that grows
     * past the ceiling is sent once, as the ceiling with the word that it is not exact, and after
     * that the window is silent. Without it the pager would sit on an exact number it outgrew.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window; its total is updated in place
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @param Closure(): ?bool $membership Whether the row is in the set now, asked at most once per change, null when the table would not say
     */
    private function emitViewportCount(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $acceptKey,
        string $page,
        string $browserKey,
        Closure $membership,
    ): void {
        if (!$viewport->totalExact()) {
            return;
        }

        $total = $this->viewportTotalAfterMutation($table, $viewport, $mutation, $page, $acceptKey, $membership);
        if ($total === null) {
            return;
        }

        $totalCount = $total[TableConstants::RESULT_KEY_TOTAL_COUNT];
        $totalExact = $total[TableConstants::RESULT_KEY_TOTAL_EXACT];
        if ($totalCount === $viewport->totalCount() && $totalExact === $viewport->totalExact()) {
            return;
        }

        $viewport->recordTotal($totalCount, $totalExact);

        $this->queueAddressedTableSignal(
            SignalTypeConstants::TABLE_VIEWPORT_COUNT,
            new TableViewportCountDTO(
                $page,
                $browserKey,
                $totalCount,
                $totalExact,
                $this->pageCount($totalCount, $viewport->limit, $totalExact),
            ),
            $acceptKey,
        );
    }

    /**
     * Places a total under the ceiling a windowed count stops at.
     *
     * A window whose total grew past the ceiling reports the ceiling and says the number is not
     * exact; a window with no limit reads its whole set anyway, so nothing is capped there.
     *
     * @param TableViewportSubscription $viewport Connection's window
     * @param int $totalCount Total the mutation arithmetic arrived at
     * @return array{totalCount: int, totalExact: bool} Total as it travels, with the word on it
     */
    private function countedTotal(TableViewportSubscription $viewport, int $totalCount): array
    {
        $overCeiling = $viewport->limit !== TableConstants::NO_LIMIT && $totalCount > TableConstants::COUNT_CEILING;

        return [
            TableConstants::RESULT_KEY_TOTAL_COUNT => $overCeiling ? TableConstants::COUNT_CEILING : $totalCount,
            TableConstants::RESULT_KEY_TOTAL_EXACT => !$overCeiling,
        ];
    }

    /**
     * Resolves the filtered total after a mutation, or null to leave it unchanged.
     *
     * With no filter of any kind the mutation type settles it: every row is in the set, so a
     * create is one more and a delete is one fewer.
     *
     * With a filter active the set is not every row, and what the count needs is one bit — is
     * this row in the set now? That is asked of the table, and the answer decides:
     *
     * - a created row is one the set did not hold a moment ago, so it moves the count by one
     *   when it belongs to the set and not at all when it does not;
     * - a row the window is holding was in the set by construction, the window being part of it,
     *   so a delete takes one off and an update takes one off only if the row has left the set;
     * - a row outside the window is one nobody can place: whether it was in the set before this
     *   change is a question about its previous state, and no previous state is kept anywhere -
     *   a source update carries the changed columns and a delete need carry no row at all. The
     *   count stands still, and the next window request makes it right again.
     *
     * A table that does not answer keeps the whole-set re-query it always had, which is the
     * point of letting it not answer: a project table that never heard of this contract must not
     * quietly stop counting. A table that refused the question is read the same way, since the
     * answer is shared with the classifier and "cannot say" is the only reading a refusal has.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $page Subscribed page key
     * @param string $acceptKey Target accept key
     * @param Closure(): ?bool $membership Whether the row is in the set now, asked at most once per change, null when the table would not say
     * @return ?array{totalCount: int, totalExact: bool} New total with the word on it, or null when it does not change
     */
    private function viewportTotalAfterMutation(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        string $page,
        string $acceptKey,
        Closure $membership,
    ): ?array {
        $query = $this->viewportQuery($viewport);
        if ($query->search === null && $viewport->filter === []) {
            return match ($mutation->type) {
                TableMutationType::Create => $this->countedTotal($viewport, $viewport->totalCount() + 1),
                TableMutationType::Delete => $this->countedTotal($viewport, max(0, $viewport->totalCount() - 1)),
                TableMutationType::Update => null,
                default => $this->viewportFilteredTotal($table, $viewport, $page, $acceptKey),
            };
        }

        $contains = $membership();
        if ($contains === null) {
            return $this->viewportFilteredTotal($table, $viewport, $page, $acceptKey);
        }

        $inWindow = $viewport->hasRow((string) $mutation->rowKey);
        $oneFewer = $this->countedTotal($viewport, max(0, $viewport->totalCount() - 1));

        return match ($mutation->type) {
            TableMutationType::Create => $contains ? $this->countedTotal($viewport, $viewport->totalCount() + 1) : null,
            TableMutationType::Delete => $inWindow ? $oneFewer : null,
            TableMutationType::Update => $inWindow && !$contains ? $oneFewer : null,
            default => $this->viewportFilteredTotal($table, $viewport, $page, $acceptKey),
        };
    }

    /**
     * Asks the table whether a changed row is in the window's set now.
     *
     * The one place the question is put: the count and the classifier both read its answer
     * through the once-only closure of {@see self::emitViewportDelta()}. A refusal is read as
     * "cannot say", which is what a table that does not answer says too, and the log line is what
     * tells the two apart from outside.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param TableQueryDTO $query Query this window was served by
     * @param string $page Subscribed page key
     * @param string $acceptKey Target accept key
     * @return ?bool Whether the row is in the set, or null when the table cannot say or refused
     */
    private function viewportRowInSet(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        TableRowMutationDTO $mutation,
        TableQueryDTO $query,
        string $page,
        string $acceptKey,
    ): ?bool {
        try {
            return $table->containsRow($mutation->rowKey, $table->scopeSearch($query));
        } catch (Throwable $e) {
            Logger::error(
                "Viewport asked whether a row is still in its set and was refused: table={$viewport->tableKey}, "
                    . "page={$page}, acceptKey={$acceptKey}, rowKey={$mutation->rowKey}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}, "
                    . 'at=' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return null;
        }
    }

    /**
     * Recomputes the filtered total via a windowed query, or null on failure.
     *
     * @param ViewportTable $table Viewport table the window is on
     * @param TableViewportSubscription $viewport Connection's window
     * @param string $page Subscribed page key
     * @param string $acceptKey Target accept key
     * @return ?array{totalCount: int, totalExact: bool} Filtered total with the word on it, or null when the query fails
     */
    private function viewportFilteredTotal(
        ViewportTable $table,
        TableViewportSubscription $viewport,
        string $page,
        string $acceptKey,
    ): ?array {
        try {
            $snapshot = $table->getPage($this->viewportQuery($viewport));

            return [
                TableConstants::RESULT_KEY_TOTAL_COUNT => $snapshot->totalCount,
                TableConstants::RESULT_KEY_TOTAL_EXACT => $snapshot->totalExact,
            ];
        } catch (Throwable $e) {
            // Null here means "the count did not change", so a refused re-query is
            // indistinguishable from a steady total: this window's paginator freezes
            // on its old number and nothing anywhere says why.
            Logger::error(
                "Viewport count kept a stale total after its re-query failed: table={$viewport->tableKey}, "
                    . "page={$page}, acceptKey={$acceptKey}, "
                    . 'exception=' . $e::class . ", message={$e->getMessage()}, "
                    . 'at=' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return null;
        }
    }

    /**
     * Maps an in-window mutation to its pending row delta, or null otherwise.
     *
     * Out-of-window changes carry no row here — their effect is the live count (and,
     * for an inbound last-page row, a later append). An in-window delete drops the
     * row from the delivered set and removes it; an in-window update re-sends it.
     *
     * An update whose row comes out identical to the one this connection was already
     * given sends nothing at all: what reaches the screen is the rendered row, not the
     * record behind it, so a change to a field the row does not carry would otherwise
     * raise a gate badge whose "apply" leaves the screen exactly as it was. Membership of a
     * narrowed set is asked BEFORE that exit, because it is not a question about values: a
     * row can leave a filtered set over a field the delivered row never carried, and then the
     * payload is the same while the count, settled just before, has already taken it off.
     * Past the exit the question is asked once more for a set that looks unnarrowed, which a
     * table's own standing narrowing makes necessary; the answer itself is computed only once.
     *
     * An update that does change the rendered row is then classified by what it does to the
     * WINDOW, because that is what the gate holds — position and membership, not the fields of
     * a record (HIL-793). A row that left the filtered set and a row that moved past an edge of
     * the window are removals, told apart by their reason because only the first of them moves
     * the count; a row that changed its slot inside the window is a move carrying that slot; and
     * a row that stayed in its slot is the plain update, which the reader's screen applies at
     * once. The window that cannot say — no order, no remembered places, a table that refused a
     * comparison — sends the move without a slot rather than promising either answer.
     *
     * @param TableViewportSubscription $viewport Connection's window; its delivered rows are updated in place
     * @param ViewportTable $table Viewport table the window is on
     * @param TableRowMutationDTO $mutation Mutation the table built for the change
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @param bool $own Whether this receiver authored the change (applies at once, never gated)
     * @param Closure(): ?bool $membership Whether the row is in the set now, asked at most once per change, null when the table would not say
     * @return ?TableViewportDeltaDTO Pending row delta, or null when no row in the window changed
     * @throws TableRowKeyMissingException When the mutated row is a placeholder and carries no key
     */
    private function rowDeltaForMutation(
        TableViewportSubscription $viewport,
        ViewportTable $table,
        TableRowMutationDTO $mutation,
        string $page,
        string $browserKey,
        bool $own,
        Closure $membership,
    ): ?TableViewportDeltaDTO {
        $rowKey = (string) $mutation->rowKey;
        if (!$viewport->hasRow($rowKey)) {
            return null;
        }

        if ($mutation->type === TableMutationType::Delete) {
            $viewport->forgetRow($rowKey);

            return TableViewportDeltaDTO::rowRemoved(
                $page,
                $browserKey,
                $mutation->rowKey,
                TableViewportDeltaDTO::REASON_DELETED,
                $own,
            );
        }

        if ($mutation->row === null) {
            return null;
        }

        $query = $this->viewportQuery($viewport);
        $narrowed = $query->search !== null || $viewport->filter !== [];
        if ($narrowed && $membership() === false) {
            // Asked before the digest: a row can leave a narrowed set over a field the rendered
            // row does not carry, and then the payload is the same while the count has already
            // taken the row off. Silenced here, the screen would keep a row the counter no longer has.
            $viewport->forgetRow($rowKey);

            return TableViewportDeltaDTO::rowRemoved(
                $page,
                $browserKey,
                $mutation->rowKey,
                TableViewportDeltaDTO::REASON_LEFT_SET,
                $own,
            );
        }

        $wireRow = $this->browserRowToWire($table->browserRow($mutation->row));
        if ($viewport->matchesRow($rowKey, $wireRow)) {
            return null;
        }

        if (!$narrowed && $membership() === false) {
            // Still asked of a set that looks unnarrowed from outside: a table can carry a
            // standing narrowing in its own SQL, and without the question its fallen-out rows
            // would stay in the window for good.
            $viewport->forgetRow($rowKey);

            return TableViewportDeltaDTO::rowRemoved(
                $page,
                $browserKey,
                $mutation->rowKey,
                TableViewportDeltaDTO::REASON_LEFT_SET,
                $own,
            );
        }

        if ($query->sort === null) {
            // A window in the source's own sequence has no place for a row to have moved from,
            // so the edit is a value and nothing else. This is the leaf's own case (HIL-793):
            // most of the framework's own pages declare no order at all, and holding their
            // edits behind the gate raised a badge whose Apply changed nothing on the screen.
            $viewport->recordRow($rowKey, $wireRow, $table->anchorForRow($mutation->row, $query));

            return TableViewportDeltaDTO::rowUpdated(
                $page,
                $browserKey,
                $mutation->rowKey,
                $wireRow,
                $own,
            );
        }

        $placement = $this->viewportRowPlacementAfterUpdate($table, $viewport, $mutation, $query);
        if ($placement === TableRowPlacement::Above || $placement === TableRowPlacement::Below) {
            $viewport->forgetRow($rowKey);

            return TableViewportDeltaDTO::rowRemoved(
                $page,
                $browserKey,
                $mutation->rowKey,
                TableViewportDeltaDTO::REASON_MOVED_OUT,
                $own,
            );
        }

        $slot = $placement === TableRowPlacement::Inside
            ? $this->viewportRowIndex($table, $viewport, $mutation, $query)
            : null;
        $shownAt = array_search($rowKey, array_map(strval(...), array_keys($viewport->rowAnchors())), true);
        $viewport->recordRow($rowKey, $wireRow, $table->anchorForRow($mutation->row, $query));

        if ($slot !== null && $slot === $shownAt) {
            return TableViewportDeltaDTO::rowUpdated(
                $page,
                $browserKey,
                $mutation->rowKey,
                $wireRow,
                $own,
            );
        }

        return TableViewportDeltaDTO::rowMoved(
            $page,
            $browserKey,
            $mutation->rowKey,
            $wireRow,
            $slot,
            $own,
        );
    }

    /**
     * Queues an addressed worker-to-client table signal for one accept key.
     *
     * @param string $signalName Signal type name
     * @param SignalDataInterface $data Signal payload
     * @param string $acceptKey Target accept key
     */
    private function queueAddressedTableSignal(string $signalName, SignalDataInterface $data, string $acceptKey): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * Page count under a window size; at least one, one when unpaginated, and none when unknown.
     *
     * A total that stopped at its ceiling supports no page count at all: the number would be the
     * pages of the ceiling and not of the set. Null is what the frames turn into an absent key,
     * because zero would read as a table with no pages.
     *
     * @param int $totalCount Total rows matching the filter
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling it stopped at
     * @return ?int Page count, or null when the total is not exact
     */
    private function pageCount(int $totalCount, int $limit, bool $totalExact): ?int
    {
        if (!$totalExact) {
            return null;
        }
        if ($limit <= TableConstants::NO_LIMIT) {
            return 1;
        }

        return max(1, (int) ceil($totalCount / $limit));
    }

    /**
     * Collects logical row keys visible in a full browser table snapshot.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return list<int|string> Logical row keys for current source items
     */
    private function snapshotRowKeys(
        BrowserSourceConfig $browserConfig,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): array {
        $rowKeys = [];
        $seen = [];
        foreach ($this->anchorRowConfigs($browserConfig) as $rowConfig) {
            if (($rowConfig[BrowserFieldKey::MANY] ?? false) === true) {
                continue;
            }

            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            if (!is_array($source)) {
                continue;
            }

            $sourceItems = $this->sourceItemsForSnapshot($source);
            if ($sourceItems === []) {
                continue;
            }

            foreach ($sourceItems as $sourceItem) {
                if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams)) {
                    continue;
                }

                $rowKey = $this->rowKeyForSourceItem($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams);
                if ($rowKey === null || isset($seen[(string) $rowKey])) {
                    continue;
                }

                $seen[(string) $rowKey] = true;
                $rowKeys[] = $rowKey;
            }
        }

        return $rowKeys;
    }

    /**
     * Returns the row configs that own full-snapshot row keys.
     *
     * Only the first non-many source is the row anchor. Joined sources enrich
     * that row and must not add their own keys to the full browser snapshot.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @return list<array<string, mixed>> Anchor row config or an empty list
     */
    private function anchorRowConfigs(BrowserSourceConfig $browserConfig): array
    {
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            if (($rowConfig[BrowserFieldKey::MANY] ?? false) === true) {
                continue;
            }

            return [$rowConfig];
        }

        return [];
    }

    /**
     * Loads source items used for a full browser snapshot.
     *
     * DB-backed anchors must use a fresh full query so lazy key-only
     * collections do not shrink list pages to already-cached rows.
     *
     * A query that fails is not an empty page. The refusal travels to the caller, which is the
     * only place that can tell the subscriber its page did not arrive; swallowed here it would
     * arrive looking complete and empty.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return list<mixed> Snapshot source items
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DbCollectionNotReadableException When the database collection is mounted but not read here
     * @throws RtCollectionNotReadableException When the runtime collection is mounted but not read here
     * @throws DatabaseException When the source collection cannot be loaded or queried
     */
    private function sourceItemsForSnapshot(array $source): array
    {
        $collection = $this->sourceCollection($source);
        if ($collection instanceof DbCollection && $this->sourceType($source) === BrowserSourceType::DB) {
            $result = $collection->queryPageItems(new TableQueryDTO());
            $rows = $result[TableConstants::RESULT_KEY_ROWS] ?? [];

            return is_array($rows) ? array_values($rows) : [];
        }

        if (!is_iterable($collection)) {
            return [];
        }

        return is_array($collection) ? array_values($collection) : iterator_to_array($collection, false);
    }

    /**
     * Finds current source items that contribute to a logical browser row.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @param string $browserKey Browser table key, named when a declaration is refused
     * @param array<string, array<string, array<string, list<mixed>>>> $joinedItems Joined db items
     *     already read for the whole snapshot, by source key, join column and join value; empty
     *     on the reactive path
     * @return list<mixed> Current source items matching this row source
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When reading a joined database source fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function sourceItemsForRow(
        array $rowConfig,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
        string $browserKey,
        array $joinedItems,
    ): array {
        $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
        if (!is_array($source)) {
            return [];
        }

        $collection = $this->sourceCollection($source);
        if ($collection instanceof DbCollection && $this->sourceType($source) === BrowserSourceType::DB) {
            $collection = $this->dbSourceItemsForRow($rowConfig, $source, $collection, $rowKey, $sources, $browserKey, $joinedItems);
        }
        if (!is_iterable($collection)) {
            return [];
        }

        $items = [];
        foreach ($collection as $sourceItem) {
            if (!$this->sourceItemMatchesRowKey($rowConfig, $sourceItem, $rowKey, $acceptKey, $pageParams, $browserParams)) {
                continue;
            }
            if (!$this->sourceItemMatchesWhere($rowConfig, $sourceItem, $acceptKey, $pageParams, $browserParams)) {
                continue;
            }
            if (!$this->sourceItemMatchesVia($rowConfig, $sourceItem, $sources)) {
                continue;
            }

            $items[] = $sourceItem;
        }

        return $items;
    }

    /**
     * Narrows a database source to the rows that can belong to one browser row.
     *
     * Walking the collection instead would answer with whatever this process already holds: a
     * key-lazy collection loads by key and nothing else, so a set owned by somebody else's key
     * is invisible to a walk and the row arrives silently short. Three declarations, three
     * readings — the join column is the child's own key and is read by key; it is a foreign key
     * and the table is asked for it; it is neither, and the declaration is refused out loud
     * rather than answered with a plausible empty list.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param array<string, mixed> $source Browser source declaration
     * @param DbCollection $collection Database collection the source names
     * @param int|string $rowKey Logical row key
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @param string $browserKey Browser table key, named when the declaration is refused
     * @param array<string, array<string, array<string, list<mixed>>>> $joinedItems Joined db items
     *     already read for the whole snapshot, by source key, join column and join value
     * @return iterable<mixed> Rows that can belong to this browser row
     * @throws PageInternalErrorException When the declaration names no column to join by
     * @throws DatabaseException When the lookup query fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function dbSourceItemsForRow(
        array $rowConfig,
        array $source,
        DbCollection $collection,
        int|string $rowKey,
        array $sources,
        string $browserKey,
        array $joinedItems,
    ): iterable {
        $sourceKey = $this->sourceKey($source);
        if ($sourceKey === null) {
            throw new PageInternalErrorException('Invalid browser source config: source names no key');
        }

        [$joinColumn, $joinValue] = $this->rowConfigJoin($rowConfig, $rowKey, $sources);
        if ($joinColumn === null) {
            throw new PageInternalErrorException(
                "Browser join cannot be read by key: table={$browserKey}, source={$sourceKey} names no join column"
            );
        }
        if ($joinValue === null) {
            return [];
        }

        if ($collection->isKeyColumn($joinColumn)) {
            $item = $this->sourceItemById($source, (string) $joinValue);

            return $item === null ? [] : [$item];
        }

        if (isset($joinedItems[$sourceKey][$joinColumn])) {
            return $joinedItems[$sourceKey][$joinColumn][(string) $joinValue] ?? [];
        }

        return $collection->whereColumnIs($joinColumn, $joinValue);
    }

    /**
     * Reads the column a row config joins its source by, and the value that column must hold.
     *
     * Which column that is, is {@see BrowserSourceConfig::joinBy()}'s answer, shared with the
     * topology validator so the join a declaration is judged on is the join it is read by. What
     * belongs here is only the value: a VIA join takes it from the anchor fragment of this row,
     * and a row-key join is the row key.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param int|string $rowKey Logical row key
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return array{0: ?string, 1: int|string|null} Join column and the value it must hold
     */
    private function rowConfigJoin(array $rowConfig, int|string $rowKey, array $sources): array
    {
        [$joinColumn, $anchorField] = BrowserSourceConfig::joinBy($rowConfig);
        if ($joinColumn === null) {
            return [null, null];
        }
        if ($anchorField === null) {
            return [$joinColumn, $rowKey];
        }

        return [$joinColumn, $this->normalizeKey($this->sourceFragmentValue($sources, $anchorField))];
    }

    /**
     * Reads every joined database source of a snapshot once, in one query each.
     *
     * {@see self::buildBrowserRow()} runs per row, so a join read there is a query per row —
     * on the chat main list, one per conversation. The keys of a snapshot are known before its
     * rows are built, so a join that reads by the row key is asked for all of them at once and
     * handed out from memory. A join whose value comes from VIA is not here: that value is a
     * field of the anchor fragment, which does not exist until the row is built.
     *
     * @param BrowserSourceConfig $browserConfig Browser source config
     * @param list<int|string> $rowKeys Logical row keys of the snapshot
     * @return array<string, array<string, array<string, list<mixed>>>> Joined items by source key,
     *     join column and join value - by column as well as by source, because one source can be
     *     joined twice by different columns and one basket cannot answer both
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DatabaseException When a lookup query fails
     * @throws LogicException When a database collection is not configured with its class constants
     * @throws CollectionNotManualException When the collection built for a join refuses its own items
     */
    private function joinedItemsForSnapshot(BrowserSourceConfig $browserConfig, array $rowKeys): array
    {
        if ($rowKeys === []) {
            return [];
        }

        $joinedItems = [];
        foreach ($this->rowConfigs($browserConfig) as $rowConfig) {
            $source = $rowConfig[BrowserFieldKey::SOURCE] ?? [];
            $sourceKey = is_array($source) ? $this->sourceKey($source) : null;
            if ($sourceKey === null || $this->sourceType($source) !== BrowserSourceType::DB) {
                continue;
            }

            [$joinColumn, $anchorField] = BrowserSourceConfig::joinBy($rowConfig);
            if ($joinColumn === null || $anchorField !== null) {
                continue;
            }

            $collection = $this->sourceCollection($source);
            if (!$collection instanceof DbCollection || $collection->isKeyColumn($joinColumn)) {
                continue;
            }

            $byJoinValue = [];
            foreach ($collection->whereColumnIn($joinColumn, ...$rowKeys) as $item) {
                $byJoinValue[(string) $this->fieldValue($item, $joinColumn)][] = $item;
            }
            $joinedItems[$sourceKey][$joinColumn] = $byJoinValue;
        }

        return $joinedItems;
    }

    /**
     * Resolves a logical row key from a current source item.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @return int|string|null Browser row key
     */
    private function rowKeyForSourceItem(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): int|string|null {
        $rowKey = $rowConfig[BrowserListFieldKey::ITEM_KEY] ?? $rowConfig[BrowserTableFieldKey::ROW_KEY] ?? null;
        if (is_array($rowKey)) {
            return $this->normalizeKey($this->resolveReference($rowKey, $acceptKey, $pageParams, $browserParams));
        }

        if (is_string($rowKey) && $rowKey !== '') {
            return $this->normalizeKey($this->fieldValue($sourceItem, $rowKey));
        }

        return $this->normalizeKey($this->fieldValue($sourceItem, 'id'));
    }

    /**
     * Checks a current source item against the row-key declaration.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return bool True when the item belongs to the logical row
     */
    private function sourceItemMatchesRowKey(
        array $rowConfig,
        mixed $sourceItem,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): bool {
        $declaredRowKey = $rowConfig[BrowserListFieldKey::ITEM_KEY] ?? $rowConfig[BrowserTableFieldKey::ROW_KEY] ?? null;
        if (is_array($declaredRowKey)) {
            return $this->sameValue($this->resolveReference($declaredRowKey, $acceptKey, $pageParams, $browserParams), $rowKey);
        }

        if (!is_string($declaredRowKey) || $declaredRowKey === '') {
            return true;
        }

        return $this->sameValue($this->fieldValue($sourceItem, $declaredRowKey), $rowKey);
    }

    /**
     * Checks WHERE predicates for a current source item.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return bool True when all predicates match
     */
    private function sourceItemMatchesWhere(
        array $rowConfig,
        mixed $sourceItem,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
    ): bool {
        $where = $rowConfig[BrowserFieldKey::WHERE] ?? [];
        if (!is_array($where)) {
            return true;
        }

        foreach ($where as $field => $expected) {
            if (!is_string($field)) {
                continue;
            }
            if (!$this->sameValue(
                $this->fieldValue($sourceItem, $field),
                $this->resolveReference($expected, $acceptKey, $pageParams, $browserParams),
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks VIA predicates against source fragments already built for the row.
     *
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $sourceItem Current source item
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return bool True when every VIA predicate matches an earlier source value
     */
    private function sourceItemMatchesVia(array $rowConfig, mixed $sourceItem, array $sources): bool
    {
        $via = $rowConfig[BrowserFieldKey::VIA] ?? [];
        if (!is_array($via)) {
            return true;
        }

        foreach ($via as $sourceField => $rowField) {
            if (!is_string($sourceField) || !is_string($rowField)) {
                continue;
            }
            if (!$this->sameValue($this->fieldValue($sourceItem, $sourceField), $this->sourceFragmentValue($sources, $rowField))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Projects configured source and computed fields from one current item.
     *
     * @param string $browserKey Browser table key
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param mixed $item Current source item
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return array<string, mixed> Projected source fragment
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    private function projectSourceItem(
        string $browserKey,
        array $rowConfig,
        mixed $item,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): array {
        $payload = [];
        $fields = $rowConfig[BrowserFieldKey::FIELDS] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $sourceField => $targetField) {
                if (is_int($sourceField)) {
                    $sourceField = $targetField;
                }
                if (!is_string($sourceField) || !is_string($targetField)) {
                    continue;
                }
                $payload[$targetField] = $this->fieldValue($item, $sourceField);
            }
        }

        $computed = $rowConfig[BrowserFieldKey::COMPUTED] ?? [];
        if (is_array($computed)) {
            foreach ($computed as $field) {
                if (!is_string($field)) {
                    continue;
                }
                $payload[$field] = $this->computeBrowserField(
                    browserKey: $browserKey,
                    field: $field,
                    rowKey: $rowKey,
                    acceptKey: $acceptKey,
                    pageParams: $pageParams,
                    browserParams: $browserParams,
                    sources: $sources,
                );
            }
        }

        return $payload;
    }

    /**
     * Reads a source collection from Hilos::$db or Hilos::$rt.
     *
     * Null means one thing only: no collection of that name is mounted. A collection that IS
     * mounted and refused the read raises instead, because the two are not the same answer —
     * "the project mounts it later" is a state the page passes through on its way to working,
     * while a refused read is a defect that would deliver an empty page as though the page
     * itself were empty, and go on doing it for as long as the wiring stays wrong.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return ?iterable Current source collection, or null when no such collection is mounted
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DbCollectionNotReadableException When the database collection is mounted but not read here
     * @throws RtCollectionNotReadableException When the runtime collection is mounted but not read here
     * @throws DatabaseException When loading the mounted database collection fails
     */
    private function sourceCollection(array $source): ?iterable
    {
        $sourceKey = $this->sourceKey($source);
        $sourceType = $this->sourceType($source);
        if ($sourceKey === null || ($sourceType !== BrowserSourceType::DB && $sourceType !== BrowserSourceType::RT)) {
            throw new PageInternalErrorException('Invalid browser source config: source names no known collection');
        }

        try {
            return $sourceType === BrowserSourceType::DB
                ? Hilos::$db?->{$sourceKey}
                : Hilos::$rt?->{$sourceKey};
        } catch (CollectionNotFoundException|RtCollectionNotFoundException) {
            // An unknown collection under a well-formed declaration: the project may mount
            // it later, and the caller reads that as "nothing to deliver yet".
            return null;
        }
    }

    /**
     * Loads one source item by its source id.
     *
     * Null means the collection holds no such id. A lookup that failed travels instead: the two
     * were one answer before, and a row that refused to be read left the page looking like a
     * page whose row had been deleted.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @param string $sourceId Source id from the DB/RT sync fact
     * @return mixed Source item, or null when the collection holds no such id
     * @throws PageInternalErrorException When a page or source declaration is malformed
     * @throws DbCollectionNotReadableException When the database collection is mounted but not read here
     * @throws RtCollectionNotReadableException When the runtime collection is mounted but not read here
     * @throws DatabaseException When the source collection cannot be loaded or the item cannot be read
     */
    private function sourceItemById(array $source, string $sourceId): mixed
    {
        $collection = $this->sourceCollection($source);
        if (!$collection instanceof ArrayAccess) {
            return null;
        }

        $item = $collection[$sourceId] ?? null;
        if ($item !== null || !ctype_digit($sourceId)) {
            return $item;
        }

        return $collection[(int) $sourceId] ?? null;
    }

    /**
     * Reads one field from an array or magic item.
     *
     * The fallback below answers one question — "this item carries no such field" — so only that
     * question is caught here. A field whose read failed for any other reason is not a field the
     * item does not have, and answering it out of `toArray()` or with null would file a broken
     * read under a missing name.
     *
     * Three species say that one thing, because three kinds of item can arrive: a database view
     * item, a runtime view item, and the object layer underneath the first. They sit in three
     * exception families and are named here one by one; there is no common base to narrow to
     * that would not also catch a failed read.
     *
     * @param mixed $item Source item or row
     * @param string $field Field name
     * @return mixed Field value, or null when the item carries no such field
     */
    private function fieldValue(mixed $item, string $field): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        try {
            return $item->{$field};
        } catch (PropertyNotFoundException|PropertyNotAccessibleException|RtItemPropertyNotFoundException) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                $payload = $item->toArray();
                if (is_array($payload) && array_key_exists($field, $payload)) {
                    return $payload[$field];
                }
            }
        }

        return null;
    }

    /**
     * Resolves a browser config reference or returns a literal value.
     *
     * @param mixed $value Literal value or reference declaration
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @return mixed Resolved value
     */
    private function resolveReference(mixed $value, string $acceptKey, array $pageParams, array $browserParams): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $value[BrowserRefKey::TYPE] ?? null;
        $key = $value[BrowserRefKey::KEY] ?? null;

        return match ($type) {
            BrowserRefType::ACCEPT_KEY => $acceptKey,
            BrowserRefType::PAGE_PARAM => is_string($key) ? ($pageParams[$key] ?? null) : null,
            BrowserRefType::TABLE_PARAM => is_string($key) ? ($browserParams[$key] ?? null) : null,
            default => null,
        };
    }

    /**
     * Returns one field value from already built source fragments.
     *
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @param string $field Field name to find
     * @return mixed Field value or null
     */
    private function sourceFragmentValue(array $sources, string $field): mixed
    {
        foreach ($sources as $sourcePayload) {
            if (is_array($sourcePayload) && array_key_exists($field, $sourcePayload)) {
                return $sourcePayload[$field];
            }
        }

        return null;
    }

    /**
     * Reads a source declaration type.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return ?string Source type, or null when the declaration names none
     */
    private function sourceType(array $source): ?string
    {
        $type = $source[BrowserSourceKey::TYPE] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * Reads a source declaration key.
     *
     * @param array<string, mixed> $source Browser source declaration
     * @return ?string Source collection key, or null when the declaration names none
     */
    private function sourceKey(array $source): ?string
    {
        $key = $source[BrowserSourceKey::KEY] ?? null;

        return is_string($key) ? $key : null;
    }

    /**
     * Normalizes a row key to the browser-supported scalar contract.
     *
     * @param mixed $value Raw key value
     * @return int|string|null Normalized key
     */
    private function normalizeKey(mixed $value): int|string|null
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Compares config and source values with numeric-string tolerance.
     */
    private function sameValue(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_string($left)) && (is_int($right) || is_string($right))) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

    /**
     * Validates route params declared by a browser page config.
     *
     * @param mixed $paramConfigs Browser param declarations
     * @param PageRouteParams $params Route params from the subscription request
     * @throws PageSubscriptionException When a required param is missing or malformed
     */
    private function validateParams(mixed $paramConfigs, PageRouteParams $params): void
    {
        if (!is_array($paramConfigs)) {
            return;
        }

        foreach ($paramConfigs as $paramKey => $paramConfig) {
            if (!is_string($paramKey) || !is_array($paramConfig)) {
                continue;
            }

            $isRequired = ($paramConfig[BrowserParamKey::REQUIRED] ?? false) === true;
            if (($paramConfig[BrowserParamKey::TYPE] ?? BrowserParamType::STRING) === BrowserParamType::POSITIVE_INT) {
                if ($isRequired) {
                    $params->requirePositiveInt($paramKey);
                } else {
                    $params->getPositiveInt($paramKey);
                }
                continue;
            }

            if ($isRequired) {
                $params->requireString($paramKey);
            } else {
                $params->getString($paramKey);
            }
        }
    }

    /**
     * Enforces page-level access checks in a fixed order: lockdown, level, guards.
     *
     * The protected-mode route lockdown is checked first, as defense-in-depth behind
     * the master welcome choke: while the freeze is up every connection is refused all
     * page data with one domain sentence, the initiator's included, regardless of the
     * page's own guards (the lockdown is total, no per-page whitelist), and the
     * verification window is the one phase that reads a connection back in. The
     * page's declared ACCESS_LEVEL comes second ({@see PageAccessGate}): checking it
     * here — not only at subscribe time — is what starves a kept-alive denied
     * subscription of fan-out and table-window data, because most framework admin
     * pages declare no browser guards at all. The page's own browser guards run last.
     *
     * @param string $page Page name the config belongs to
     * @param BrowserPageConfig $pageConfig Browser page config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageServiceUnavailableException When the protected-mode freeze locks this connection out
     * @throws PageSubscriptionException When the access level or a guard rejects the subscription, or a guard declares an unsupported type
     */
    private function assertPageGuards(string $page, BrowserPageConfig $pageConfig, string $acceptKey, array $pageParams): void
    {
        if ($this->protectedModeLocksOut($acceptKey)) {
            throw new PageServiceUnavailableException();
        }

        $hilosClass = $this->hilosClass;
        $pageClass = $hilosClass::PAGES[$page] ?? null;
        if (is_string($pageClass)) {
            PageAccessGate::assert($pageClass, $acceptKey);
        }

        $this->assertGuardConfigs($pageConfig, $acceptKey, $pageParams);
    }

    /**
     * Whether every page guard passes for this connection — the non-throwing twin
     * of {@see self::assertPageGuards}.
     *
     * Browser delivery paths (the reactive fan-out and the table window) re-check
     * this on EVERY fan-out instead of relying on the subscription being absent: a
     * subscription is intentionally kept alive after a guard failure (the
     * live-promotion model, see PageSignalRouter::dispatchPageSubscribe), so a
     * guard-failed subscription must receive nothing WHILE the guard fails yet
     * resume the instant it passes (the missing resource appears / access granted).
     *
     * A malformed declaration is not a refusal and does not answer false: it leaves by the
     * rethrow below. PageInternalErrorException is a child of PageSubscriptionException, so
     * until that rethrow the two reached the same answer, and no caller could tell a connection
     * that is denied from a page that is broken. Each delivery path holds a trap for the broken
     * page — the traps sit outside this call and were unreachable for as long as it was
     * swallowed here.
     *
     * @param string $page Page name the config belongs to
     * @param BrowserPageConfig $pageConfig Browser page config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @return bool Whether this connection may receive the page's browser data now
     * @throws PageInternalErrorException When a guard or a source declaration is malformed
     * @throws DbCollectionNotReadableException When a guard's database collection is not read here
     * @throws RtCollectionNotReadableException When a guard's runtime collection is not read here
     */
    private function pageGuardsAllow(string $page, BrowserPageConfig $pageConfig, string $acceptKey, array $pageParams): bool
    {
        try {
            $this->assertPageGuards($page, $pageConfig, $acceptKey, $pageParams);
        } catch (PageInternalErrorException $e) {
            throw $e;
        } catch (PageSubscriptionException) {
            return false;
        }

        return true;
    }

    /**
     * Runs the browser guards a page declared, in declaration order.
     *
     * The one loop both entries share: the subscription request
     * ({@see self::assertSubscriptionAccess}) and the delivery paths
     * ({@see self::assertPageGuards}) judge a page's guards by the same code, so the
     * two cannot drift into judging it differently.
     *
     * @param BrowserPageConfig $pageConfig Browser page config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageSubscriptionException When a guard rejects the subscription
     * @throws PageInternalErrorException When a guard declares an unsupported type
     */
    private function assertGuardConfigs(BrowserPageConfig $pageConfig, string $acceptKey, array $pageParams): void
    {
        foreach ($pageConfig->guardConfigs() as $guard) {
            match ($guard[BrowserGuardKey::TYPE] ?? null) {
                BrowserGuardType::DB_EXISTS => $this->assertDbExistsGuard($guard, $acceptKey, $pageParams),
                BrowserGuardType::ACCESS => $this->assertAccessGuard($guard, $acceptKey),
                BrowserGuardType::AUTHENTICATED => $this->assertAuthenticatedGuard($acceptKey),
                default => throw new PageInternalErrorException('Unsupported browser guard type'),
            };
        }
    }

    /**
     * Whether the protected-mode freeze locks this connection out of all page data.
     *
     * Reads the daemon-owned runtime singleton synced into this worker; false (open)
     * when this process holds no runtime state, which is not a project declining the
     * mode - the row is mounted for every project that has an RT context. Same
     * lightweight in-memory read the master welcome path uses.
     *
     * The session behind the connection is offered beside its accept key, so this gate answers the
     * same way the master's welcome path does for the browser that started the operation (HIL-655).
     * The token comes from the session stage of the runtime connection roster - the framework's own
     * seam, the one {@see SessionCarrier} reads - and a connection the roster does not carry hashes
     * to null, which is the accept-key-only verdict this gate gave before.
     *
     * Neither name is a way past the freeze itself: while the node is frozen the row refuses every
     * connection, the initiator's included, and only the verification window reads the two names
     * as an admission (HIL-718).
     *
     * @param string $acceptKey Subscriber accept key
     * @return bool Whether this connection is frozen out right now
     */
    private function protectedModeLocksOut(string $acceptKey): bool
    {
        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($freeze === null) {
            return false;
        }

        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;

        return $freeze->locksOut(
            $acceptKey,
            $sessionToken === null ? null : ProtectedModeRuntime::hashSessionToken($sessionToken),
        );
    }

    /**
     * Enforces a DB-exists browser guard.
     *
     * @param array<string, mixed> $guard Browser guard config
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @throws PageSubscriptionException When the guarded DB item is absent
     */
    private function assertDbExistsGuard(array $guard, string $acceptKey, array $pageParams): void
    {
        $source = $guard[BrowserGuardKey::SOURCE] ?? [];
        if (!is_array($source)) {
            return;
        }

        $key = $this->normalizeKey(
            $this->resolveReference($guard[BrowserGuardKey::KEY] ?? null, $acceptKey, $pageParams, []),
        );
        if ($key === null || $this->sourceItemById($source, (string) $key) !== null) {
            return;
        }

        if (($guard[BrowserGuardKey::ERROR] ?? null) === BrowserSubscriptionError::FORBIDDEN) {
            throw new PageForbiddenException('Access forbidden');
        }

        throw new PageResourceNotFoundException("Resource #{$key} not found");
    }

    /**
     * Enforces an access browser guard: the connection's current user must exist
     * in the guard source and hold a truthy value in the named flag field (e.g.
     * `admin`). A guest (no resolvable authenticated user) is denied with a 401 —
     * the session is anonymous and must authenticate — while an authenticated user
     * missing the flag is denied with a 403, the same forbidden code an
     * access-denied DB_EXISTS guard uses.
     *
     * @param array<string, mixed> $guard Browser guard config
     * @param string $acceptKey Subscriber accept key
     * @throws PageUnauthorizedException When the subscriber is an anonymous session
     * @throws PageForbiddenException When the authenticated user lacks the flag
     * @throws PageInternalErrorException When the guard config is malformed
     */
    private function assertAccessGuard(array $guard, string $acceptKey): void
    {
        $userId = $this->resolveCurrentUserId($acceptKey);
        if ($userId === null) {
            throw new PageUnauthorizedException('Authentication required');
        }

        $source = $guard[BrowserGuardKey::SOURCE] ?? [];
        $field = $guard[BrowserGuardKey::FIELD] ?? null;
        if (!is_array($source) || !is_string($field) || $field === '') {
            throw new PageInternalErrorException('Invalid access guard config');
        }

        $user = $this->sourceItemById($source, (string) $userId);
        if ($user === null || $this->fieldValue($user, $field) !== true) {
            throw new PageForbiddenException('Access forbidden');
        }
    }

    /**
     * Enforces an authenticated browser guard: the connection must resolve to any
     * user. Unlike the ACCESS guard it carries no flag or source — a guest (no
     * resolvable user) is denied with a 401, and any authenticated user passes.
     * The page-level counterpart of the AUTH_ACTIONS action guard, for a page that
     * is readable only once signed in (e.g. the profile page).
     *
     * @param string $acceptKey Subscriber accept key
     * @throws PageUnauthorizedException When the subscriber is an anonymous session
     */
    private function assertAuthenticatedGuard(string $acceptKey): void
    {
        if ($this->resolveCurrentUserId($acceptKey) === null) {
            throw new PageUnauthorizedException('Authentication required');
        }
    }

    /**
     * Resolves the durable user id behind a connection's accept key, or null when
     * the connection has no user (a guest, or before any project identity).
     *
     * Reads the project seam ({@see self::resolveConnectionIdentity}) and flattens
     * its three states back to the two the guards judge by, answering null both for
     * a guest and for an identity still on its way. Flattening is safe on both paths
     * that reach a guard, for two different reasons: a client's own frame is judged
     * only after the dispatcher has held it ({@see PageSignalRouter::releasePendingFrames}),
     * so "not yet known" there means the deadline passed and today's verdict applies;
     * the reactive fan-out re-checks the same guards on every delivery and never went
     * through a frame at all, and a delivery it skips resumes on the next fan-out the
     * moment the guard passes — the live-promotion model.
     *
     * Sealed on purpose: a project answers the three-state seam instead, so there is
     * one connection→user source and not two that can disagree. `final` and not
     * `private` because the failure modes differ — an override attempt has to fail at
     * class load, where it is read as the instruction it is, rather than compile into
     * a second method this class never calls.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ?int Durable user id, or null when none is resolvable
     */
    final protected function resolveCurrentUserId(string $acceptKey): ?int
    {
        return $this->resolveConnectionIdentity($acceptKey)->userId;
    }

    /**
     * Resolves who is behind a connection, or says the answer has not arrived yet.
     *
     * The framework has no acceptKey -> user mapping — that identity is
     * project-owned (the project's runtime connection registry), written by the
     * agent that owns the WebSocket lifecycle in ITS worker and read here in
     * whatever worker serves the page. A project that uses the ACCESS guard or any
     * non-PUBLIC page level overrides this to read its own mapping: no row for this
     * accept key means the handshake write has not crossed the RT sync yet, so the
     * answer is {@see ConnectionIdentity::pending()}; a row means
     * {@see ConnectionIdentity::resolved()} with whatever user it names, including
     * null for a guest.
     *
     * The framework default is a settled "nobody", so a project that has not wired
     * identity behaves exactly as before and never parks a frame: guarded pages
     * close to everyone rather than leaking, and nothing waits for an answer that
     * would never come.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity Who is behind the connection, or the pending state
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(null);
    }

    /**
     * Resolves the authenticated user behind an accept key for the auth gates.
     *
     * Public seam over {@see self::resolveCurrentUserId} so the action dispatcher
     * ({@see PageSignalRouter::dispatchAction}) can gate a page's AUTH_ACTIONS and
     * the page access gate ({@see PageAccessGate}) can serve AUTHENTICATED/ADMIN
     * page levels, without either owning the connection→user mapping. Returns null
     * for an anonymous session, which the callers deny with a 401.
     *
     * @param string $acceptKey Acting connection accept key
     * @return ?int Authenticated user id, or null when the session is anonymous
     */
    public function resolveActionUserId(string $acceptKey): ?int
    {
        return $this->resolveCurrentUserId($acceptKey);
    }

    /**
     * Public seam over {@see self::resolveConnectionIdentity} for the frame dispatcher.
     *
     * Symmetric to the {@see self::resolveActionUserId} / {@see self::resolveCurrentUserId}
     * pair: the guards ask the flattened question, and
     * {@see PageSignalRouter::dispatchPageSubscribe} and its siblings ask this one —
     * whether there is an answer at all — to decide between judging a frame and parking it.
     *
     * @param string $acceptKey Acting connection accept key
     * @return ConnectionIdentity Who is behind the connection, or the pending state
     */
    public function connectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->resolveConnectionIdentity($acceptKey);
    }

    /**
     * Whether the authenticated user holds the project's admin privilege.
     *
     * The identity seam the page access gate ({@see PageAccessGate}) asks for
     * ADMIN-level pages. The framework cannot read a project's user storage from
     * this worker, so the default denies: a project that has not wired admin
     * identity closes its admin surface to everyone rather than opening it. A
     * project overrides this from its own runtime or database source. When a
     * central authorization hook lands (HIL-309), only this method's body
     * changes — no page declaration moves.
     *
     * An override reading the DATABASE must name what it reads in
     * {@see DbContext::processWideReadCollections()}, and nowhere else (HIL-750).
     * The gate answers for every gated page, in whatever worker serves it,
     * including pages that declare nothing of their own — so no page's topology
     * and no {@see AbstractPage::READS_DB} ever covers this read. Left
     * undeclared it does not fail loudly either: an override reads defensively
     * and turns the refusal into a denial, which reaches a person as their own
     * admin surface being forbidden to them.
     *
     * @param int $userId Authenticated durable user id
     * @return bool Whether this user may access ADMIN-level pages and actions
     */
    public function isAdmin(int $userId): bool
    {
        return false;
    }

    /**
     * Adds or replaces one browser row in the tick-local signal accumulator.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @param int|string $rowKey Logical row key
     * @param array{rowKey: int|string, sources: array<string, mixed>} $row Browser row payload
     */
    private function addBrowserRow(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $browserKey,
        int|string $rowKey,
        array $row,
    ): void {
        unset($signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::deleted][(string) $rowKey]);
        $signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::rows][(string) $rowKey] = $row;
    }

    /**
     * Adds one browser row delete to the tick-local signal accumulator.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     * @param int|string $rowKey Logical row key
     */
    private function addBrowserDelete(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $browserKey,
        int|string $rowKey,
    ): void {
        unset($signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::rows][(string) $rowKey]);
        $signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::deleted][(string) $rowKey] = $rowKey;
    }

    /**
     * Marks one page-bound table as fully cleared in the tick-local accumulator.
     *
     * Drops any rows/deletes already accumulated for the table this tick: a
     * truncate supersedes them. Rows created later in the same tick (for example
     * a follow-up marker event) are added after the clear and ride alongside it,
     * so the frontend truncates first and then applies them.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @param string $acceptKey Target accept key
     * @param string $page Subscribed page key
     * @param string $browserKey Browser table key
     */
    private function addBrowserClear(
        array &$signalTables,
        string $acceptKey,
        string $page,
        string $browserKey,
    ): void {
        unset(
            $signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::rows],
            $signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::deleted],
        );
        $signalTables[$acceptKey][$page][$browserKey][BrowserPageSignalData::cleared] = true;
    }

    /**
     * Compacts the tick-local accumulator to per-table row/delete payloads.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $signalTables Tick-local table accumulator
     * @return array<string, array<string, array<string, array<string, mixed>>>> Tables grouped by accept key and page key
     */
    private function buildBrowserPayloads(array $signalTables): array
    {
        $payloads = [];
        foreach ($signalTables as $acceptKey => $pages) {
            foreach ($pages as $page => $tables) {
                foreach ($tables as $browserKey => $changes) {
                    $rows = $changes[BrowserPageSignalData::rows] ?? [];
                    $deleted = $changes[BrowserPageSignalData::deleted] ?? [];
                    $cleared = ($changes[BrowserPageSignalData::cleared] ?? false) === true;
                    $payload = [];
                    if ($cleared) {
                        $payload[BrowserPageSignalData::cleared] = true;
                    }
                    if ($rows !== []) {
                        $payload[BrowserPageSignalData::rows] = array_values($rows);
                    }
                    if ($deleted !== []) {
                        $payload[BrowserPageSignalData::deleted] = array_values($deleted);
                    }
                    if ($payload !== []) {
                        $payloads[$acceptKey][$page][$browserKey] = $payload;
                    }
                }
            }
        }

        return $payloads;
    }

    /**
     * Routes per-table rows into a kind-classified page payload.
     *
     * Each source's kind decides its section: a list source becomes an ordered
     * item collection, a data source collapses to a single page-data blob, and a
     * table source keeps the row-collection shape. The row field bag is renamed
     * from `sources` to `slots` on the wire.
     *
     * @param array<string, array<string, mixed>> $browserByKey Per-table rows and deletes keyed by table key
     * @param array<string, array<string, mixed>> $windows First window per viewport-table key, already in wire shape
     * @return PagePayload Page payload split by section
     */
    private function pagePayloadFromBrowser(array $browserByKey, array $windows = []): PagePayload
    {
        $lists = [];
        $tables = [];
        $data = [];
        foreach ($browserByKey as $browserKey => $table) {
            $rows = $table[BrowserPageSignalData::rows] ?? [];
            $rows = is_array($rows) ? $rows : [];
            $deleted = $table[BrowserPageSignalData::deleted] ?? [];
            $deleted = is_array($deleted) ? $deleted : [];
            $cleared = ($table[BrowserPageSignalData::cleared] ?? false) === true;

            switch ($this->browserKind($browserKey)) {
                case BrowserSourceKind::LIST:
                    $section = [];
                    if ($cleared) {
                        $section[PagePayload::cleared] = true;
                    }
                    if ($rows !== []) {
                        $section[PagePayload::items] = $this->listItemsToWire($rows);
                    }
                    if ($deleted !== []) {
                        $section[PagePayload::deleted] = array_values($deleted);
                    }
                    $lists[$browserKey] = $section;

                    break;

                case BrowserSourceKind::DATA:
                    $fragments = $rows === []
                        ? []
                        : array_values(array_filter(
                            $rows[array_key_first($rows)][BrowserPageSignalData::sources] ?? [],
                            'is_array',
                        ));
                    $data[$browserKey] = $fragments === [] ? [] : array_merge(...$fragments);

                    break;

                default:
                    $section = [];
                    if ($cleared) {
                        $section[PagePayload::cleared] = true;
                    }
                    if ($rows !== []) {
                        $section[PagePayload::rows] = array_values(array_map($this->browserRowToWire(...), $rows));
                    }
                    if ($deleted !== []) {
                        $section[PagePayload::deleted] = array_values($deleted);
                    }
                    $tables[$browserKey] = $section;
            }
        }

        return new PagePayload(data: $data, lists: $lists, tables: $tables, windows: $windows);
    }

    /**
     * Renames each browser list item's `sources` bag to `slots` under the item key.
     *
     * A table row of the same page answer is shaped by {@see self::browserRowToWire()} instead,
     * which is where the row's own optional fields live: a list item is addressed by its place
     * in an order and carries none of them.
     *
     * @param list<array{rowKey: int|string, sources: array<string, mixed>}> $rows Browser rows
     * @return list<array<string, mixed>> Items reshaped for the page_response list section
     */
    private function listItemsToWire(array $rows): array
    {
        return array_values(array_map(
            static fn(array $row): array => [
                PagePayload::itemKey => $row[BrowserPageSignalData::rowKey],
                PagePayload::slots => $row[BrowserPageSignalData::sources],
            ],
            $rows,
        ));
    }
}

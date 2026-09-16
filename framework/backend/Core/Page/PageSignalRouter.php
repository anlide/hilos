<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Action\ActionHostInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\Exception\FramePopOrderException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Router\ActionErrorSignalDataInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Bulk\TableBulkRun;
use Hilos\Core\Table\DTO\TableBulkAcceptedReplyDTO;
use Hilos\Core\Table\DTO\TableBulkActionDTO;
use Hilos\Core\Table\DTO\TableBulkReportSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\Exception\TableBulkRunBusyException;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableFacetsSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * PageSignalRouter - Routes page signals to page handlers.
 *
 * Resolves page instances and dispatches subscribe/unsubscribe/action events.
 *
 * An action is the one frame here whose handler need not be a page: an agent that declares
 * AGENT_ACTIONS owns actions of its own, and since HIL-622 those come through this same
 * dispatcher rather than past it. Everything an action meets on the way in - the identity
 * wait, the throttle park, the auth guard, the tracked reply - is therefore written against
 * {@see ActionHostInterface} and not against a page.
 */
class PageSignalRouter
{
    /**
     * Milliseconds a frame waits for its connection's identity before it is judged anyway.
     *
     * A ceiling, not an expected wait: the measured window between the handshake answer and
     * the first frame judged without it was 49 ms (HIL-582), and the sweep that empties the
     * queue runs every worker tick, 10 ms apart. What the ceiling buys is the guarantee that
     * nothing here can make a connection worse off than it was before the queue existed —
     * an identity that never arrives costs half a second and then today's verdict.
     */
    private const int IDENTITY_WAIT_TIMEOUT_MS = 500;

    /**
     * What a subscription that failed for a reason of the node's own tells the client.
     *
     * One name for two senders. The generic catch has always sent it; since HIL-575 the
     * internal-error branch sends it too, in place of the engine's own words, and two spellings
     * of one sentence would drift the moment either is reworded.
     */
    private const string SUBSCRIBE_INTERNAL_ERROR = 'Internal error during subscription';

    /** The same sentence for an update, which the client shows against the page it is already on. */
    private const string UPDATE_INTERNAL_ERROR = 'Internal error during subscription update';

    /** Signal-to-page route config for non-action routed signals. */
    private SignalRouteConfig $signalRoutes;

    /** The anti-abuse guard's worker half, asked before a throttled action runs. */
    private ThrottleGate $throttleGate;

    /**
     * @var array<string, DeferredAction> Actions waiting on a throttle verdict, by request key
     *
     * Lives on the router of one page agent, which is where both ends of the wait meet: the
     * action was dispatched here, and the verdict is addressed back to this same agent.
     */
    private array $deferredActions = [];

    /** @var int Serial that makes each parked action's request key unique in this router */
    private int $deferredSequence = 0;

    /**
     * @var array<string, TableBulkRun> Mass operations this router is driving, by progress key
     *
     * The second pool beside {@see self::$deferredActions}, and in the same place for the same
     * reason: both ends of the wait meet on the router of one page agent. The action that
     * opened the run was dispatched here, and the verdict on every row comes back through
     * this same router, whether the page answers in that very call or a signal later.
     */
    private array $bulkRuns = [];

    /** @var int Serial that makes each run's progress key unique in this router */
    private int $bulkSequence = 0;

    /**
     * @var array<string, list<PendingFrame>> Frames held per connection until its identity lands, by accept key
     *
     * Per connection and strictly FIFO: while one frame of a connection waits, every later
     * frame of the same connection queues behind it even if the identity has since arrived.
     * Otherwise an action would overtake the subscribe it belongs to, and one race would
     * only have been traded for another.
     */
    private array $pendingFrames = [];

    /**
     * Creates page signal router with factory and action routes.
     *
     * @param AbstractPageFactory $pageFactory Page factory for resolving pages
     * @param ActionRouteConfig $actionRoutes Action-to-page route config
     * @param ?SignalRouteConfig $signalRoutes Optional signal-to-page route config; empty config when null
     */
    public function __construct(
        private AbstractPageFactory $pageFactory,
        private ActionRouteConfig $actionRoutes,
        ?SignalRouteConfig $signalRoutes = null,
    ) {
        $this->signalRoutes = $signalRoutes ?? new SignalRouteConfig();
        $this->throttleGate = new ThrottleGate();
    }

    /**
     * Dispatch page subscribe signal to page handler
     *
     * Catches PageSubscriptionException and sends structured error signal while
     * preserving the subscription. Catches any other exception as internal error.
     *
     * A subscription that arrives before this worker learns who sent it is parked
     * rather than judged ({@see self::parkUntilIdentified}), because the gate below
     * would read an unfinished answer as "a guest" and answer 401 to somebody who is
     * signed in. A parked frame resumes into the identical steps, so nothing here
     * distinguishes it.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function dispatchPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        if ($this->parkUntilIdentified(PendingFrameKind::PageSubscribe, $data, $source, $name)) {
            return;
        }

        $this->runPageSubscribeFrame($data, $source, $name);
    }

    /**
     * Re-judges one live page subscription after the acting user's rights changed (HIL-621).
     *
     * The receiving end of {@see PageAccessReassessment}. It runs the identical frame a
     * subscribe runs, so allow answers with a full page_response and deny answers with the
     * subscription error the same verdict would have produced at subscribe - one path, one
     * shape on the wire, and nothing new for the client to receive.
     *
     * What it deliberately does NOT do is re-subscribe. The subscription itself is unchanged;
     * only the answer to it is. That is why the frame never passes through
     * {@see DaemonManager::updateSubscriptions}: a real re-subscribe would rewrite the
     * registry entry, drop this connection's table viewports and bill the person an extra
     * page visit, none of which a re-decision means.
     *
     * {@see self::parkUntilIdentified} is not called either, and for the same kind of reason:
     * a subscribe has to wait to learn who sent it, while this frame exists BECAUSE that
     * answer is known - it was the change of that very identity's rights that queued it.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data, shaped exactly like the subscribe it re-judges
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function dispatchPageAccessReassess(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $data->page ?? $name;
        if ($page === '' || !$this->subscribedToPage($data->acceptKey, $page)) {
            // The tab navigated away, or closed, between the grant and this frame. There is
            // nothing to re-decide, and answering would push a page the client left.
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        if ($pageInstance::ACCESS_LEVEL === PageAccessLevel::PUBLIC && !$this->accessCanTurnOnIdentity($page)) {
            // A page whose verdict cannot depend on who is asking answers the same thing it
            // answered a moment ago. Sending it anyway would push a full page answer into
            // every open chat tab of the person on every grant: harmless frames, pure noise.
            return;
        }

        $this->runPageSubscribeFrame($data, $source, $name);
    }

    /**
     * Whether a PUBLIC page can still refuse a particular person through its own guards.
     *
     * The non-throwing twin of {@see BrowserContext::pageAccessDependsOnIdentity}, in the
     * shape the browser fan-out already uses to re-check a guard. A page
     * whose declaration is malformed cannot be shown to be identity-independent, so it is
     * re-judged like any other and {@see self::runPageSubscribeFrame} answers the broken
     * declaration exactly as a fresh subscribe answers it. Letting the exception out
     * instead would take the worker down on every admin grant, and with it every other
     * connection it serves.
     *
     * Any throwable and not only the subscription family, because the blast radius is the
     * point and it does not depend on which exception a project's page-config hook happened
     * to reach: the subscribe path contains all of them here too, and there is no catch
     * between this frame and the worker's message loop.
     *
     * @param string $page Page the re-decision is addressed to
     * @return bool Whether the page declares at least one browser guard
     */
    private function accessCanTurnOnIdentity(string $page): bool
    {
        try {
            return Hilos::$browser?->pageAccessDependsOnIdentity($page) === true;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Judges and dispatches one page subscribe frame, parked or not.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    private function runPageSubscribeFrame(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $data->page ?? $name;
        if ($page === '') {
            Logger::error('Page subscribe without page name');
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        try {
            // The whole verdict is reached BEFORE onSubscribe — the declared access
            // level, then the freeze, the route params and the page's browser guards.
            // onSubscribe is where a page reads domain state and runs its own side
            // effects, so a session that will be refused must not reach it at all: the
            // work would be done for an answer that never ships. The denial lands in
            // the PageSubscriptionException catch below, keeping the subscription alive
            // for live-promotion after sign-in or an admin grant.
            $params = new PageRouteParams($data->params);
            // Held for the one reader that needs it and only until the answer is built: the
            // windows this tab is holding decide which window each of the page's viewport
            // tables answers with (HIL-642). Written before the guards rather than after,
            // because a refusal has to clear it too, and the take does that.
            Hilos::$sr?->reportTableWindows($data->acceptKey, $data->tableWindows);
            PageAccessGate::assert($pageInstance::class, $data->acceptKey);
            Hilos::$browser?->assertSubscriptionAccess($page, $data->acceptKey, $params);
            $pageInstance->onSubscribe($data->acceptKey, $params);
        } catch (PageInternalErrorException $e) {
            // Ahead of its own base class, because it is not the same event. Every other species
            // below is a verdict about the resource or the rights, reached on purpose and worth
            // an info line and its own words to the subscriber. This one says the node is wrong
            // about itself: error, because nobody comes to fix an info line, and scrubbed,
            // because the text names the inside of the node and the subscriber can act on none
            // of it. The frame it sends is the internal-error frame either way — the species
            // already carries 500 and `internal_error`.
            Logger::error("Page subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            $this->sendSubscriptionError(
                $pageInstance,
                $page,
                $data->acceptKey,
                $e->httpCode,
                $e->errorCode,
                self::SUBSCRIBE_INTERNAL_ERROR,
            );
        } catch (PageSubscriptionException $e) {
            // The subscription is intentionally KEPT alive, not torn down. A guard
            // failure is a transient state, not a dead end: if the missing resource
            // later appears (e.g. user #10 is created while a client sits on its 404
            // page) or access is later granted, the guard starts passing and the live
            // fan-out promotes the error page into the real page with no re-subscribe
            // — the Hilos live-promotion model. Because the subscription stays
            // registered, the browser delivery paths must re-check the guard on every
            // fan-out (not rely on the subscription being absent): a guard-failed
            // subscription receives nothing WHILE the guard fails, yet resumes the
            // instant it passes.
            Logger::info("Page subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected page subscription error: page={$page}, exception={$e->getMessage()}");
            $this->sendSubscriptionError(
                $pageInstance,
                $page,
                $data->acceptKey,
                HttpConstants::HTTP_INTERNAL_ERROR,
                'internal_error',
                self::SUBSCRIBE_INTERNAL_ERROR,
            );
        } finally {
            // The report does not outlive the frame it arrived in, whatever the verdict was:
            // an answer takes it above, and a refusal drops it here. Left standing, it would
            // still be there when this page is re-sent to the same connection later, and the
            // window it names may by then be one the tab has moved on from.
            Hilos::$sr?->takeReportedTableWindows($data->acceptKey);
        }
    }

    /**
     * Dispatch page update subscription signal to page handler
     *
     * Judged like a fresh subscribe — access level, freeze, params, browser guards —
     * but on the MERGED param set, because the frame is allowed to carry only what it
     * changes. Mirrors {@see self::dispatchPageSubscribe} error handling: a
     * PageSubscriptionException becomes a structured subscription error signal, and
     * any other exception becomes a generic internal error. The subscription is
     * preserved either way, with its previous params.
     *
     * An update that arrives before this worker learns who sent it is parked rather
     * than judged ({@see self::parkUntilIdentified}), for the reason a subscribe is:
     * the gate below would read an unfinished answer as "a guest" and answer 401 to
     * somebody who is signed in. This door was the loudest of the four at that, because
     * the client-side gate holds back only page_subscribe — a param change sent right
     * after a reconnect arrived here with nothing in front of it. A parked frame
     * resumes into the identical steps, so nothing here distinguishes it.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function dispatchPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        if ($this->parkUntilIdentified(PendingFrameKind::PageUpdateSubscription, $data, $source, $name)) {
            return;
        }

        $this->runPageUpdateSubscriptionFrame($data, $source, $name);
    }

    /**
     * Judges one subscription update and settles the accepted params, parked or not.
     *
     * Answers nobody but the log when there is no verdict to reach: an unnamed page, a
     * page that does not resolve, or a connection that has since moved on. Each of those
     * used to be the same false, and one bit could not say which had happened
     * (docs/agents/code-style/method-contracts.md).
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    private function runPageUpdateSubscriptionFrame(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
            Logger::error('Page update subscription without page name');
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        if (!$this->subscribedToPage($data->acceptKey, $page)) {
            // The tab navigated away, or closed, before the frame was judged — a window
            // parking widens but did not invent. Judging it would run the page's
            // onUpdateSubscription for a page this connection no longer stands on, and the
            // mirror write below would then fail on a record that names another page: which
            // is exactly what the straight-through path did here, silently, before the check.
            //
            // Ahead of the guards on purpose. There is no verdict to reach about a page
            // nobody is on, and reaching one anyway would answer the client about it.
            Logger::info("Page update subscription for a page left behind: page={$page}, acceptKey={$data->acceptKey}");
            return;
        }

        try {
            $params = new PageRouteParams($this->mergedSubscriptionParams($data));
            PageAccessGate::assert($pageInstance::class, $data->acceptKey);
            Hilos::$browser?->assertSubscriptionAccess($page, $data->acceptKey, $params);
            $pageInstance->onUpdateSubscription($data->acceptKey, $params);

            try {
                // Only an accepted update settles into the mirrors: a set the guards
                // refused would otherwise judge the next fan-out for this connection.
                // Written here, beside the verdict, and no longer by the caller — the
                // answer that used to travel back as a bool never leaves this method.
                Hilos::$sr?->updatePageSubscription($page, $data);
            } catch (PageSubscriptionNotFoundException|PageSubscriptionMismatchException $e) {
                // The registry moved under an accepted update. The client is told nothing:
                // its update did happen, and the page already ran it.
                Logger::error("Cannot mirror page subscription update: page={$page}, acceptKey={$data->acceptKey}, {$e->getMessage()}");
            }
        } catch (PageInternalErrorException $e) {
            // Same order and the same reason as in dispatchPageSubscribe(): a node wrong about
            // itself is not a verdict about the subscriber, so it is logged as an error and its
            // words stay inside.
            Logger::error("Page update subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            $this->sendSubscriptionError(
                $pageInstance,
                $page,
                $data->acceptKey,
                $e->httpCode,
                $e->errorCode,
                self::UPDATE_INTERNAL_ERROR,
            );
        } catch (PageSubscriptionException $e) {
            Logger::info("Page update subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected page update subscription error: page={$page}, exception={$e->getMessage()}");
            $this->sendSubscriptionError(
                $pageInstance,
                $page,
                $data->acceptKey,
                HttpConstants::HTTP_INTERNAL_ERROR,
                'internal_error',
                self::UPDATE_INTERNAL_ERROR,
            );
        }
    }

    /**
     * Merges an update payload over the params the subscription already carries.
     *
     * The client sends only the params it changes, so the guards have to judge the set
     * the subscription would end up with: judging the sent fragment alone would refuse
     * an update over a required param the subscription already holds. Reads the same
     * worker-local subscription mirror the browser delivery paths read.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Update payload (acceptKey, page, params to merge)
     * @return array<string, string> Params the subscription would carry once this update applies
     */
    private function mergedSubscriptionParams(WebSocketPageUpdateSubscriptionSignalDTO $data): array
    {
        $current = Hilos::$sr?->getPageSubscriptions()[$data->acceptKey][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];

        return array_merge($current, $data->params);
    }

    /**
     * Dispatch page unsubscribe signal to page handler
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onUnsubscribe($data->acceptKey);
    }

    /**
     * Dispatch a table viewport signal: store the descriptor and reply the window.
     *
     * Records the connection's window descriptor for one table (so live deltas can
     * be scoped to the rows it shows), then has the browser context build and reply
     * the table_window snapshot for that descriptor.
     *
     * The quietest of the four identity-judged doors, and so the one parked with the
     * strictest condition ({@see self::parkUntilIdentified}): the window delivery
     * re-checks the page guards, and those guards judge by the params of the page
     * subscription, so this frame waits for its subscription as well as for the
     * identity. A refusal here answers nothing at all to the client, which is why it
     * now leaves a log line instead of only an absence.
     *
     * @param WebSocketTableViewportSignalDTO $data Viewport signal (acceptKey, tableKey, filter, sort, limit, address)
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     * @throws HilosException When the table's own sources refuse the reads its window rows need
     * @throws InvalidArgumentException When the table-window signal cannot be named
     */
    public function dispatchTableViewport(WebSocketTableViewportSignalDTO $data, string $source, string $name): void
    {
        if ($data->acceptKey === '' || $data->tableKey === '') {
            return;
        }

        if ($this->parkUntilIdentified(PendingFrameKind::TableViewport, $data, $source, $name)) {
            return;
        }

        $this->runTableViewportFrame($data, $source, $name);
    }

    /**
     * Stores one viewport descriptor and replies its window, parked or not.
     *
     * @param WebSocketTableViewportSignalDTO $data Viewport signal (acceptKey, tableKey, filter, sort, limit, address)
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     * @throws InvalidArgumentException When the table-window signal cannot be named
     */
    private function runTableViewportFrame(WebSocketTableViewportSignalDTO $data, string $source, string $name): void
    {
        $previous = Hilos::$sr?->getTableViewport($data->acceptKey, $data->tableKey);
        $viewport = new TableViewportSubscription(
            tableKey: $data->tableKey,
            filter: $data->filter,
            sort: $data->sort,
            limit: $data->limit,
            anchor: $data->anchor,
            anchorDirection: $data->anchorDirection,
            pageIndex: $data->pageIndex,
        );
        Hilos::$sr?->setTableViewport($data->acceptKey, $viewport);
        if (Hilos::$browser?->sendTableWindow($name, $data->acceptKey, $viewport) === false) {
            // The client asked for this window and gets no answer of any kind - not an
            // error frame, not an empty one. Without this line the refusal exists
            // nowhere, and a page silently missing its table looks the same as a page
            // whose table is genuinely empty.
            Logger::info(
                "Table window refused: page={$name}, table={$data->tableKey}, acceptKey={$data->acceptKey}",
            );

            return;
        }

        $this->recountFacets($name, $data->acceptKey, $viewport, $previous);
    }

    /**
     * Dispatches a client's table_facets frame: the options it asks counts beside, for one table.
     *
     * Parked until the connection is identified and subscribed to the page, exactly as the viewport
     * frame is, because the counts re-check the page guards a window re-checks.
     *
     * @param WebSocketTableFacetsSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws InvalidArgumentException When the facet-counts signal cannot be named
     */
    public function dispatchTableFacets(WebSocketTableFacetsSignalDTO $data, string $source, string $name): void
    {
        if ($data->acceptKey === '' || $data->tableKey === '') {
            return;
        }

        if ($this->parkUntilIdentified(PendingFrameKind::TableFacets, $data, $source, $name)) {
            return;
        }

        $this->runTableFacetsFrame($data, $source, $name);
    }

    /**
     * Records the options a connection asked counts beside, and answers with the counts at once.
     *
     * The list is kept beside the connection's window rather than inside it, and after this the
     * server sends the counts again on its own whenever the set changes ({@see self::recountFacets()}):
     * the client never comes back for them. A table with no window on this connection yet gets its
     * counts with its first window instead.
     *
     * @param WebSocketTableFacetsSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    private function runTableFacetsFrame(WebSocketTableFacetsSignalDTO $data, string $source, string $name): void
    {
        Hilos::$sr?->setTableFacets($data->acceptKey, $data->tableKey, $data->facets);

        $viewport = Hilos::$sr?->getTableViewport($data->acceptKey, $data->tableKey);
        if ($viewport !== null) {
            Hilos::$browser?->sendTableFacetCounts($name, $data->acceptKey, $viewport);
        }
    }

    /**
     * Sends again the counts a new window moved, and only those.
     *
     * The counts of a filter are taken over the set with that filter lifted, so they move when the
     * search or any OTHER filter changes, and stay where they are when the filter itself does. A page
     * turn, a new size or a new order change no filter at all and move nothing. A window with no
     * predecessor on this connection is its first, and every count goes out with it.
     *
     * @param string $page Page the table belongs to
     * @param string $acceptKey Connection the window was served to
     * @param TableViewportSubscription $viewport Window just served
     * @param ?TableViewportSubscription $previous Window it replaced, or null when it is the first
     */
    private function recountFacets(
        string $page,
        string $acceptKey,
        TableViewportSubscription $viewport,
        ?TableViewportSubscription $previous,
    ): void {
        if (Hilos::$sr === null || Hilos::$sr->getTableFacets($acceptKey, $viewport->tableKey) === []) {
            return;
        }

        if ($previous === null) {
            Hilos::$browser?->sendTableFacetCounts($page, $acceptKey, $viewport);

            return;
        }

        $changed = self::changedFilterKeys($previous->filter, $viewport->filter);
        $moved = array_values(array_filter(
            array_map(
                static fn(int|string $key): string => (string) $key,
                array_keys(Hilos::$sr->getTableFacets($acceptKey, $viewport->tableKey)),
            ),
            static fn(string $key): bool => array_diff($changed, [$key]) !== [],
        ));
        if ($moved !== []) {
            Hilos::$browser?->sendTableFacetCounts($page, $acceptKey, $viewport, $moved);
        }
    }

    /**
     * Names the filter-map keys whose value differs between two windows, the search among them.
     *
     * @param array<string, mixed> $before Filter map of the window replaced
     * @param array<string, mixed> $after Filter map of the window served
     * @return list<string> Keys set, cleared or changed between the two
     */
    private static function changedFilterKeys(array $before, array $after): array
    {
        $changed = [];
        foreach (array_keys($before + $after) as $key) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after) || $before[$key] !== $after[$key]) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /**
     * Dispatch action signal to its owner's handler
     *
     * Resolves the owner - this router's own agent when it declares the action, else the
     * page the action route configuration names - and parses the payload into the DTO
     * that owner declared for the action.
     *
     * When the action carried a client-minted requestId (a tracked action) the
     * framework replies with the action-success ack on success or the
     * action-error ack on failure, both correlated by that requestId. The ack
     * carries the domain reply the handler returned, when any. An untracked
     * action keeps the legacy path: silent on success, onActionException() on
     * failure — and a reply it returns is undeliverable, so it is dropped with a
     * warning.
     *
     * A payload the DTO refuses is one of those failures, which is why the DTO
     * is built inside the try: the client is owed the same fail-ack it gets for
     * an action that threw. The one thing such a failure cannot do is reach
     * onActionException(), since the DTO that hook is handed is the very thing
     * that could not be built; an untracked action then leaves only the log line.
     *
     * An action a page declared throttled is judged before any of that: refused here
     * when this worker's replica already shows a block, otherwise parked until the
     * throttle agent answers ({@see self::deferForThrottleVerdict()}). A parked action
     * resumes into the identical steps, so nothing below distinguishes it.
     *
     * Ahead of even that comes the identity wait ({@see self::parkUntilIdentified}):
     * an action from a connection this worker has not been told about yet is held
     * before anything reads it. It has to be the outer of the two waits, because a
     * throttle key is minted per session — keying one on an identity that has not
     * arrived would count a signed-in person's attempts against the anonymous bucket.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function dispatchAction(WebSocketActionSignalDTO $data, string $source): void
    {
        if ($this->parkUntilIdentified(PendingFrameKind::Action, $data, $source, $data->action)) {
            return;
        }

        $this->runActionFrame($data, $source);
    }

    /**
     * Routes and runs one action frame, parked or not.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    private function runActionFrame(WebSocketActionSignalDTO $data, string $source): void
    {
        $host = $this->resolveActionHost($data->action, $data->acceptKey);
        if ($host === null) {
            return;
        }

        $dto = null;

        try {
            $dto = $this->createActionPayloadDTO($host, $data);
            if ($this->deferForThrottleVerdict($host, $data, $dto)) {
                return;
            }
            $this->runAction($host, $data->acceptKey, $data->action, $dto, $data->requestId);
        } catch (Throwable $e) {
            $this->failAction($host, $data->acceptKey, $data->action, $dto, $data->requestId, $e);
        }
    }

    /**
     * Finds who owns an incoming action: this router's own agent, or one of its pages.
     *
     * The agent is asked first and by its own declaration, because that declaration is
     * what routed the frame to this agent in the first place; a name it does not claim
     * belongs to a page exactly as it always did. The two can never both claim a name -
     * topology validation refuses a project where an action has two owners.
     *
     * @param string $action Action name from the frame
     * @param string $acceptKey Accept key of the connection that sent it, for the log line
     * @return ?ActionHostInterface Owner of the action, or null when nothing owns it
     */
    private function resolveActionHost(string $action, string $acceptKey): ?ActionHostInterface
    {
        $agent = $this->pageFactory->getAgent();
        if ($agent instanceof AbstractAgent && array_key_exists($action, $agent::AGENT_ACTIONS)) {
            return $agent;
        }

        $page = $this->actionRoutes->getPageForAction($action);
        if ($page === null) {
            Logger::error("Action routing failed: action={$action}, acceptKey={$acceptKey}");
            return null;
        }

        return $this->resolvePage($page);
    }

    /**
     * Parses one action's payload into the DTO its owner declared for it.
     *
     * @param ActionHostInterface $host Owner the action was routed to
     * @param WebSocketActionSignalDTO $data Action frame as it arrived
     * @return ActionPayloadDTO Parsed payload
     * @throws AgentUnknownActionException When an agent-owned action has no computed DTO route
     * @throws ValidationException When the payload does not match the declared DTO
     */
    private function createActionPayloadDTO(ActionHostInterface $host, WebSocketActionSignalDTO $data): ActionPayloadDTO
    {
        if (!$host instanceof AbstractAgent) {
            return $this->pageFactory->createActionPayloadDTO($data->action, $data->data);
        }

        $dto = Hilos::$sr?->createAgentActionPayloadDTO($data->action, $data->data, $host->getType());
        if ($dto === null) {
            // The agent declares this action in AGENT_ACTIONS - that is how it was resolved as
            // the owner - so the computed route map must carry it too, and topology validation
            // refuses a project where the two disagree. Reaching here means this process is
            // routing against a map it does not share.
            throw new AgentUnknownActionException("Unknown agent action: {$data->action}");
        }

        return $dto;
    }

    /**
     * Runs one action's guards and handler, and answers a tracked caller.
     *
     * Shared by the straight-through dispatch and the resumed one, so a throttled action
     * meets the same guards in the same order as any other. The access-level guard is the
     * one step that is not shared: it judges a subscription to a page, and an agent has no
     * page for it to be asked about.
     *
     * A handler that finishes its action somewhere else says so, and then nothing is sent
     * from here: the request id went with the work, and the process doing it answers.
     *
     * The handler runs inside an origin scope carrying both the connection and the request
     * id, so every row it writes announces which press of which button produced it. The
     * accept key is already on the frame by the time we get here; restating it costs
     * nothing and keeps the two halves of an origin from being set in two places.
     *
     * @param ActionHostInterface $host Owner the action was routed to
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @throws ActionForbiddenException When the page's ADMIN level denies the acting user
     * @throws ActionUnauthorizedException When the page or the action requires a session the caller has not got
     * @throws FramePopOrderException When the handler leaves the execution frame stack imbalanced
     * @throws Throwable Whatever the action handler raises
     */
    private function runAction(
        ActionHostInterface $host,
        string $acceptKey,
        string $action,
        ActionPayloadDTO $dto,
        ?string $requestId,
    ): void {
        if ($host instanceof AbstractPage) {
            $this->assertPageAccessLevel($host, $acceptKey);
        }
        $this->assertActionAuthorized($host, $action, $acceptKey);
        $host->beginActionDispatch($requestId);
        try {
            $reply = ExecutionContext::withOrigin(
                $acceptKey,
                $requestId,
                fn (): ?ActionReplyDTO => $host->runAction($acceptKey, $action, $dto),
            );
            if ($host->actionReplyDeferred()) {
                // The handler passed the request id to whoever finishes this action, and
                // that process answers when it is done. Acking here would put "done" on the
                // wire in front of the state it announces.
                return;
            }

            if ($requestId !== null) {
                $host->sendActionSuccess($acceptKey, $action, $requestId, $reply);
                return;
            }

            if ($reply !== null) {
                // An untracked action has no requestId to correlate a reply to, so a
                // returned reply cannot be delivered. Almost always an integration
                // mistake on an answering action; drop it and log rather than fail.
                Logger::warning(
                    "Action reply dropped: untracked action returned a reply, "
                        . "host={$host->actionHostName()}, action={$action}",
                );
            }
        } finally {
            // After the ack, not before: the success sentence a handler set is read while
            // it is sent. And whichever way the dispatch left - answered, deferred, thrown -
            // it is over, so nothing of it stays readable for the next frame to quote.
            $host->endActionDispatch();
        }
    }

    /**
     * Reports one action's failure to its caller and to the log.
     *
     * @param ActionHostInterface $host Owner the action was routed to
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name
     * @param ?ActionPayloadDTO $dto Parsed action payload, or null when the payload is what failed
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @param Throwable $e Failure to report
     */
    private function failAction(
        ActionHostInterface $host,
        string $acceptKey,
        string $action,
        ?ActionPayloadDTO $dto,
        ?string $requestId,
        Throwable $e,
    ): void {
        // The client is told an action failed, never why: the frontend shows a
        // generic message and deliberately does not surface the backend reason.
        // Without this log the failure exists nowhere on the server either, so
        // a broken action is undiagnosable from the outside.
        Logger::error(
            "Action failed: host={$host->actionHostName()}, action={$action}, "
                . 'exception=' . $e::class . ', message=' . $e->getMessage(),
            [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ],
        );

        if ($requestId !== null) {
            // An admin page proved the acting user's privilege before the handler ran
            // (assertPageAccessLevel above), so the failure's own text may go back to them
            // and to nobody else. An agent host proves nothing of the kind: the level guard
            // judges a subscription to a page, and an agent has no page to be asked about.
            $host->sendActionFailure(
                $acceptKey,
                $action,
                $requestId,
                $e,
                $host instanceof AbstractPage && $host::ACCESS_LEVEL === PageAccessLevel::ADMIN,
            );
            return;
        }

        if ($dto !== null) {
            $host->onActionException($acceptKey, $action, $dto, $e);
        }
    }

    /**
     * Decides whether a throttled action may go straight through, and parks it when it may not.
     *
     * Three answers, in the order the plan fixes them: a key this worker already sees
     * blocked refuses at once and never reaches the agent; a keyed action that is not
     * blocked is parked and asked about; anything the layer does not cover - the feature
     * off, the action unlisted, no key to count against - passes untouched. Parking happens
     * before the access-level and action-auth guards on purpose: brute force must not be
     * able to learn from the guards which accounts exist, and a refusal it never waits for
     * is a refusal it can repeat.
     *
     * @param ActionHostInterface $host Owner the action was routed to
     * @param WebSocketActionSignalDTO $data Action being dispatched
     * @param ActionPayloadDTO $dto Parsed action payload, held for the resumed dispatch
     * @return bool True when the action has been parked and must not run now
     * @throws ActionRateLimitedException When a block on one of this action's keys is already in force
     */
    private function deferForThrottleVerdict(
        ActionHostInterface $host,
        WebSocketActionSignalDTO $data,
        ActionPayloadDTO $dto,
    ): bool {
        if (!in_array($data->action, $host->throttledActions(), true) || !$this->throttleGate->enabled()) {
            return false;
        }

        $requestKey = $data->acceptKey . '#' . ++$this->deferredSequence;
        $checks = $this->throttleGate->checksFor($data, $requestKey, $host->getAgentSignalSource());
        if ($checks === []) {
            return false;
        }

        $now = microtime(true);
        foreach ($checks as $check) {
            $blockedSeconds = $this->throttleGate->blockedSeconds($check, $now);
            if ($blockedSeconds !== null) {
                throw new ActionRateLimitedException($blockedSeconds);
            }
        }

        $this->deferredActions[$requestKey] = new DeferredAction(
            host: $host,
            acceptKey: $data->acceptKey,
            action: $data->action,
            dto: $dto,
            requestId: $data->requestId,
            deadline: $now + $this->throttleGate->verdictTimeoutSeconds(),
            awaitingVerdicts: count($checks),
        );

        foreach ($checks as $check) {
            $this->throttleGate->requestVerdict($check);
        }

        return true;
    }

    /**
     * Applies one throttle verdict to the action waiting on it.
     *
     * An action is keyed per scope and so waits on one verdict per key: the first refusal
     * settles it, and it runs only once every key has allowed it. A verdict for a key this
     * router no longer holds is not an error - its action was already refused by a sibling
     * verdict or released by the deadline - so it is dropped in silence.
     *
     * @param ThrottleVerdictSignalData $verdict Verdict from the throttle agent
     * @throws FramePopOrderException When the execution frame is unwound out of order
     */
    private function applyThrottleVerdict(ThrottleVerdictSignalData $verdict): void
    {
        $entry = $this->deferredActions[$verdict->requestKey] ?? null;
        if ($entry === null) {
            return;
        }

        $refusalSeconds = $this->throttleGate->refusalSeconds($verdict);
        if ($refusalSeconds === null && --$entry->awaitingVerdicts > 0) {
            return;
        }

        unset($this->deferredActions[$verdict->requestKey]);
        $this->resumeDeferredAction($entry, $refusalSeconds);
    }

    /**
     * Runs every parked action whose verdict did not arrive in time.
     *
     * Called once per worker tick. A missing verdict is this server's failure - a dropped
     * signal, a stopped agent - and not evidence against the client, so the action runs.
     * Blocks already in force do not leak out through this door: the fast path refuses
     * those without ever parking anything.
     *
     * @throws FramePopOrderException When the execution frame is unwound out of order
     */
    public function releaseExpiredDeferredActions(): void
    {
        if ($this->deferredActions === []) {
            return;
        }

        $now = microtime(true);
        foreach ($this->deferredActions as $requestKey => $entry) {
            if ($entry->deadline > $now) {
                continue;
            }

            unset($this->deferredActions[$requestKey]);
            Logger::error(
                "Throttle verdict timed out, running the action anyway: "
                    . "host={$entry->host->actionHostName()}, action={$entry->action}, acceptKey={$entry->acceptKey}",
            );
            $this->resumeDeferredAction($entry, null);
        }
    }

    /**
     * Finishes a parked action's dispatch, either into the handler or into a refusal.
     *
     * The connection is stamped back onto the execution frame first: the verdict arrived on
     * a signal of its own, so without this the resumed handler's writes would belong to
     * nobody and the client's own table deltas would not apply at once. The refusal is
     * raised inside that frame too, so a parked action reports its failure with the same
     * connection behind it as one that was never parked.
     *
     * @param DeferredAction $entry Action that was waiting
     * @param ?int $refusalSeconds Seconds the caller must wait, or null when the action may run
     * @throws FramePopOrderException When the execution frame is unwound out of order
     */
    private function resumeDeferredAction(DeferredAction $entry, ?int $refusalSeconds): void
    {
        ExecutionContext::withOrigin($entry->acceptKey, null, function () use ($entry, $refusalSeconds): void {
            try {
                if ($refusalSeconds !== null) {
                    throw new ActionRateLimitedException($refusalSeconds);
                }

                $this->runAction($entry->host, $entry->acceptKey, $entry->action, $entry->dto, $entry->requestId);
            } catch (Throwable $e) {
                $this->failAction($entry->host, $entry->acceptKey, $entry->action, $entry->dto, $entry->requestId, $e);
            }
        });
    }

    /**
     * Accepts one mass operation over a table, and answers that it was taken.
     *
     * The run is opened and nothing else happens here. Judging the rows inside this call would
     * stop every other connection this worker serves, so the work is driven a handful of rows
     * per tick by {@see self::advanceBulkRuns()} instead, and this returns the moment the
     * target is known.
     *
     * A second run on the same table from the same connection is refused rather than queued.
     * One place holds one bar: the second run would take the bar of the first and leave it
     * working blind, and an invisible queue promises a person work they will never be told of.
     * The place is the pair (page, table) and not the table alone, because that is how a bar is
     * addressed on the wire - two pages of one agent may each carry a table of the same key.
     *
     * @param AbstractPage $page Page the table belongs to
     * @param string $acceptKey Connection that asked, and the only one the frames go to
     * @param TableBulkActionDTO $dto Request naming the table and the target
     * @param ViewportTable $table Table the page resolved the request's key to
     * @return TableBulkAcceptedReplyDTO Acceptance carrying the run's key and its honest total
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     */
    public function startBulkRun(
        AbstractPage $page,
        string $acceptKey,
        TableBulkActionDTO $dto,
        ViewportTable $table,
    ): TableBulkAcceptedReplyDTO {
        foreach ($this->bulkRuns as $running) {
            if ($running->acceptKey === $acceptKey
                && $running->page === $page->getPageName()
                && $running->tableKey === $dto->tableKey
            ) {
                throw new TableBulkRunBusyException($dto->tableKey);
            }
        }

        $run = new TableBulkRun(
            progressKey: $dto->tableKey . ':' . ++$this->bulkSequence,
            acceptKey: $acceptKey,
            page: $page->getPageName(),
            tableKey: $dto->tableKey,
            table: $table,
            action: $dto->getAction(),
            rowKeys: $dto->rowKeys,
            filter: $dto->filter,
            total: $dto->rowKeys === null ? null : count($dto->rowKeys),
        );
        $this->bulkRuns[$run->progressKey] = $run;

        return new TableBulkAcceptedReplyDTO(
            $run->progressKey,
            $run->rowKeys !== null ? $run->total : $this->primeBulkTarget($run),
        );
    }

    /**
     * Records what the page decided about one row it was handed.
     *
     * A verdict for a row this router is not waiting on is dropped in silence, as a late
     * throttle verdict is: its run has already reported, or the deadline called the row
     * untouched before the owner spoke. Answering it would move a bar that is no longer there.
     *
     * @param string $progressKey Run the verdict belongs to
     * @param string $rowKey Row that was judged
     * @param ?string $reason Why the row was left alone, or null when it was changed
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    public function declareBulkVerdict(string $progressKey, string $rowKey, ?string $reason): void
    {
        $run = $this->bulkRuns[$progressKey] ?? null;
        if ($run === null || !$run->settle($rowKey)) {
            return;
        }

        if ($reason === null) {
            $run->recordTouched();
        } else {
            $run->recordUntouched($rowKey, $reason);
        }
        $this->emitBulkProgress($run, false);
    }

    /**
     * Moves every mass operation this router holds one step forward.
     *
     * Called once per worker tick, beside the two pools that wait on another process. The step
     * is deliberately small - overdue verdicts, then a handful of rows handed out, then the
     * report when there is nothing left - because the worker has every other connection to
     * serve between two ticks.
     *
     * The step runs inside the initiator's execution frame for the reason a resumed action
     * does ({@see self::resumeDeferredAction()}): the rows are changed by handlers reached from
     * here, and without the frame the initiator's own table deltas would not apply at once.
     *
     * @throws FramePopOrderException When the execution frame is unwound out of order
     * @throws InvalidArgumentException When a frame of a run cannot be named
     */
    public function advanceBulkRuns(): void
    {
        if ($this->bulkRuns === []) {
            return;
        }

        foreach ($this->bulkRuns as $run) {
            ExecutionContext::withOrigin($run->acceptKey, null, function () use ($run): void {
                $this->expireBulkVerdicts($run);
                $this->handOutBulkRows($run);
                $this->reportFinishedBulkRun($run);
            });
        }
    }

    /**
     * Calls untouched every row whose verdict did not arrive in time.
     *
     * Silence from the owner of a row is this server's failure - a dropped signal, a stopped
     * agent - so it is logged as one. It is never a reason to stop: a run that waits forever
     * is a bar that never comes down and a report nobody ever reads.
     *
     * @param TableBulkRun $run Run to sweep
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    private function expireBulkVerdicts(TableBulkRun $run): void
    {
        foreach ($run->overdue(microtime(true)) as $rowKey) {
            Logger::error(
                "Bulk verdict timed out, the row is reported untouched: "
                    . "page={$run->page}, table={$run->tableKey}, action={$run->action}, rowKey={$rowKey}",
            );
            $run->recordUntouched($rowKey, TableConstants::BULK_REASON_NO_VERDICT);
            $this->emitBulkProgress($run, false);
        }
    }

    /**
     * Hands the page its next rows to judge, up to what one tick is allowed to give out.
     *
     * The same bound holds both counts, as the constant says: no more than a handful in flight,
     * and no more than a handful handed out in one tick. Without the second the loop would walk
     * the whole target inside one tick whenever the page judges rows in the very call it is
     * asked, which is the ordinary case for a table whose rows the page owns itself.
     *
     * A row that has left the set is not handed to the page at all. That is the question the
     * ticket is proved by: a row a neighbour deleted before its turn came is reported untouched
     * with a reason, rather than quietly missing from the count.
     *
     * @param TableBulkRun $run Run to feed
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    private function handOutBulkRows(TableBulkRun $run): void
    {
        $handedOut = 0;
        while ($handedOut < TableConstants::BULK_ROWS_IN_FLIGHT
            && count($run->awaiting) < TableConstants::BULK_ROWS_IN_FLIGHT
        ) {
            $rowKey = $this->nextBulkRow($run);
            if ($rowKey === null) {
                return;
            }

            $handedOut++;
            if ($run->table->containsRow($rowKey, $this->bulkMembershipQuery($run)) === false) {
                $run->recordUntouched($rowKey, TableConstants::BULK_REASON_ROW_GONE);
                $this->emitBulkProgress($run, false);
                continue;
            }

            $run->handOut($rowKey, microtime(true) + TableConstants::BULK_VERDICT_TIMEOUT_SECONDS);
            $this->judgeBulkRow($run, $rowKey);
        }
    }

    /**
     * Asks the page about one row, and turns a judge that threw into a reason.
     *
     * A page whose hook raises is a page that did not decide, and the run has to say so about
     * that row and keep going: letting the failure out would break the tick that drives every
     * other run and every other pool of this worker, and would lose the report as well. The
     * words the client is allowed to see are the ones any other action failure travels with.
     *
     * @param TableBulkRun $run Run the row belongs to
     * @param string $rowKey Row to judge
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    private function judgeBulkRow(TableBulkRun $run, string $rowKey): void
    {
        $page = $this->resolvePage($run->page);
        if ($page === null) {
            $this->declareBulkVerdict($run->progressKey, $rowKey, TableConstants::BULK_REASON_NO_VERDICT);
            return;
        }

        try {
            $page->onBulkRow($run->action, $run->progressKey, $rowKey);
        } catch (Throwable $e) {
            Logger::error(
                "Bulk row judge failed: page={$run->page}, table={$run->tableKey}, "
                    . "action={$run->action}, rowKey={$rowKey}, error={$e->getMessage()}",
            );
            $this->declareBulkVerdict($run->progressKey, $rowKey, ActionFailureReason::forClient($e));
        }
    }

    /**
     * Takes the next row of the target, fetching one more window of a condition when needed.
     *
     * @param TableBulkRun $run Run to take from
     * @return ?string Next row key, or null when the target has nothing left
     */
    private function nextBulkRow(TableBulkRun $run): ?string
    {
        if ($run->queue === [] && !$run->drained) {
            $this->fetchBulkWindow($run);
        }

        return array_shift($run->queue);
    }

    /**
     * Reads one more window of the condition, from the place the last one ended.
     *
     * By anchor and never by offset: the run itself removes rows from the set it is walking, so
     * an offset would step over as many rows as the last window changed - the very defect the
     * window moved onto keys to be rid of.
     *
     * A window that comes back standing where it was taken from is thrown away and ends the
     * walk. Asked for the rows AFTER a place, a table answers with rows below it or with none,
     * so an answer ending at that same place is a table that did not honour the anchor: its
     * rows are the ones just judged, and following it would judge one window over and over,
     * every tick, for as long as the worker lives.
     *
     * @param TableBulkRun $run Run whose condition is being walked
     * @return TableSnapshotDTO Window the walk read, whose counts the opening of a run needs
     */
    private function fetchBulkWindow(TableBulkRun $run): TableSnapshotDTO
    {
        $snapshot = $run->table->getPage($this->bulkWindowQuery($run));
        $stalled = $snapshot->lastAnchor !== null
            && $snapshot->lastAnchor->toArray() === $run->cursor?->toArray();
        if ($stalled) {
            Logger::error(
                "Bulk run stopped: the window came back where it was taken from, so the table did "
                    . "not honour the anchor: page={$run->page}, table={$run->tableKey}",
            );
        }

        $run->queue = $stalled ? [] : $this->rowKeysOf($snapshot->rows, $run);
        $run->drained = $run->queue === [] || $snapshot->lastAnchor === null;
        $run->cursor = $snapshot->lastAnchor;

        return $snapshot;
    }

    /**
     * Opens the walk of a condition and answers how many rows it honestly has.
     *
     * The first window is read here rather than on the first tick because the acceptance has to
     * name the total, and the total comes off that same read. Above the count ceiling there is
     * no honest number - "500 of 500+" is the lie the ceiling exists to prevent - so the answer
     * is that the run has no estimate, which is a bar state of its own.
     *
     * @param TableBulkRun $run Run to open
     * @return ?int Rows the run will judge, or null when the set has no exact count
     */
    private function primeBulkTarget(TableBulkRun $run): ?int
    {
        $snapshot = $this->fetchBulkWindow($run);

        return $snapshot->totalExact ? $snapshot->totalCount : null;
    }

    /**
     * Reads the keys of one fetched window, in the order the table returned them.
     *
     * A row that cannot name itself is skipped and logged rather than judged: a bulk action is
     * addressed by row key from end to end, and a row with none could be neither reported nor
     * asked about.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Rows as the window returned them
     * @param TableBulkRun $run Run the window was fetched for, named in the log
     * @return list<string> Row keys to judge
     */
    private function rowKeysOf(array $rows, TableBulkRun $run): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $rowKey = $row instanceof AbstractTableRow ? $row->getRowKey() : null;
            if ($rowKey === null) {
                Logger::error(
                    "Bulk run skipped a row that names no key: page={$run->page}, table={$run->tableKey}",
                );
                continue;
            }

            $keys[] = (string)$rowKey;
        }

        return $keys;
    }

    /**
     * Builds the query one window of the target is read with.
     *
     * The search term is lifted out of the filter map exactly as the viewport does it, because
     * the condition the person chose is the one the panel showed them - a run that left the
     * term in the map would walk the unsearched set and touch rows nobody was looking at. The
     * order is the table's own, which is what makes the anchor mean anything.
     *
     * @param TableBulkRun $run Run whose condition is being walked
     * @return TableQueryDTO Window query for the next rows of the target
     */
    private function bulkWindowQuery(TableBulkRun $run): TableQueryDTO
    {
        return new TableQueryDTO(
            search: $this->bulkSearchTerm($run),
            sort: $run->table->defaultSort(),
            limit: TableConstants::BULK_ROWS_IN_FLIGHT,
            filter: $run->filter ?? [],
            anchor: $run->cursor,
            anchorDirection: TableAnchorDirection::After,
        );
    }

    /**
     * Lifts the search term out of the condition, the way the viewport does it.
     *
     * The term travels inside the filter map, and the query carries it in a field of its own;
     * a caller that left it in the map would describe the UNSEARCHED set, and the run would
     * touch rows the person who chose the condition never saw. One reader for both queries,
     * because two descriptions of one condition drift apart silently.
     *
     * @param TableBulkRun $run Run whose condition carries the term
     * @return ?string Term the set is searched by, or null when nothing is searched
     */
    private function bulkSearchTerm(TableBulkRun $run): ?string
    {
        $search = ($run->filter ?? [])[TableConstants::FILTER_KEY_SEARCH] ?? null;

        return is_string($search) ? $search : null;
    }

    /**
     * Builds the query a row's membership of the target is asked against.
     *
     * The same question for both targets, and only the set differs: named rows are asked about
     * against the table with no condition on it, a condition's rows against that condition. A
     * table that answers null has not said no - it has said it cannot tell - and the row goes
     * to the page, which is the one that can.
     *
     * @param TableBulkRun $run Run whose target describes the set
     * @return TableQueryDTO Query describing the set a row is placed against
     */
    private function bulkMembershipQuery(TableBulkRun $run): TableQueryDTO
    {
        return new TableQueryDTO(
            search: $this->bulkSearchTerm($run),
            filter: $run->filter ?? [],
        );
    }

    /**
     * Ends a run that has nothing left to judge: the report first, the bar down after it.
     *
     * That order is the whole of it. The other way round leaves the panel for an instant with
     * neither - the bar already gone, the report not yet there - and a view showing empty space
     * where work was running a moment ago.
     *
     * The outcome also goes to the log, every time and not only when something went wrong. A
     * run is not canceled by the tab that started it closing, and the frames of a run whose
     * initiator has gone reach nobody - the log line is then the only record that forty rows
     * were deleted and which of them were not.
     *
     * @param TableBulkRun $run Run to end, if it is over
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    private function reportFinishedBulkRun(TableBulkRun $run): void
    {
        if (!$run->isFinished()) {
            return;
        }

        unset($this->bulkRuns[$run->progressKey]);
        Logger::info(
            "Bulk run finished: page={$run->page}, table={$run->tableKey}, action={$run->action}, "
                . "progressKey={$run->progressKey}, touched={$run->touched}, "
                . 'untouched=' . (count($run->untouched) + $run->untouchedOmitted),
        );
        $this->queueBulkFrame(
            $run,
            SignalTypeConstants::TABLE_BULK_REPORT,
            new TableBulkReportSignalData(
                page: $run->page,
                tableKey: $run->tableKey,
                progressKey: $run->progressKey,
                touched: $run->touched,
                untouched: $run->untouched,
                untouchedOmitted: $run->untouchedOmitted > 0 ? $run->untouchedOmitted : null,
            ),
        );
        $this->emitBulkProgress($run, true);
    }

    /**
     * Sends the bar of one run, as far along as the run has judged.
     *
     * A judged row is the unit of the work, touched and untouched alike: what the person is
     * watching is the set being gone through, and a row that was left alone took its turn like
     * any other.
     *
     * @param TableBulkRun $run Run the bar is about
     * @param bool $ended Whether the work has stopped and the bar comes down
     * @throws InvalidArgumentException When the bar frame cannot be named
     */
    private function emitBulkProgress(TableBulkRun $run, bool $ended): void
    {
        $this->queueBulkFrame(
            $run,
            SignalTypeConstants::TABLE_PROGRESS,
            TableProgressSignalData::fromProgress(
                $run->page,
                $run->tableKey,
                new TableProgressDTO(
                    TableProgressScope::Bulk,
                    $run->progressKey,
                    null,
                    $run->judged,
                    $run->total,
                    $ended,
                ),
            ),
        );
    }

    /**
     * Addresses one frame of a run to the connection that started it.
     *
     * To that connection and to no other: the selection panel belongs to whoever was marking
     * rows, and the tabs merely watching the table have seen every touched row already as an
     * ordinary live delta.
     *
     * @param TableBulkRun $run Run the frame belongs to
     * @param string $signalName Frame name on the wire
     * @param SignalDataInterface $data Frame payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function queueBulkFrame(TableBulkRun $run, string $signalName, SignalDataInterface $data): void
    {
        $page = $this->resolvePage($run->page);
        if ($page === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: $page->getAgent()->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $run->acceptKey),
        );
    }

    /**
     * Holds a frame back when this worker does not yet know who is behind its connection.
     *
     * The identity is written by the agent that owns the WebSocket lifecycle, in its own
     * worker, and read here through the project browser context, so between the two lies
     * the RT sync. A frame that lands inside that window used to be judged against an
     * absent answer and refused 401 ({@see ConnectionIdentity} on why the answer has three
     * states and not two). It is queued instead, and swept back out by
     * {@see self::releasePendingFrames()} as soon as the answer arrives.
     *
     * A connection with a queue keeps queueing: the readiness of a later frame is not even
     * asked about while an earlier one waits, or an action would run before the subscribe
     * it belongs to. A project that resolves identity synchronously - or does not resolve
     * it at all, which is the framework default - never reaches the queue.
     *
     * @param PendingFrameKind $kind Which door the frame arrived at
     * @param WebSocketPageSubscribeSignalDTO|WebSocketPageUpdateSubscriptionSignalDTO|WebSocketActionSignalDTO
     *     |WebSocketTableViewportSignalDTO|WebSocketTableFacetsSignalDTO $data Frame as it arrived
     * @param string $source Signal source the frame was dispatched with
     * @param string $name Signal name the frame was dispatched with
     * @return bool True when the frame has been parked and must not run now
     */
    private function parkUntilIdentified(
        PendingFrameKind $kind,
        WebSocketPageSubscribeSignalDTO|WebSocketPageUpdateSubscriptionSignalDTO|WebSocketActionSignalDTO
            |WebSocketTableViewportSignalDTO|WebSocketTableFacetsSignalDTO $data,
        string $source,
        string $name,
    ): bool {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return false;
        }

        if (($this->pendingFrames[$acceptKey] ?? []) === [] && $this->frameIsReady($kind, $acceptKey, $name)) {
            return false;
        }

        $this->pendingFrames[$acceptKey][] = new PendingFrame(
            acceptKey: $acceptKey,
            kind: $kind,
            data: $data,
            source: $source,
            name: $name,
            deadline: microtime(true) + self::IDENTITY_WAIT_TIMEOUT_MS / 1000,
        );

        return true;
    }

    /**
     * Whether everything one frame is judged against has reached this worker.
     *
     * Every door waits for the connection's identity, and three of the four wait for
     * nothing else - a subscription update among them, because the mirror is written the
     * moment its subscribe is dispatched, so by the time an update of the same connection
     * is looked at the subscription is already there. The viewport door waits for one
     * thing more - the page subscription the frame is addressed to - because the window
     * delivery re-checks the page guards, and those guards read the subscription's params:
     * judged without it they judge an empty param set, which is a different question from
     * the one the client asked.
     *
     * @param PendingFrameKind $kind Door the frame arrived at
     * @param string $acceptKey Accept key of the connection that sent it
     * @param string $name Signal name the frame was dispatched with (page name for the viewport door)
     * @return bool Whether the frame may be judged now
     */
    private function frameIsReady(PendingFrameKind $kind, string $acceptKey, string $name): bool
    {
        if (Hilos::$browser?->connectionIdentity($acceptKey)->pending === true) {
            return false;
        }

        return !$kind->waitsForPageSubscription() || $this->subscribedToPage($acceptKey, $name);
    }

    /**
     * Whether this connection's page subscription is the one a frame is addressed to.
     *
     * @param string $acceptKey Accept key of the connection that sent the frame
     * @param string $page Page the frame is addressed to
     * @return bool Whether the subscription mirror already holds that page for this connection
     */
    private function subscribedToPage(string $acceptKey, string $page): bool
    {
        $subscribed = Hilos::$sr?->getPageSubscriptions()[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY] ?? null;

        return $subscribed === $page;
    }

    /**
     * Names what a frame was still waiting for when its deadline ran out.
     *
     * Only ever asked on the timeout path, where the answer is the whole diagnosis: an
     * identity that never crossed the RT sync and a subscription that never arrived are
     * different failures with different owners, and the frame itself cannot tell them
     * apart. Both can be outstanding at once, which is why they are reported together
     * rather than as the first one found. Only the viewport door can be waiting on the
     * subscription, so for the other three the line names the identity or nothing.
     *
     * @param PendingFrameKind $kind Door the frame arrived at
     * @param string $acceptKey Accept key of the connection that sent it
     * @param string $name Signal name the frame was dispatched with (page name for the viewport door)
     * @return string Unmet conditions, comma-separated
     */
    private function unmetWait(PendingFrameKind $kind, string $acceptKey, string $name): string
    {
        $unmet = [];
        if (Hilos::$browser?->connectionIdentity($acceptKey)->pending === true) {
            $unmet[] = 'identity';
        }
        if ($kind->waitsForPageSubscription() && !$this->subscribedToPage($acceptKey, $name)) {
            $unmet[] = 'subscription';
        }

        // Everything it waited for has arrived, and the frame is late for another
        // reason entirely: a tick that did not run, or a queue held up ahead of it.
        return $unmet === [] ? 'nothing (the queue itself was late)' : implode(',', $unmet);
    }

    /**
     * Runs every parked frame whose wait is over, in the order each connection sent them.
     *
     * Called once per worker tick, so a frame is delayed by the RT sync plus at most one
     * 10 ms loop and never by a wait on the stack - this worker serves every other
     * connection while these sit here. A frame whose deadline passed runs anyway, judged
     * exactly as it would have been before this queue existed: an identity that never
     * arrives is this server's failure, and answering the client late with today's verdict
     * is the one behavior guaranteed to be no worse than before.
     *
     * @throws FramePopOrderException When the execution frame is unwound out of order
     */
    public function releasePendingFrames(): void
    {
        if ($this->pendingFrames === []) {
            return;
        }

        $now = microtime(true);
        foreach (array_keys($this->pendingFrames) as $acceptKey) {
            while (($frame = $this->pendingFrames[$acceptKey][0] ?? null) !== null) {
                $expired = $frame->deadline <= $now;
                if (!$expired && !$this->frameIsReady($frame->kind, $acceptKey, $frame->name)) {
                    break;
                }

                // Off the queue BEFORE it runs: the dispatch it resumes into asks this same
                // queue whether to park, and a frame still standing in it would park itself
                // again, forever.
                array_shift($this->pendingFrames[$acceptKey]);
                if ($expired) {
                    // What the frame was waiting for is named rather than assumed: a
                    // viewport frame also waits for its page subscription, and a line
                    // that always blamed the identity would send the one reader this
                    // log exists for after the wrong thing.
                    Logger::error(
                        "Frame wait timed out, judging it anyway: "
                            . "frame={$frame->kind->value}, name={$frame->name}, acceptKey={$acceptKey}, "
                            . 'waitedFor=' . $this->unmetWait($frame->kind, $acceptKey, $frame->name),
                    );
                }
                $this->runPendingFrame($frame);
            }

            if (($this->pendingFrames[$acceptKey] ?? null) === []) {
                unset($this->pendingFrames[$acceptKey]);
            }
        }
    }

    /**
     * Throws away everything a closed connection was waiting to have judged.
     *
     * Nobody is left to answer: the frames would sit until their deadline and then be
     * dispatched into a socket that is gone, spending the worker's tick on delivering
     * refusals and windows to nobody.
     *
     * @param string $acceptKey Accept key of the closed connection
     */
    public function dropPendingFrames(string $acceptKey): void
    {
        unset($this->pendingFrames[$acceptKey]);
    }

    /**
     * Finishes one parked frame's dispatch, into the very steps it was stopped before.
     *
     * The connection is stamped back onto the execution frame first, for the reason it is
     * in {@see self::resumeDeferredAction()}: the sweep runs on the worker tick and not on
     * the frame's own signal, so without this the resumed handler's writes would belong to
     * nobody and the client's own deltas would not apply at once.
     *
     * Whatever a resumed frame raises stops here. The straight-through path answers its own
     * failures and lets the rest reach the signal dispatcher; this path has no dispatcher
     * above it, only the worker tick shared by every agent and every other connection.
     *
     * @param PendingFrame $frame Frame that was waiting
     * @throws FramePopOrderException When the execution frame is unwound out of order
     */
    private function runPendingFrame(PendingFrame $frame): void
    {
        ExecutionContext::withOrigin($frame->acceptKey, null, function () use ($frame): void {
            $data = $frame->data;

            try {
                if ($data instanceof WebSocketPageSubscribeSignalDTO) {
                    $this->runPageSubscribeFrame($data, $frame->source, $frame->name);
                } elseif ($data instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
                    $this->runPageUpdateSubscriptionFrame($data, $frame->source, $frame->name);
                } elseif ($data instanceof WebSocketActionSignalDTO) {
                    $this->runActionFrame($data, $frame->source);
                } elseif ($data instanceof WebSocketTableViewportSignalDTO) {
                    $this->runTableViewportFrame($data, $frame->source, $frame->name);
                } else {
                    $this->runTableFacetsFrame($data, $frame->source, $frame->name);
                }
            } catch (Throwable $e) {
                Logger::error(
                    "Parked frame failed after the wait: frame={$frame->kind->value}, "
                        . "name={$frame->name}, acceptKey={$frame->acceptKey}, "
                        . 'exception=' . $e::class . ', message=' . $e->getMessage(),
                );
            }
        });
    }

    /**
     * Enforces the host's action-level auth guard before the handler runs.
     *
     * A page or an agent lists the actions that require an authenticated session in its
     * AUTH_ACTIONS; an anonymous session (no resolvable user) invoking one is
     * denied a 401 before the handler, for the anonymous-read + authenticated-write
     * model. The connection→user resolution stays project-owned through the
     * browser context seam.
     *
     * @param ActionHostInterface $host Owner the action was routed to
     * @param string $action Dispatched action name
     * @param string $acceptKey Acting connection accept key
     * @throws ActionUnauthorizedException When a guarded action is invoked by an anonymous session
     */
    private function assertActionAuthorized(ActionHostInterface $host, string $action, string $acceptKey): void
    {
        if (!in_array($action, $host->authActions(), true)) {
            return;
        }

        if (Hilos::$browser?->resolveActionUserId($acceptKey) === null) {
            throw new ActionUnauthorizedException();
        }
    }

    /**
     * Enforces the page's declared ACCESS_LEVEL before an action handler runs.
     *
     * The same rule the subscription gate applies ({@see PageAccessGate}),
     * converted to the action-dispatch exception family: a guest on an
     * AUTHENTICATED/ADMIN page is denied 401 (the frontend opens the sign-in
     * modal), an authenticated non-admin on an ADMIN page is denied 403 — a
     * sign-in modal is useless to a user who is already signed in.
     *
     * @param AbstractPage $page Resolved page handler
     * @param string $acceptKey Acting connection accept key
     * @throws ActionUnauthorizedException When the level requires a user and the acting session is anonymous
     * @throws ActionForbiddenException When the level is ADMIN and the acting user lacks the admin privilege
     */
    private function assertPageAccessLevel(AbstractPage $page, string $acceptKey): void
    {
        try {
            PageAccessGate::assert($page::class, $acceptKey);
        } catch (PageUnauthorizedException $e) {
            throw new ActionUnauthorizedException(previous: $e);
        } catch (PageForbiddenException $e) {
            throw new ActionForbiddenException(previous: $e);
        }
    }

    /**
     * Dispatch binary frame signal to its configured page handler.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function dispatchFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::FRAME_BINARY, $name);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onSignalFrameBinary($data, $source, $name);
    }

    /**
     * Dispatch agent-to-agent signal to its configured page handler.
     *
     * The throttle verdict is answered here rather than by a page: it belongs to an action
     * this router parked, and no page ever sees that the action waited at all.
     *
     * @param AgentSignalData $data Wrapped agent signal payload
     * @param string $sender Sender in full - source, then agent type, then index
     * @param string $name Signal name
     * @throws AgentException
     * @throws FramePopOrderException When the execution frame is unwound out of order
     * @throws ValidationException When a validation failure cannot be mapped to an action error
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function dispatchAgentSignal(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name === HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT) {
            if ($data->data instanceof ThrottleVerdictSignalData) {
                $this->applyThrottleVerdict($data->data);
                return;
            }

            // The payload arrives typed only when the receiving agent declares the verdict
            // route with its DTO in AGENT_SIGNALS. Without that declaration every throttled
            // action on this agent's pages waits out its deadline and then runs, which is a
            // guard that quietly does nothing - so the omission is named.
            // Since HIL-858 the same omission is also refused at startup, by
            // TopologyValidator::validateThrottleVerdictRoutes(). This stays: it costs nothing
            // and still catches a topology that never went past the validator at all - a unit
            // test building the router directly, without Hilos::init().
            Logger::error(
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT
                    . ' arrived as ' . $data->data::class . '; the page agent must declare it in AGENT_SIGNALS'
                    . ' with ' . ThrottleVerdictSignalData::class,
            );
            return;
        }

        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::AGENT_SIGNAL, $name);
        if ($pageInstance === null) {
            return;
        }

        $innerPayload = $this->pageFactory->createPageSignalPayloadDTO(
            SignalTypeConstants::AGENT_SIGNAL,
            $name,
            $data->data,
        );
        $signalData = $innerPayload === $data->data ? $data : new AgentSignalData($innerPayload);

        try {
            $pageInstance->onSignalAgent($signalData, $sender, $name);
        } catch (ValidationException $e) {
            $this->dispatchAgentSignalActionException($pageInstance, $signalData, $e);
        }
    }

    /**
     * Dispatch cron signal to its configured page handler.
     *
     * @param SignalDataInterface $data Cron payload
     * @param string $source Signal source
     * @param string $name Cron job name
     */
    public function dispatchCron(SignalDataInterface $data, string $source, string $name): void
    {
        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::CRON, $name);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onSignalCron($data, $source, $name);
    }

    /**
     * Resolve page instance by name
     *
     * The router hands itself to the page on the way out, and this is the one door every page
     * of this agent comes through. A page needs it for the one thing it cannot do alone: a mass
     * operation is held in this router's pool, so starting one and declaring a verdict on a row
     * are both calls back into here, and the second of them happens long after the action that
     * started the run has returned.
     *
     * @param string $page Page name
     * @return ?AbstractPage Resolved page instance or null if not found
     */
    private function resolvePage(string $page): ?AbstractPage
    {
        if (!$this->pageFactory->hasPage($page)) {
            Logger::error("Unknown page: {$page}");
            return null;
        }

        try {
            $pageInstance = $this->pageFactory->getPage($page);
            $pageInstance->bindSignalRouter($this);

            return $pageInstance;
        } catch (PageNotFoundException $exception) {
            Logger::error("Page not found: {$page}");
            return null;
        }
    }

    /**
     * Resolve page instance for a signal route.
     *
     * @param string $signalType Signal type constant
     * @param string $name Signal name
     * @return ?AbstractPage Routed page instance or null when no route/page exists
     */
    private function resolveSignalPage(string $signalType, string $name): ?AbstractPage
    {
        $page = $this->signalRoutes->getPageForSignal($signalType, $name);
        if ($page === null) {
            return null;
        }

        return $this->resolvePage($page);
    }

    /**
     * Queues a structured subscription error signal to the originating connection.
     *
     * Shared by the subscribe and update-subscription dispatchers so both emit
     * the same SUBSCRIPTION_PAGE_ERROR contract without tearing down the connection.
     *
     * @param AbstractPage $pageInstance Page whose agent owns the signal source
     * @param string $page Page name that failed
     * @param string $acceptKey Target connection acceptKey
     * @param int $httpCode HTTP status code for the error
     * @param string $errorCode Machine-readable error code
     * @param string $message Human-readable error message
     */
    private function sendSubscriptionError(
        AbstractPage $pageInstance,
        string $page,
        string $acceptKey,
        int $httpCode,
        string $errorCode,
        string $message,
    ): void {
        Hilos::$sr->queueSignal(
            signalSource: $pageInstance->getAgent()->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
            signalData: new WebSocketSignalData(
                data: new PageSubscriptionErrorSignalData(
                    page: $page,
                    httpCode: $httpCode,
                    errorCode: $errorCode,
                    message: $message,
                ),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Routes a user-facing validation failure from an async agent signal through the page action error hook.
     *
     * @param AbstractPage $pageInstance Page that handled the routed signal
     * @param AgentSignalData $data Wrapped agent signal payload
     * @param ValidationException $e Validation failure to surface to the originating client
     * @throws ValidationException When the signal payload does not expose action error context
     */
    private function dispatchAgentSignalActionException(
        AbstractPage $pageInstance,
        AgentSignalData $data,
        ValidationException $e,
    ): void {
        $actionErrorData = $data->data;
        if (!$actionErrorData instanceof ActionErrorSignalDataInterface) {
            throw $e;
        }

        $acceptKey = $actionErrorData->getAcceptKey();
        if ($acceptKey === null) {
            throw $e;
        }

        $action = $actionErrorData->getActionErrorName();

        try {
            $dto = $this->pageFactory->createActionPayloadDTO(
                $action,
                $actionErrorData->getActionErrorPayload(),
            );
        } catch (ValidationException) {
            // The payload the signal carried back no longer parses, so the hook
            // has no DTO to be handed. The failure the client is waiting for is
            // still the original one, not this one.
            throw $e;
        }

        $pageInstance->onActionException($acceptKey, $action, $dto, $e);
    }
}

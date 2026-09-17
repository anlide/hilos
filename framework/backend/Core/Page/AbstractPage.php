<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionHostInterface;
use Hilos\Core\Action\ActionReply;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableBulkAcceptedReplyDTO;
use Hilos\Core\Table\DTO\TableBulkActionDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Exception\TableBulkActionNotOfferedException;
use Hilos\Core\Table\Exception\TableBulkRunBusyException;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * Base class for page handlers.
 *
 * Concrete pages own subscription, action, and routed signal hooks. Shared
 * framework state is resolved through the active Hilos facade.
 */
abstract class AbstractPage implements ActionHostInterface
{
    /** Page name or identifier, overridden by concrete pages. */
    public const string PAGE = '';

    /** Agent type that owns subscription signals for this page. */
    public const string SUBSCRIPTION_AGENT_TYPE = '';

    /**
     * Per-instance route: which entity instance's agent serves this page (HIL-627).
     *
     * Empty - the default - means the page is not per-instance and is routed by
     * SUBSCRIPTION_AGENT_TYPE alone, exactly as before. A page that fills it declares
     * where the instance index comes from and who takes the subscription when no index
     * can be determined; the keys and their accepted values are
     * {@see PageAgentIndexKey}.
     *
     * The master resolves the address once, on subscribe, and remembers it on the
     * subscription record: an unsubscribe carries no params at all, so an address
     * recomputed per signal would have nothing to recompute from.
     */
    public const array SUBSCRIPTION_AGENT_INDEX = [];

    /** WebSocket actions owned by this page, keyed by action name with payload DTO class values. */
    public const array ACTIONS = [];

    /**
     * Action names on this page that require an authenticated session.
     *
     * The action dispatcher denies a listed action invoked from an anonymous
     * session with an ActionUnauthorizedException (401) before onAction runs, for
     * the anonymous-read + authenticated-write model. A project that gates a write
     * action declares it here; the connection→user resolution stays project-owned
     * ({@see BrowserContext::resolveActionUserId}).
     */
    public const array AUTH_ACTIONS = [];

    /**
     * Access level every subscription and action on this page must clear.
     *
     * Enforced by {@see PageAccessGate} before onSubscribe, on every browser
     * fan-out, and before onAction. The project-page default stays PUBLIC, so
     * no existing project page changes behavior; the framework admin surface
     * inverts the default — {@see AbstractHilosPage} overrides this to ADMIN,
     * closing every hilos page unless it explicitly declares otherwise.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;

    /**
     * Action names on this page that the anti-abuse layer rate-limits (HIL-420).
     *
     * The action dispatcher counts attempts per (scope, identity, action) on a
     * listed action and, once the caller trips the window ladder or sits under a
     * durable block, denies it with an {@see ActionRateLimitedException} before
     * onAction runs — the framework never sleeps a single-threaded worker. A page
     * declares its expensive auth/detection actions here; the throttle is
     * framework-owned and activatable, so an empty list opts the page out.
     *
     * The list obliges the agent, not the page: a listed action is parked in the
     * worker of the {@see self::SUBSCRIPTION_AGENT_TYPE} agent, and the verdict is
     * addressed back there by signal name. That agent must therefore declare
     * HILOS_AUTH_THROTTLE_VERDICT in AGENT_SIGNALS with
     * {@see ThrottleVerdictSignalData} - and, when it is registered indexed, with
     * AgentSignalConfigKey::INDEX_FIELD => 'agentIndex', which names the parking
     * instance. A start whose page lists actions its agent cannot be answered
     * about is refused by {@see TopologyValidator}.
     */
    public const array THROTTLED_ACTIONS = [];

    /**
     * Non-action signal routes owned by this page, keyed by signal type.
     *
     * Type-wide routes use an empty list (`SignalTypeConstants::FRAME_BINARY => []`).
     * Named routes use either a list of signal name strings or
     * `signalName => SignalDataInterface` class map entries.
     */
    public const array SIGNALS = [];

    /** Browser data config declared by data-bearing pages. */
    public const array BROWSER = [];

    /**
     * @var list<string> DB collection keys this page reads BEYOND the ones its tables are built
     *     from. The browser config above already names what the page shows, and it is read off
     *     the topology; this list is for what its ACTIONS read - a lookup behind a submit, a
     *     neighbouring row a verdict is decided against - which no table names and which would
     *     otherwise be refused at the moment the user pressed the button (HIL-750).
     *
     *     The two lists add up. A collection already named by a table does not need repeating
     *     here, and repeating it is harmless: interest is held per collection, not per mention.
     *
     *     A subclass declaring this REPLACES what its parent declared, so one extending a page
     *     that has a list of its own carries it: `[...parent::READS_DB, …]`. Nothing complains
     *     if it does not; the parent's reads are refused where they happen.
     *
     *     What this list CANNOT cover is a page nobody subscribes to. It is taken up when a
     *     connection subscribes to the page and let go when it unsubscribes, so a page that hosts
     *     actions without a subscription of its own - a bell, a banner, anything addressed while
     *     the person is looking at something else - is never the subject of a take-up, and a list
     *     here would sit unread. Those reads belong to
     *     {@see DbContext::processWideReadCollections()}, which a project overrides for its own
     *     such pages (HIL-750). The framework itself no longer has one to point at: the
     *     notification center was the example, and HIL-860 turned it into a group.
     */
    public const array READS_DB = [];

    /**
     * @var list<string> RT collection keys this page reads BEYOND the ones its tables are built
     *     from. The browser config above already names what the page draws its rows out of, and
     *     it is read off the topology; this list is for a collection the page depends on without
     *     showing a single row of it.
     *
     *     What asks first is not an action but the frozen-replica mark: it is addressed to a page
     *     by the RT collections that page is interested in
     *     ({@see WorkerManager::notifyStalenessToPages()}), so a page drawing its picture out of
     *     something a collection stands behind - a mirror, a cache, a file index - is told the
     *     source went quiet only if it names that collection here. That is the difference from
     *     READS_DB above, whose first reader is a page's own actions: a read behind a submit that
     *     no table names would otherwise be refused at the moment the user pressed the button.
     *
     *     The two lists add up. A collection already named by a table does not need repeating
     *     here, and repeating it is harmless: interest is held per collection, not per mention.
     *
     *     A subclass declaring this REPLACES what its parent declared, so one extending a page
     *     that has a list of its own carries it: `[...parent::READS_RT, …]`. Nothing complains
     *     if it does not.
     *
     *     What this list CANNOT cover is a page nobody subscribes to, for the same reason
     *     READS_DB cannot: it is taken up when a connection subscribes to the page and let go
     *     when it unsubscribes, so on a page that only hosts actions it would sit unread. The
     *     PAGE-REACH guard reports that shape.
     */
    public const array READS_RT = [];

    /**
     * Whether the browser navigates to this page, or it only hosts actions.
     *
     * PageReach::ROUTE says a person can be on this page, so a subscription takes up
     * what it reads; PageReach::ACTION_HOST says nobody navigates here and its actions
     * arrive while the person is looking at something else, which is exactly when
     * READS_DB and READS_RT above are never taken up. UNDECLARED is the value of this root alone —
     * an answer here would declare every page in the repository at once.
     *
     * Nothing reads this at runtime: it is a declaration the PAGE-REACH guard judges,
     * not a switch. Like {@see PageAccessLevel} it is inherited, so a base answers for
     * its whole branch and a thin subclass writes nothing.
     */
    public const PageReach REACH = PageReach::UNDECLARED;

    /** Agent instance that owns this page handler. */
    protected PageAgentInterface $agent;

    /**
     * The node that answers this page's actions, built on first use.
     *
     * Lazily, because a page is constructed for every routed frame and most of them are
     * not actions at all - and because the node takes the page itself, which does not
     * exist yet while the page's own constructor runs.
     */
    private ?ActionReply $actionReply = null;

    /**
     * The router that serves this page, handed over the first time it resolves this page.
     *
     * A page reaches back into it for one thing only: a mass operation lives in the router's
     * pool, so starting one and declaring the verdict on a row are calls into that pool, and
     * the second of them happens long after the action that started the run has returned.
     */
    private ?PageSignalRouter $signalRouter = null;

    /**
     * Creates a page bound to its owning agent.
     *
     * @param PageAgentInterface $agent Agent instance
     */
    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Returns the static page identifier.
     *
     * @return string Page identifier from the PAGE constant
     */
    public function getPageName(): string
    {
        return static::PAGE;
    }

    /**
     * Returns the page agent instance.
     *
     * @return PageAgentInterface Agent instance
     */
    public function getAgent(): PageAgentInterface
    {
        return $this->agent;
    }

    /**
     * @return string Page name, under which the action dispatcher logs this host
     */
    public function actionHostName(): string
    {
        return static::PAGE;
    }

    /**
     * @return list<string> Action names of this page the anti-abuse layer judges before they run
     */
    public function throttledActions(): array
    {
        return static::THROTTLED_ACTIONS;
    }

    /**
     * @return list<string> Action names of this page that require an authenticated session
     */
    public function authActions(): array
    {
        return static::AUTH_ACTIONS;
    }

    /**
     * Returns the signal source of the agent this page belongs to.
     *
     * A page has no signal source of its own: everything it sends leaves the worker under
     * its agent's identity, which is what routes the frame back to the connection.
     *
     * @return SignalSourceInterface Signal source of the owning agent
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return $this->agent->getAgentSignalSource();
    }

    /**
     * Logs an info message under this page's owning agent id.
     *
     * @param string $message Message to log
     */
    protected function logAgentInfo(string $message): void
    {
        Logger::logAgentInfo($this->agent->getId(), $message);
    }

    /**
     * Logs an error message under this page's owning agent id.
     *
     * @param string $message Error message to log
     */
    protected function logAgentError(string $message): void
    {
        Logger::logAgentError($this->agent->getId(), $message);
    }

    /**
     * Logs an info message when a static page workflow only has an agent id.
     *
     * @param string $agentId Agent id to use as the log source
     * @param string $message Message to log
     */
    protected static function logAgentInfoForId(string $agentId, string $message): void
    {
        Logger::logAgentInfo($agentId, $message);
    }

    /**
     * Handles page subscription.
     *
     * Final: the frame below is the contract, and an override that forgot to
     * call the parent used to drop it silently. A concrete page extends the
     * subscription through onSubscribeBeforeResponse() and
     * onSubscribeAfterResponse() instead, and picks between them by the frame:
     * whatever must hold before the client is released - a route-param check
     * that refuses the subscription, a snapshot of the page's own that has to
     * be on the wire first - goes before it, and whatever may only run once the
     * subscription is answered goes after it.
     *
     * Route params are available through the typed accessors on
     * PageRouteParams; family-level abstract pages typically convert
     * them into an AbstractPageSubscribeParamsDTO subclass before
     * dispatching to a page-specific hook.
     *
     * The page_response frame closes every ACCEPTED subscription, carrying an
     * empty payload when the page contributes none. It is the answer the client
     * waits on before it shows the page, so a page that has nothing to say must
     * still say that — otherwise the client has no event to wait for and can
     * only show the page optimistically, ahead of a denial that may still be in
     * flight. It goes out LAST, after the browser snapshot, because it means
     * "this subscription is answered in full": sent first, it would release the
     * page while its snapshot was still on the wire. A subscription that throws
     * sends no answer at all — the PageSubscriptionException becomes a
     * subscription_page_error, which the client waits on the same way.
     *
     * The frame also carries the page's own identity - its heading, its lead and its breadcrumb -
     * whenever the page catalog holds an entry for it, so one subscription answers with
     * everything the page needs to draw itself. It is laid UNDER what the page built: a page that
     * wrote an identity key of its own keeps it, which is how a detail page will one day put the
     * name of its entity where the catalog holds a static caption.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws PageSubscriptionException When the before-response hook or the browser snapshot refuses the subscription
     * @throws InvalidArgumentException When the page-response signal cannot be named
     * @throws HilosException Whatever else the before-response hook or the concrete page's payload build raises
     */
    final public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribeBeforeResponse($acceptKey, $params);
        $payload = $this->withPageIdentity($this->buildPagePayload($acceptKey, $params));
        Hilos::$browser?->subscribeSnapshot(static::PAGE, $acceptKey, $params);
        $this->sendToUser(
            SignalTypeConstants::PAGE_RESPONSE,
            $acceptKey,
            new PageResponseSignalData(static::PAGE, $payload),
        );
        $this->onSubscribeAfterResponse($acceptKey, $params);
    }

    /**
     * Builds the page scope payload sent to a subscribing client.
     *
     * Default returns null: the page contributes no entities or page-data and
     * only the browser snapshot path runs. Override in concrete pages to send
     * an entity/data payload, returning null when there is nothing to send.
     * An override that reads domain state should raise a
     * PageSubscriptionException on failure so the framework reports a
     * subscription error to the client.
     *
     * The accept key comes first because the page_response frame this payload
     * rides is addressed personally: onSubscribe() sends it to the one
     * connection that asked, so what the payload says is allowed to differ
     * between two subscribers of the same page. A page that answers everyone
     * the same way ignores the argument; a page whose content depends on WHO
     * subscribed resolves that subscriber through it - the connection behind an
     * accept key, and the session behind that connection.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params from page subscription
     * @return ?PagePayload Page scope payload, or null when the page carries none
     * @throws PageSubscriptionException When the override refuses the subscription on its own terms
     * @throws HilosException Whatever else the override's read of domain state raises
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return null;
    }

    /**
     * Runs page-specific work before the page_response frame goes out.
     *
     * Default intentionally does nothing. Override to refuse the subscription on
     * the page's own terms before any answer is sent, or to put a snapshot of the
     * page's own ahead of the frame that releases the page.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws PageSubscriptionException When the override refuses the subscription on its own terms
     * @throws HilosException Whatever else the override's own work raises
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * Runs page-specific work after the page_response frame has gone out.
     *
     * Default intentionally does nothing. Override for a side effect a refused
     * subscription must not leave behind - putting the connection on a live push
     * list, for one. An exception raised here travels up as it is, even though
     * the client has already been answered.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws HilosException Whatever the override's own work raises
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * Handles page subscription update.
     *
     * Called when a client updates params of an existing page subscription.
     * Default is a no-op; override in child classes when partial-state refresh
     * is needed. Route params arrive as the merged snapshot the subscription
     * would carry — the params it already held with the update payload applied
     * over them — and the access level, the freeze and the page's browser guards
     * have already passed on exactly that set.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Merged route params for the subscription
     * @throws HilosException Whatever the concrete page's refresh raises
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * Handles page unsubscription.
     *
     * Default is a no-op; override in child classes when unsubscription cleanup
     * is needed.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws HilosException Whatever the concrete page's cleanup raises
     */
    public function onUnsubscribe(string $acceptKey): void
    {
    }

    /**
     * Handles a page action signal.
     *
     * Called when a client sends an action signal on this page.
     * Override in child classes when action handling is needed.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws AgentUnknownActionException When the page does not support the action
     * @throws HilosException Whatever the concrete page's action handler raises
     * @throws RandomException When a concrete page's handler cannot draw from the CSPRNG
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        throw new AgentUnknownActionException("Unknown action: {$action}");
    }

    /**
     * Runs this page's handler for one action the dispatcher routed here.
     *
     * The action-host spelling of {@see self::onAction()}: the dispatcher serves pages and
     * agents through one contract, and each names its own handler.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws AgentUnknownActionException When the page does not support the action
     * @throws HilosException Whatever the concrete page's action handler raises
     * @throws RandomException When a concrete page's handler cannot draw from the CSPRNG
     */
    public function runAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        return $this->onAction($acceptKey, $action, $dto);
    }

    /**
     * Sets the backend-authored success sentence for the action currently being
     * handled.
     *
     * Call from onAction() to have the framework success reply carry outcome text
     * the frontend surfaces as a success toast; the domain sentence lives on the
     * backend because Hilos i18n does. The message is consumed by the tracked
     * success reply that immediately follows onAction() and does not carry over to
     * a later action. Leave unset where the screen answers for itself: with no
     * sentence the frontend shows no success toast at all.
     *
     * @param string $message Backend-authored, already-localized success sentence
     */
    protected function setActionSuccessMessage(string $message): void
    {
        $this->actionReply()->setSuccessMessage($message);
    }

    /**
     * Resets the per-action slots at the start of an action dispatch.
     *
     * Called by PageSignalRouter before onAction() runs. The success message slot
     * is otherwise cleared only in sendActionSuccess()/sendActionFail(), neither of
     * which fires on the untracked path — so a message set by an untracked action
     * would survive and surface on the next action's ack. Clearing it up front
     * scopes the message to the action that set it.
     *
     * @param ?string $requestId Client-minted request id of this dispatch, or null when untracked
     */
    public function beginActionDispatch(?string $requestId = null): void
    {
        $this->actionReply()->beginDispatch($requestId);
    }

    /**
     * Ends the dispatch of one action, leaving no per-dispatch state readable behind it.
     *
     * Called by the dispatcher whichever way the action went - answered, deferred or thrown -
     * so that a frame built between dispatches cannot quote an answered request id.
     */
    public function endActionDispatch(): void
    {
        $this->actionReply()->endDispatch();
    }

    /**
     * Returns the request id of the action dispatch running right now.
     *
     * @return ?string Request id of the running dispatch, or null when the caller did not track it
     */
    public function currentActionRequestId(): ?string
    {
        return $this->actionReply()->requestId();
    }

    /**
     * Hands the answer to this action to whoever the handler passed the ending to.
     *
     * The one thing a handler may say about its own reply, and it is a negative: the
     * dispatcher must NOT ack, because an ack is on its way from another process and a second
     * one would tell the browser the command finished before it did. The mirror of what an
     * agent has had since HIL-622, needed on a page since HIL-771 gave the admin submits their
     * two-step shape - the page checks who is asking and forwards the write to the agent that
     * owns the table, so the page is no longer the last step of its own action.
     */
    protected function deferActionReply(): void
    {
        $this->actionReply()->defer();
    }

    /**
     * Whether the handler that just ran handed the answer to another process.
     *
     * @return bool True when this page owes no ack for the running action
     */
    public function actionReplyDeferred(): bool
    {
        return $this->actionReply()->isDeferred();
    }

    /**
     * Sends the default page action error signal.
     *
     * Optional hook called by PageSignalRouter when onAction() throws. Override
     * only when the page has a more specific user-facing error contract.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @param Throwable $e Action failure exposed to the client
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->actionReply()->sendException($acceptKey, $action, $e);
    }

    /**
     * Sends the framework action-success reply for a tracked action.
     *
     * Called by PageSignalRouter after onAction() returns without throwing, only
     * when the action carried a client-minted requestId. It releases the action's
     * loading state and resolves its request on the client, correlated by the
     * echoed requestId, and consumes any success sentence the handler set via
     * {@see AbstractPage::setActionSuccessMessage()} so the frontend can toast it.
     * The optional $reply the handler returned rides the same ack as domain data.
     *
     * @param string $acceptKey WebSocket accept key of the initiating client
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id to echo back for correlation
     * @param ?ActionReplyDTO $reply Domain reply the handler returned, or null when the action answered with nothing
     * @throws InvalidArgumentException When the action-success signal cannot be named
     */
    public function sendActionSuccess(
        string $acceptKey,
        string $action,
        string $requestId,
        ?ActionReplyDTO $reply = null,
    ): void {
        $this->actionReply()->sendSuccess($acceptKey, $action, $requestId, $reply);
    }

    /**
     * Sends the framework action-failure reply for a tracked action.
     *
     * Called by PageSignalRouter when onAction() throws, only when the action
     * carried a client-minted requestId; it supersedes onActionException() on
     * the tracked path so the failure correlates by the echoed requestId.
     *
     * @param string $acceptKey WebSocket accept key of the initiating client
     * @param string $action Action name that failed
     * @param string $requestId Client-minted request id to echo back for correlation
     * @param string $reason Human-readable error message exposed to the client
     * @param ?string $errorCode Machine-readable error code (e.g. 'unauthorized'), or null when unclassified
     * @param ?int $retryAfter Seconds the caller should wait before retrying (rate_limited failures), or null
     * @param ?string $errorType Class name of the failure the reason stands for, or null for anyone but an admin
     * @param ?string $errorDetail Original message of that failure, or null for anyone but an admin
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function sendActionFail(
        string $acceptKey,
        string $action,
        string $requestId,
        string $reason,
        ?string $errorCode = null,
        ?int $retryAfter = null,
        ?string $errorType = null,
        ?string $errorDetail = null,
    ): void {
        $this->actionReply()->sendFail($acceptKey, $action, $requestId, $reason, $errorCode, $retryAfter, $errorType, $errorDetail);
    }

    /**
     * Sends the framework action-failure reply a raised exception is turned into.
     *
     * The tracked path the dispatcher takes when onAction() throws: unlike
     * {@see AbstractPage::sendActionFail()}, whose reason a handler wrote itself, everything
     * here is read off the exception behind the one gate on what a client may see.
     *
     * @param string $acceptKey WebSocket accept key of the initiating client
     * @param string $action Action name that failed
     * @param string $requestId Client-minted request id to echo back for correlation
     * @param Throwable $e Failure the reply is built from
     * @param bool $detailAllowed Whether the caller is proven to be an administrator
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function sendActionFailure(
        string $acceptKey,
        string $action,
        string $requestId,
        Throwable $e,
        bool $detailAllowed,
    ): void {
        $this->actionReply()->sendFailure($acceptKey, $action, $requestId, $e, $detailAllowed);
    }

    /**
     * Handles a routed binary frame signal.
     *
     * Default intentionally ignores the signal. Override when the page owns
     * binary frame handling for its agent.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete page's binary-frame handler raises
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
    }

    /**
     * Handles a routed agent-to-agent signal.
     *
     * Default intentionally ignores the signal. Override when the page owns a
     * specific agent signal workflow while the agent remains the process boundary.
     *
     * Alone among the onSignal*() handles, the third argument here names the SENDER and not
     * the kind of source it is: two agents of one type are told apart by it, so it can be
     * kept as a key of per-sender state. The nine neighbours still receive the kind.
     *
     * @param AgentSignalData $data Wrapped signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete page's agent-signal workflow raises
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
    }

    /**
     * Handles a routed cron signal.
     *
     * Default intentionally ignores the signal. Override when a page owns the
     * scheduled workflow.
     *
     * @param SignalDataInterface $data Cron payload
     * @param string $source Signal source
     * @param string $name Cron job name
     * @throws HilosException Whatever the concrete page's scheduled workflow raises
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
    }

    /**
     * Queues a signal to a specific WebSocket connection by accept key.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class.
     *
     * @param string $signalName Signal name
     * @param string $acceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    protected function sendToUser(string $signalName, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * Queues a signal to the agent the application router sends this signal name to.
     *
     * The twin of {@see sendToUser()} for the case where a page is NOT the last step of its own
     * action: it checks what only it can check, hands the work to the agent that owns the thing,
     * and defers its ack ({@see deferActionReply()}). The owner may be on another node, which is
     * how the log viewer answers about a file this machine does not have (HIL-757).
     *
     * It lives on the page rather than being reached for on the agent because
     * {@see PageAgentInterface} is deliberately a small subset of the agent - a page that could
     * call anything on its agent is a page that could restart it.
     *
     * @param string $signalName Signal name the router picks the target agent by
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    protected function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName($signalName),
            signalData: new AgentSignalData(data: $data),
        );
    }

    /**
     * Hands this page the router of the agent that serves it.
     *
     * Called by {@see PageSignalRouter} every time it resolves a page, before anything is
     * dispatched into it. It exists for the mass-operation pool and nothing else: that pool
     * lives on the router, and a page both opens a run in it and answers about single rows
     * long after the opening action returned.
     *
     * @param PageSignalRouter $router Router of this page's agent
     */
    public function bindSignalRouter(PageSignalRouter $router): void
    {
        $this->signalRouter = $router;
    }

    /**
     * Accepts a mass operation over one of this page's tables, and answers that it was taken.
     *
     * Call from onAction() and return what it gives back as the action's reply. The answer says
     * the run was ACCEPTED - it is not the outcome and never becomes one: a run over a condition
     * outlives the client's action timeout, so the outcome arrives later as its own frame
     * (docs/agents/frontend/wire-protocol.md, "When the work outlives the reply").
     *
     * The table is resolved by the framework, not by the page: the key the request names has to
     * be one of this page's tables in the project's topology, and the table is then taken from
     * the registry the windows are served from. A page handed nothing to pick the table with has
     * nothing it could pick wrongly - a key from the wire cannot be answered with some other
     * table's object. Which operations the table takes is its own declaration, held by the router.
     *
     * @param string $acceptKey Connection that asked
     * @param TableBulkActionDTO $dto Request naming the table and the target
     * @return TableBulkAcceptedReplyDTO Acceptance carrying the run's key and its honest total
     * @throws TableBulkActionNotOfferedException When the key is not a table of this page, or the table does not offer the action
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     * @throws HilosException When the page was never handed its router
     */
    protected function startBulkAction(string $acceptKey, TableBulkActionDTO $dto): TableBulkAcceptedReplyDTO
    {
        $table = Hilos::$browser?->pageViewportTable(static::PAGE, $dto->tableKey);
        if ($table === null) {
            throw new TableBulkActionNotOfferedException($dto->tableKey, $dto->getAction());
        }

        return $this->requireSignalRouter()->startBulkRun($this, $acceptKey, $dto, $table);
    }

    /**
     * Judges one row of a running mass operation.
     *
     * The framework touches no row itself: it walks the target, asks the table whether the row
     * still belongs to it, and hands the row here. What happens to it is the page's to decide,
     * because the write belongs to the owner of the entity, and for most rows that owner is
     * another agent this page only asks.
     *
     * The verdict is ANNOUNCED and not returned: {@see self::bulkRowTouched()} in this very
     * call when the page owns the row, {@see self::bulkRowUntouched()} with a reason, or either
     * of them later from the signal the owner answers with. A hook that returned a value could
     * not serve the second case at all, and two shapes for one decision would be one behavior
     * with a flag.
     *
     * @param string $action Action the run was started under
     * @param string $progressKey Run the row belongs to, which the verdict carries back
     * @param string $rowKey Row to judge
     * @throws TableActionException Always, until the page that declared the action overrides this
     * @throws HilosException When an override cannot declare its verdict; the row is then reported untouched
     * @throws InvalidArgumentException When an override cannot name the ask it hands the row on with
     */
    public function onBulkRow(string $action, string $progressKey, string $rowKey): void
    {
        throw new TableActionException(
            'This page declared a bulk action and does not judge its rows: ' . static::PAGE . '/' . $action,
        );
    }

    /**
     * Declares that the page changed one row it was handed.
     *
     * @param string $progressKey Run the row belongs to
     * @param string $rowKey Row that was changed
     * @throws HilosException When the page was never handed its router
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    protected function bulkRowTouched(string $progressKey, string $rowKey): void
    {
        $this->requireSignalRouter()->declareBulkVerdict($progressKey, $rowKey, null);
    }

    /**
     * Declares that the page left one row alone, and why.
     *
     * The reason is shown to the person who started the run, so it is written for them: this is
     * the sentence that turns "39 of 40 deleted" into a report they can act on.
     *
     * @param string $progressKey Run the row belongs to
     * @param string $rowKey Row that was left alone
     * @param string $reason Why it was left, in words meant for the person who asked
     * @throws HilosException When the page was never handed its router
     * @throws InvalidArgumentException When a frame of the run cannot be named
     */
    protected function bulkRowUntouched(string $progressKey, string $rowKey, string $reason): void
    {
        $this->requireSignalRouter()->declareBulkVerdict($progressKey, $rowKey, $reason);
    }

    /**
     * Answers with the router serving this page, or refuses to guess.
     *
     * A page that was dispatched into has one, because the dispatcher binds it on the way in.
     * A page built by hand outside the dispatcher has none, and a mass operation started on it
     * would be held in a pool no tick ever visits - a run that accepts and then never reports.
     *
     * @return PageSignalRouter Router of this page's agent
     * @throws HilosException When the page was never handed its router
     */
    private function requireSignalRouter(): PageSignalRouter
    {
        if ($this->signalRouter === null) {
            throw new HilosException('Page ' . static::PAGE . ' has no signal router to run a bulk action in');
        }

        return $this->signalRouter;
    }

    /**
     * Queues a broadcast signal to all WebSocket connections.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     * @throws InvalidArgumentException When the signal name is empty
     */
    protected function sendToAllUsers(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Queues a broadcast signal to every connected WebSocket client, including
     * connections not subscribed to any page.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class. Use sendToAllUsers() for the usual
     * page-subscriber broadcast; reserve this for the rare all-connections case.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     * @throws InvalidArgumentException When the signal name is empty
     */
    protected function sendToAllConnected(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Lays this page's catalog identity under the payload the page built.
     *
     * Union rather than merge, so a key the page already wrote wins over the catalog: the
     * framework fills what the page left unsaid and overwrites nothing. A page the catalog does
     * not know - a public footer page, a project page outside the admin tree - passes through
     * unchanged, which is not an error but the ordinary case for most of a project's pages.
     *
     * @param ?PagePayload $payload Payload the page built, or null when it built none
     * @return PagePayload Payload carrying the page identity the catalog holds
     */
    private function withPageIdentity(?PagePayload $payload): PagePayload
    {
        $payload ??= new PagePayload();
        $identity = PageCatalogResolver::identity(static::PAGE);
        if ($identity === null) {
            return $payload;
        }

        return new PagePayload(
            entities: $payload->entities,
            data: $payload->data + [
                PageCatalogConstants::WIRE_PAGE_LABEL => $identity[PageCatalogConstants::CATALOG_ENTRY_LABEL],
                PageCatalogConstants::WIRE_PAGE_LEAD => $identity[PageCatalogConstants::CATALOG_ENTRY_LEAD],
                PageCatalogConstants::WIRE_PAGE_BREADCRUMB => PageCatalogResolver::breadcrumb(static::PAGE),
                PageCatalogConstants::WIRE_PAGE_CHILDREN => PageCatalogResolver::children(static::PAGE),
            ],
            lists: $payload->lists,
            tables: $payload->tables,
        );
    }

    /**
     * Returns this page's reply node, building it on first use.
     *
     * @return ActionReply Node that answers this page's actions
     */
    private function actionReply(): ActionReply
    {
        return $this->actionReply ??= new ActionReply($this);
    }
}

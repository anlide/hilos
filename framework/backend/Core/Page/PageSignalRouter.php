<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\Exception\FramePopOrderException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Router\ActionErrorSignalDataInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * PageSignalRouter - Routes page signals to page handlers.
 *
 * Resolves page instances and dispatches subscribe/unsubscribe/action events.
 */
class PageSignalRouter
{
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
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     */
    public function dispatchPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
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
            // The access level gates BEFORE onSubscribe: AbstractPage::onSubscribe
            // sends the page payload ahead of the browser guards, so a later check
            // would leak the payload to a denied session. The denial lands in the
            // PageSubscriptionException catch below, keeping the subscription alive
            // for live-promotion after sign-in or an admin grant.
            PageAccessGate::assert($pageInstance::class, $data->acceptKey);
            $pageInstance->onSubscribe($data->acceptKey, new PageRouteParams($data->params));
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
                'Internal error during subscription',
            );
        }
    }

    /**
     * Dispatch page update subscription signal to page handler
     *
     * Mirrors {@see self::dispatchPageSubscribe} error handling: a
     * PageSubscriptionException from onUpdateSubscription (e.g. re-validating the
     * merged route params) becomes a structured subscription error signal, and
     * any other exception becomes a generic internal error. The subscription is
     * preserved either way.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        try {
            $pageInstance->onUpdateSubscription($data->acceptKey, new PageRouteParams($data->params));
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
                'Internal error during subscription update',
            );
        }
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
     * @param WebSocketTableViewportSignalDTO $data Viewport signal (acceptKey, tableKey, filter, sort, offset, limit)
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     */
    public function dispatchTableViewport(WebSocketTableViewportSignalDTO $data, string $source, string $name): void
    {
        if ($data->acceptKey === '' || $data->tableKey === '') {
            return;
        }

        $viewport = new TableViewportSubscription(
            tableKey: $data->tableKey,
            filter: $data->filter,
            sort: $data->sort,
            offset: $data->offset,
            limit: $data->limit,
        );
        Hilos::$sr?->setTableViewport($data->acceptKey, $viewport);
        Hilos::$browser?->sendTableWindow($name, $data->acceptKey, $viewport);
    }

    /**
     * Dispatch action signal to page handler
     *
     * Resolves page from payload or action route configuration.
     * Creates ActionPayloadDTO via PageFactory.
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
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     */
    public function dispatchAction(WebSocketActionSignalDTO $data, string $source): void
    {
        $page = $this->actionRoutes->getPageForAction($data->action);

        if ($page === null) {
            Logger::error("Action routing failed: action={$data->action}, acceptKey={$data->acceptKey}");
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $dto = null;

        try {
            // Create typed DTO via PageFactory
            $dto = $this->pageFactory->createActionPayloadDTO($data->action, $data->data);
            if ($this->deferForThrottleVerdict($pageInstance, $data, $dto)) {
                return;
            }
            $this->runAction($pageInstance, $data->acceptKey, $data->action, $dto, $data->requestId);
        } catch (Throwable $e) {
            $this->failAction($pageInstance, $data->acceptKey, $data->action, $dto, $data->requestId, $e);
        }
    }

    /**
     * Runs one action's guards and handler, and answers a tracked caller.
     *
     * Shared by the straight-through dispatch and the resumed one, so a throttled action
     * meets the same guards in the same order as any other.
     *
     * @param AbstractPage $page Resolved page handler
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @throws ActionForbiddenException When the page's ADMIN level denies the acting user
     * @throws ActionUnauthorizedException When the page or the action requires a session the caller has not got
     * @throws Throwable Whatever the action handler raises
     */
    private function runAction(
        AbstractPage $page,
        string $acceptKey,
        string $action,
        ActionPayloadDTO $dto,
        ?string $requestId,
    ): void {
        $this->assertPageAccessLevel($page, $acceptKey);
        $this->assertActionAuthorized($page, $action, $acceptKey);
        $page->beginActionDispatch();
        $reply = $page->onAction($acceptKey, $action, $dto);
        if ($requestId !== null) {
            $page->sendActionSuccess($acceptKey, $action, $requestId, $reply);
            return;
        }

        if ($reply !== null) {
            // An untracked action has no requestId to correlate a reply to, so a
            // returned reply cannot be delivered. Almost always an integration
            // mistake on an answering action; drop it and log rather than fail.
            Logger::warning(
                "Action reply dropped: untracked action returned a reply, "
                    . "page={$page->getPageName()}, action={$action}",
            );
        }
    }

    /**
     * Reports one action's failure to its caller and to the log.
     *
     * @param AbstractPage $page Resolved page handler
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name
     * @param ?ActionPayloadDTO $dto Parsed action payload, or null when the payload is what failed
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     * @param Throwable $e Failure to report
     */
    private function failAction(
        AbstractPage $page,
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
            "Page action failed: page={$page->getPageName()}, action={$action}, "
                . 'exception=' . $e::class . ', message=' . $e->getMessage(),
            [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ],
        );

        $errorCode = match (true) {
            $e instanceof ActionRateLimitedException => $e->errorCode,
            $e instanceof ActionUnauthorizedException => $e->errorCode,
            $e instanceof ActionForbiddenException => $e->errorCode,
            default => null,
        };
        $retryAfter = $e instanceof ActionRateLimitedException ? $e->retryAfter : null;
        if ($requestId !== null) {
            $page->sendActionFail(
                $acceptKey,
                $action,
                $requestId,
                self::clientReason($e),
                $errorCode,
                $retryAfter,
            );
            return;
        }

        if ($dto !== null) {
            $page->onActionException($acceptKey, $action, $dto, $e);
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
     * @param AbstractPage $page Resolved page handler
     * @param WebSocketActionSignalDTO $data Action being dispatched
     * @param ActionPayloadDTO $dto Parsed action payload, held for the resumed dispatch
     * @return bool True when the action has been parked and must not run now
     * @throws ActionRateLimitedException When a block on one of this action's keys is already in force
     */
    private function deferForThrottleVerdict(
        AbstractPage $page,
        WebSocketActionSignalDTO $data,
        ActionPayloadDTO $dto,
    ): bool {
        if (!in_array($data->action, $page::THROTTLED_ACTIONS, true) || !$this->throttleGate->enabled()) {
            return false;
        }

        $requestKey = $data->acceptKey . '#' . ++$this->deferredSequence;
        $checks = $this->throttleGate->checksFor($data, $requestKey, $page->getAgent()->getAgentSignalSource());
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
            page: $page,
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
                    . "page={$entry->page->getPageName()}, action={$entry->action}, acceptKey={$entry->acceptKey}",
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
        ExecutionContext::withAcceptKey($entry->acceptKey, function () use ($entry, $refusalSeconds): void {
            try {
                if ($refusalSeconds !== null) {
                    throw new ActionRateLimitedException($refusalSeconds);
                }

                $this->runAction($entry->page, $entry->acceptKey, $entry->action, $entry->dto, $entry->requestId);
            } catch (Throwable $e) {
                $this->failAction($entry->page, $entry->acceptKey, $entry->action, $entry->dto, $entry->requestId, $e);
            }
        });
    }

    /**
     * Reduces an action failure to what may cross the wire.
     *
     * A domain failure (validation, authorization, a business rule) describes
     * itself in terms the caller asked about, so its message travels. Anything
     * infrastructural — a driver error carrying SQL text, index names, paths, or
     * an unexpected engine fault — stays on the server: the full detail is in the
     * log written above. See docs/agents/frontend/wire-protocol.md.
     *
     * @param Throwable $e Failure raised by the action handler
     * @return string Message safe to deliver to a client
     */
    private static function clientReason(Throwable $e): string
    {
        if ($e instanceof DatabaseException || !$e instanceof HilosException) {
            return SignalConstants::ACTION_FAILED_REASON;
        }

        return $e->getMessage();
    }

    /**
     * Enforces the page's action-level auth guard before the handler runs.
     *
     * A page lists write actions that require an authenticated session in its
     * AUTH_ACTIONS; an anonymous session (no resolvable user) invoking one is
     * denied a 401 before onAction, for the anonymous-read + authenticated-write
     * model. The connection→user resolution stays project-owned through the
     * browser context seam.
     *
     * @param AbstractPage $page Resolved page handler
     * @param string $action Dispatched action name
     * @param string $acceptKey Acting connection accept key
     * @throws ActionUnauthorizedException When a guarded action is invoked by an anonymous session
     */
    private function assertActionAuthorized(AbstractPage $page, string $action, string $acceptKey): void
    {
        if (!in_array($action, $page::AUTH_ACTIONS, true)) {
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentException
     * @throws FramePopOrderException When the execution frame is unwound out of order
     * @throws ValidationException When a validation failure cannot be mapped to an action error
     */
    public function dispatchAgentSignal(AgentSignalData $data, string $source, string $name): void
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
            $pageInstance->onSignalAgent($signalData, $source, $name);
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
            return $this->pageFactory->getPage($page);
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

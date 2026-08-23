<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalSourceInterface;
use Throwable;

/**
 * Whatever a client action belongs to - a page, or an agent through AGENT_ACTIONS.
 *
 * The action conveyor was written when a page was the only possible owner: the identity
 * wait, the throttle park, the auth guard and the tracked reply all live in
 * {@see PageSignalRouter} and all took an {@see AbstractPage}. An action owned by an agent
 * bypassed the conveyor entirely and so reached none of them. This interface is what the
 * conveyor talks to instead, so the second owner gets the same steps in the same order
 * rather than a second copy of them written inside the agent (HIL-622).
 *
 * Implemented by {@see AbstractPage} and {@see AbstractAgent}. A project changes what it
 * declares - THROTTLED_ACTIONS, AUTH_ACTIONS, the handler - and never these members.
 */
interface ActionHostInterface
{
    /**
     * @return string Name of this host for the dispatcher's log lines: a page name or an agent id
     */
    public function actionHostName(): string;

    /**
     * @return list<string> Action names of this host the anti-abuse layer judges before they run
     */
    public function throttledActions(): array;

    /**
     * @return list<string> Action names of this host that require an authenticated session
     */
    public function authActions(): array;

    /**
     * @return SignalSourceInterface Signal source every frame this host sends goes out from
     */
    public function getAgentSignalSource(): SignalSourceInterface;

    /**
     * Resets whatever this host scopes to a single dispatch, before the handler runs.
     *
     * @param ?string $requestId Client-minted request id of this dispatch, or null when untracked
     */
    public function beginActionDispatch(?string $requestId = null): void;

    /**
     * Returns the request id of the dispatch running right now.
     *
     * For the handler that has to hand its answer to another process: the request id is
     * what makes an ack sent from there land on the right call.
     *
     * @return ?string Request id of the running dispatch, or null when the caller did not track it
     */
    public function currentActionRequestId(): ?string;

    /**
     * Ends the dispatch of one action, leaving no per-dispatch state readable behind it.
     *
     * @see beginActionDispatch() for what this undoes
     */
    public function endActionDispatch(): void;

    /**
     * Whether the handler that just ran left the answer to somebody else.
     *
     * @return bool True when this host sends no ack of its own for the running action
     */
    public function actionReplyDeferred(): bool;

    /**
     * Runs this host's handler for one client action it owns.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name owned by this host
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws Throwable Whatever the concrete handler raises
     */
    public function runAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO;

    /**
     * Sends the action-success reply for a tracked action of this host.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param ?ActionReplyDTO $reply Domain reply the handler returned, or null when it answered with nothing
     * @throws InvalidArgumentException When the reply frame cannot be named
     */
    public function sendActionSuccess(
        string $acceptKey,
        string $action,
        string $requestId,
        ?ActionReplyDTO $reply = null,
    ): void;

    /**
     * Sends the action-failure reply for a tracked action of this host.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that failed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param string $reason Human-readable message the client may see
     * @param ?string $errorCode Machine-readable error code, or null when unclassified
     * @param ?int $retryAfter Seconds to wait before retrying, or null when not rate-limited
     * @throws InvalidArgumentException When the reply frame cannot be named
     */
    public function sendActionFail(
        string $acceptKey,
        string $action,
        string $requestId,
        string $reason,
        ?string $errorCode = null,
        ?int $retryAfter = null,
    ): void;

    /**
     * Reports an untracked action's failure, which has no request id to correlate a reply to.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Parsed action payload the handler was given
     * @param Throwable $e Failure to surface
     * @throws InvalidArgumentException When the error frame cannot be named
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void;
}

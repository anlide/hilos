<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Throwable;

/**
 * The one place a client action is answered from, for either owner of an action.
 *
 * Both frames the answer can be - the success ack and the error ack - and the
 * backend-authored success sentence that rides the first of them used to live in
 * {@see AbstractPage} alone, because a page was the only thing that
 * could own an action. An agent that owns one now answers through this same node
 * instead of growing its own pair of senders, so the wire contract has one author
 * (HIL-622).
 *
 * One instance per host: the pending success sentence is per host and per dispatch, and
 * the dispatcher clears it before every handler runs.
 */
final class ActionReply
{
    /**
     * Backend-authored success sentence set during the current dispatch, consumed by the
     * tracked success ack that immediately follows it. Null unless the handler set one.
     */
    private ?string $pendingSuccessMessage = null;

    /**
     * Client-minted request id of the dispatch running right now, or null when the caller
     * did not track this action. The handler may read it to hand the answer to somebody
     * else ({@see defer()}); nothing else in a handler has any business knowing it.
     */
    private ?string $requestId = null;

    /**
     * Whether the running handler handed the answer to another process.
     *
     * False for all but a handful of actions. The dispatcher answers a tracked action the
     * moment the handler returns, which is right while the handler is the last step - and
     * wrong when what it started finishes elsewhere. A sign-in is the case that needed it:
     * the library proves the credential and the session holder raises the session, so the
     * "you are in" the browser gets has to leave AFTER the identity it announces, not
     * before it (HIL-622).
     */
    private bool $deferred = false;

    /**
     * @param ActionHostInterface $host Owner of the action, whose signal source every frame goes out from
     */
    public function __construct(private readonly ActionHostInterface $host)
    {
    }

    /**
     * Holds the success sentence the current handler wants the client to see.
     *
     * @param string $message Backend-authored, already-localized success sentence
     */
    public function setSuccessMessage(string $message): void
    {
        $this->pendingSuccessMessage = $message;
    }

    /**
     * Empties the per-dispatch slots and records whose request is running.
     *
     * The success slot is otherwise cleared only by an ack, and an untracked action sends
     * none - so a sentence set by one would survive and surface on the next action's ack.
     * The deferral is cleared for the same reason and matters more: a stale one would
     * silence the ack of an unrelated action.
     *
     * @param ?string $requestId Client-minted request id of this dispatch, or null when untracked
     */
    public function beginDispatch(?string $requestId = null): void
    {
        $this->pendingSuccessMessage = null;
        $this->requestId = $requestId;
        $this->deferred = false;
    }

    /**
     * Empties the per-dispatch slots now that the dispatch is over.
     *
     * The slots are filled by {@see beginDispatch()} and were once emptied only by the next
     * one, which left them readable between dispatches - and a frame built there, as the
     * ending of an OAuth login is, quoted the request id of an action already answered. What
     * kept that from becoming a misdirected ack was the action name beside it happening to
     * be null; an invariant should not rest on a second field's luck (HIL-622).
     */
    public function endDispatch(): void
    {
        $this->pendingSuccessMessage = null;
        $this->requestId = null;
        $this->deferred = false;
    }

    /**
     * Returns the request id the running handler must quote to have its action answered.
     *
     * @return ?string Request id of the running dispatch, or null when the caller did not track it
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Declares that the answer to the running action is owed by somebody else.
     *
     * The handler that says this has passed the request id on and is no longer the last
     * step of its own action; the dispatcher then sends nothing, and the process holding
     * the request id answers when its part is done.
     */
    public function defer(): void
    {
        $this->deferred = true;
    }

    /**
     * @return bool Whether the running handler handed the answer to another process
     */
    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    /**
     * Sends the success ack of a tracked action, carrying its reply and success sentence.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param ?ActionReplyDTO $reply Domain reply the handler returned, or null when it answered with nothing
     * @throws InvalidArgumentException When the action-success signal cannot be named
     */
    public function sendSuccess(
        string $acceptKey,
        string $action,
        string $requestId,
        ?ActionReplyDTO $reply,
    ): void {
        $message = $this->pendingSuccessMessage;
        $this->pendingSuccessMessage = null;
        $this->send(
            SignalConstants::ACTION_SUCCESS,
            $acceptKey,
            new PageActionSuccessSignalData($action, $requestId, $message, $reply?->toArray()),
        );
    }

    /**
     * Sends the failure ack of a tracked action, superseding the untracked error hook.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that failed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param string $reason Human-readable message exposed to the client
     * @param ?string $errorCode Machine-readable error code, or null when unclassified
     * @param ?int $retryAfter Seconds the caller should wait before retrying, or null
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function sendFail(
        string $acceptKey,
        string $action,
        string $requestId,
        string $reason,
        ?string $errorCode = null,
        ?int $retryAfter = null,
    ): void {
        // A failed action carries no success text; drop any the handler set before throwing.
        $this->pendingSuccessMessage = null;
        $this->send(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData($action, $reason, $requestId, $errorCode, $retryAfter),
        );
    }

    /**
     * Sends the error frame of an untracked action, which has no request id to correlate.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that failed
     * @param Throwable $e Failure exposed to the client
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function sendException(string $acceptKey, string $action, Throwable $e): void
    {
        $this->send(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData(
                $action,
                $e->getMessage(),
                errorCode: $e instanceof ActionUnauthorizedException ? $e->errorCode : null,
            ),
        );
    }

    /**
     * Queues one frame to the connection that sent the action.
     *
     * @param string $signalName Signal name
     * @param string $acceptKey Target connection accept key
     * @param SignalDataInterface $data Frame payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    private function send(string $signalName, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->host->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }
}

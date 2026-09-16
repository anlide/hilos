<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;

/**
 * The gatekeeper side of the handover: forward the ask, and answer the client once the writer has
 * (HIL-1001).
 *
 * Used by an {@see AbstractPage} that checks who may ask and hands the write to the agent owning
 * the row, the form docs/agents/architecture/entity-libraries.md fixes. Both halves used to be
 * copied into every such page; what differed between the copies was only the name of the answer
 * and where the action name came from, and both of those now travel in the ask.
 *
 * A trait rather than a method on {@see AbstractHilosPage}: a method there would put the knowledge
 * of this form into the base class of every admin page, including those that forward nothing.
 *
 * The tracked half is the same everywhere and is written once here. The untracked half is not: a
 * submit with no request id has nothing to correlate, so its outcome rides whatever uncorrelated
 * frame the browser of that surface already listens for, and a surface with project frames of its
 * own overrides {@see answerUntracked()} rather than changing what its browser receives.
 */
trait HandoverGatekeeperTrait
{
    /**
     * Sends an untracked submit's outcome, which has no request id to be answered on.
     *
     * By default a refusal rides the uncorrelated action-error frame and a success says nothing,
     * because the change itself returns over the live table. Never a detail: the detail is read in
     * the modal the action was sent from, and an untracked action has no correlation to find its
     * way back to one.
     *
     * @param string $acceptKey Accept key of the connection that asked
     * @param string $action Browser action name the outcome belongs to
     * @param ?string $error Why the write was refused, or null when it went through
     * @throws InvalidArgumentException When the frame cannot be named
     */
    protected function answerUntracked(string $acceptKey, string $action, ?string $error): void
    {
        if ($error === null) {
            return;
        }

        $this->sendToUser(SignalConstants::ACTION_ERROR, $acceptKey, new PageActionErrorSignalData($action, $error));
    }

    /**
     * Hands one ask to the writer that owns the row and stops owing the caller an answer.
     *
     * Deferred only for a tracked submit: an untracked one owes no ack, and deferring it would
     * leave the dispatcher silent for a caller that never correlates.
     *
     * @param string $name Agent-signal name the ask travels under
     * @param HandoverAskInterface $ask The ask, carrying whom to answer and what to write
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function forward(string $name, HandoverAskInterface $ask): void
    {
        $this->sendToAgent($name, $ask);

        if ($this->currentActionRequestId() !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Turns the writer's answer into the ack the submit is waiting on.
     *
     * The sentence is set immediately before the success, because that is the slot the success
     * reads, and handling a signal is not an action dispatch that would have filled it. A refusal
     * carries the class and text of a failure the reason stands in for only when this page is an
     * admin one - the same gate a failure raised inside the page's own handler passes.
     *
     * @param HandoverAnswerSignalData $done Whom to answer, on which action, and why it was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerHandover(HandoverAnswerSignalData $done): void
    {
        if ($done->requestId === null) {
            $this->answerUntracked($done->acceptKey, $done->action, $done->error);

            return;
        }

        if ($done->error === null) {
            if ($done->successMessage !== null) {
                $this->setActionSuccessMessage($done->successMessage);
            }
            $this->sendActionSuccess($done->acceptKey, $done->action, $done->requestId);

            return;
        }

        $detailAllowed = $this->detailAllowed();
        $this->sendActionFail(
            $done->acceptKey,
            $done->action,
            $done->requestId,
            $done->error,
            errorType: $detailAllowed ? $done->errorType : null,
            errorDetail: $detailAllowed ? $done->errorDetail : null,
        );
    }

    /**
     * Tells whether this gatekeeper proved its caller to be an administrator.
     *
     * Read off the page's own level rather than off a list of today's gatekeepers, so a page of
     * any other level sends the refusal byte for byte as it did before the detail existed.
     *
     * @return bool Whether a refusal may carry the failure's class and text
     */
    private function detailAllowed(): bool
    {
        return static::ACCESS_LEVEL === PageAccessLevel::ADMIN;
    }
}

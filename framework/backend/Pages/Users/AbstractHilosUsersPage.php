<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\ImpersonateDoneSignalData;
use Hilos\Auth\Session\DTO\ImpersonateRequestSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;

/**
 * Base class for the framework Hilos users-list page.
 *
 * Subscribe behavior is supplied by project browser configs or page overrides.
 * The base page key is shared with the browser context so projects can return
 * page-shaped users snapshots through the default subscription handler.
 *
 * It also owns the one row action the framework offers on this list: taking a person over.
 * The name is declared HERE, on the base class, which is what makes the control a framework
 * one - every project mounting this page gets it without a line of its own. The lock comes
 * with the declaration and is not written anywhere below: the ADMIN level inherited from
 * {@see AbstractHilosPage} is enforced before the handler runs, and an agent action has no
 * such level, which is the whole reason the name moved here from
 * {@see AbstractSessionsLibraryAgent} in HIL-824.
 *
 * What it does NOT own is the session it rebinds. That is the library's, so this page is the
 * gatekeeper and the library is the writer: the page checks who is asking and forwards, the
 * library judges the takeover and performs it, and the answer comes back here to be acked -
 * the admin's submit is answered by the surface it was submitted to.
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_IMPERSONATE_START => ImpersonateStartActionDTO::class,
    ];

    /**
     * The library's answer to the takeover this page forwarded (HIL-824).
     *
     * Declaring it here is what brings the answer back to the surface that asked: a page-owned
     * signal is routed to the agent serving this page, which hands it to this handler. The
     * page holds the action because the ADMIN level closing it lives on a page and nowhere
     * else, so the ack has to leave from the page too.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_IMPERSONATE_DONE => ImpersonateDoneSignalData::class,
        ],
    ];

    /**
     * Routes the impersonation-start action to its typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws InvalidArgumentException When the takeover cannot be handed to the library
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_IMPERSONATE_START:
                if (!$dto instanceof ImpersonateStartActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStartActionDTO::class, $dto);
                }
                $this->handleImpersonateStart($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose takeover the library has finished (HIL-824).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_IMPERSONATE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof ImpersonateDoneSignalData) {
            throw new LogicException($name . ' payload must be ' . ImpersonateDoneSignalData::class);
        }

        $this->answerImpersonate($data->data);
    }

    /**
     * Hands one takeover to the owner of the session and stops owing the caller an answer.
     *
     * The admin half of a two-step action: what this page is, is the door - it carries the
     * ADMIN level that decides who may ask at all, and an agent action carries no such level,
     * which is why the name is here. What it is not, is the writer: the session belongs to
     * {@see AbstractSessionsLibraryAgent}, and a page runs in whichever worker serves the
     * connection, so a page rebinding it would be a write with no claim behind it.
     *
     * Nothing is judged here on the way out, the admin's own session included: it would be
     * read in this worker and acted on in another, and it is free to change in between. The
     * library runs the whole guard order where it writes, and says so on the way back.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param ImpersonateStartActionDTO $dto Impersonation-start action payload
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     */
    private function handleImpersonateStart(string $acceptKey, ImpersonateStartActionDTO $dto): void
    {
        $requestId = $this->currentActionRequestId();
        $this->agent->sendToAgent(
            HilosSignalConstants::HILOS_IMPERSONATE_REQUEST,
            new ImpersonateRequestSignalData($dto->targetUserId, $acceptKey, $requestId),
        );

        if ($requestId !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Turns the library's outcome into the ack the admin's submit is waiting on.
     *
     * Two shapes for the same reason the dispatcher has two: a tracked submit is correlated by
     * its request id and is answered on it, and an untracked one has nothing to correlate, so
     * its refusal rides the same uncorrelated action-error frame the page's exception hook
     * used to send. A tracked success needs no sentence of its own - the takeover arrives as
     * the rebound session on the handshake the library publishes, which is what the person
     * sees change.
     *
     * @param ImpersonateDoneSignalData $done Whom to answer, and why the takeover was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerImpersonate(ImpersonateDoneSignalData $done): void
    {
        if ($done->requestId !== null) {
            if ($done->error === null) {
                $this->sendActionSuccess(
                    $done->acceptKey,
                    HilosSignalConstants::HILOS_IMPERSONATE_START,
                    $done->requestId,
                );

                return;
            }

            $this->sendActionFail(
                $done->acceptKey,
                HilosSignalConstants::HILOS_IMPERSONATE_START,
                $done->requestId,
                $done->error,
            );

            return;
        }

        if ($done->error === null) {
            return;
        }

        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $done->acceptKey,
            new PageActionErrorSignalData(
                HilosSignalConstants::HILOS_IMPERSONATE_START,
                $done->error,
            ),
        );
    }
}

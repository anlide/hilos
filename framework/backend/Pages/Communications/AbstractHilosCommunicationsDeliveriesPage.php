<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Notification\DTO\DeliveryRetryDoneSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;

/**
 * AbstractHilosCommunicationsDeliveriesPage - the admin channel delivery log (HIL-201).
 *
 * The read-only delivery journal ({@see HilosNotificationDeliveriesTable}
 * serves its windows) plus the single row action it owns: retry. A failed delivery
 * row can be re-queued — its status resets to pending with zero attempts and the
 * channel's deliver signal is sent again — so an admin can recover a delivery that
 * failed on a transient outage without asking the user to re-trigger the event. The
 * action is valid only for a failed row (pending is already queued; sent is done).
 *
 * The page is an admin surface: the ADMIN access level inherited from
 * AbstractHilosPage closes its subscription and the retry action, replacing the
 * former flagless AUTHENTICATED guard and AUTH_ACTIONS list with the stricter
 * inherited default. Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 *
 * The retry runs in two steps since HIL-771, and the split is between the two things the
 * action was doing at once: deciding WHO may ask, which is the page's ADMIN level and lives
 * nowhere else, and WRITING the journal row, which belongs to
 * {@see AbstractNotificationsLibraryAgent}. So the page checks the caller and forwards, the
 * library judges the row and resets it, and the answer comes back here to be acked - the
 * admin's submit is answered by the surface it was submitted to.
 */
abstract class AbstractHilosCommunicationsDeliveriesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES;

    public const array ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => HilosDeliveryRetryActionDTO::class,
    ];

    /**
     * The library's answer to the retry this page forwarded (HIL-771).
     *
     * Declaring it here is what brings the answer back to the surface that asked: a page-owned
     * signal is routed to the agent serving this page, which hands it to this handler. The
     * page kept the action because the ADMIN level closing it lives on a page and nowhere
     * else, so the ack has to leave from the page too.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE => DeliveryRetryDoneSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_DELIVERIES,
    ];

    /**
     * Routes the delivery retry action to its typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws InvalidArgumentException When the retry cannot be handed to the library
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY:
                if (!$dto instanceof HilosDeliveryRetryActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosDeliveryRetryActionDTO::class, $dto);
                }
                $this->handleRetry($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose retry the library has finished (HIL-771).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof DeliveryRetryDoneSignalData) {
            throw new LogicException($name . ' payload must be ' . DeliveryRetryDoneSignalData::class);
        }

        $this->answerRetry($data->data);
    }

    /**
     * Hands one retry to the owner of the delivery journal and stops owing the caller an answer.
     *
     * The admin half of a two-step action (HIL-771): what this page still is, is the door - it
     * carries the ADMIN level that decides who may ask at all, and an agent action carries no
     * such level, which is why the name stayed here. What it stopped being is the writer: the
     * journal row belongs to {@see AbstractNotificationsLibraryAgent}, and a page runs in
     * whichever worker serves the connection, so a page writing it was a write with no claim
     * behind it.
     *
     * Nothing is judged here on the way out, not even that the row exists: the answer would be
     * read in this worker and acted on in another, and the row is free to change in between.
     * The library judges it where it writes it, and says so on the way back.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param HilosDeliveryRetryActionDTO $dto Retry action payload
     * @throws InvalidArgumentException When the retry frame cannot be named or queued
     */
    private function handleRetry(string $acceptKey, HilosDeliveryRetryActionDTO $dto): void
    {
        $requestId = $this->currentActionRequestId();
        $this->agent->sendToAgent(
            HilosSignalConstants::HILOS_DELIVERY_RETRY,
            new DeliveryRetrySignalData($dto->deliveryId, $acceptKey, $requestId),
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
     * its refusal rides the same uncorrelated action-error frame the page's exception hook used
     * to send. A tracked success needs no sentence - the re-queued row returns over the
     * journal's next window, exactly as before the move.
     *
     * @param DeliveryRetryDoneSignalData $done Whom to answer, and why the retry was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerRetry(DeliveryRetryDoneSignalData $done): void
    {
        if ($done->requestId !== null) {
            if ($done->error === null) {
                $this->sendActionSuccess(
                    $done->acceptKey,
                    HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
                    $done->requestId,
                );

                return;
            }

            $this->sendActionFail(
                $done->acceptKey,
                HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
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
                HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
                $done->error,
            ),
        );
    }
}

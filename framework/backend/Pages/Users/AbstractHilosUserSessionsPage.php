<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\UserSessionsEndRequestSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;
use Hilos\Pages\Users\DTO\HilosUserSessionEndActionDTO;
use Hilos\Pages\Users\DTO\HilosUserSessionsEndAllActionDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;

/**
 * The Sessions tab of a person's card: the person's sessions, and the two buttons that end them (HIL-1662).
 *
 * The same card as {@see AbstractHilosUserPage} - the person, the head, the standing and its live
 * frame, the subscription parsed into {@see HilosUserPageSubscribeParams} - with the sessions list
 * ({@see HilosUserSessionsBrowserList}) as the one thing it must have bound: a project registering
 * this page without the list is refused at the start rather than drawn as an empty list.
 *
 * Its own actions are the two endings and nothing of the card's: renaming, merging, rights, blocking,
 * deletion and the takeover stay on the card's Overview, and this page does not answer them. Its ADMIN
 * level closes both endings, a viewer of the admin view mode included, and neither is reading. What it
 * does not do is write: the sessions belong to {@see AbstractSessionsLibraryAgent}, so each ending is
 * handed there under {@see HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST}, and the submit is
 * answered when the library answers - with the library's sentence counting what ended, or its refusal.
 * The library asks again who pressed, and their confirmation of
 * {@see StepUpOperationKey::END_USER_SESSIONS}; nothing about the administrator travels in the payload.
 */
abstract class AbstractHilosUserSessionsPage extends AbstractHilosUserPage
{
    // The base class takes the trait too, privately; its forward and its answer are this page's own copies.
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_USER_SESSIONS;

    /** @var list<string> The person this page is about, and the sessions the list reads */
    public const array READS_DB = [HilosDbContext::users, HilosDbContext::sessions];

    public const array REQUIRED_LISTS = [HilosUserSessionsBrowserList::class];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER_SESSIONS,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => AbstractHilosUsersTable::USERS_SOURCE,
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                    BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
                ],
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_SESSION_END => HilosUserSessionEndActionDTO::class,
        HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => HilosUserSessionsEndAllActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    /**
     * Hands an ending of one or every session of the person to the sessions library.
     *
     * Nothing is judged on the way out, not even that the session is the person's: the answer would
     * be read in this worker and acted on in another.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library owes the answer
     * @throws AgentUnknownActionException When the action is not one this page declares
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the request cannot be handed to the library
     * @throws InvalidFormatException When the person or the session id is not positive
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_USER_SESSION_END:
                if (!$dto instanceof HilosUserSessionEndActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserSessionEndActionDTO::class, $dto);
                }
                $this->forwardEnding($acceptKey, $action, $dto->userId, $dto->sessionId);

                break;

            case HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL:
                if (!$dto instanceof HilosUserSessionsEndAllActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserSessionsEndAllActionDTO::class, $dto);
                }
                $this->forwardEnding($acceptKey, $action, $dto->userId, null);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose ending the sessions library has finished.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the browser ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
                }

                $this->answerHandover($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Forwards one ending without judging it in the page worker.
     *
     * @param string $acceptKey Initiating administrator connection
     * @param string $action Browser action the answer is addressed to
     * @param int $userId Person whose sessions end
     * @param ?int $sessionId Session row to end, or null to end every session of the person
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the person or the session id is not positive
     */
    private function forwardEnding(string $acceptKey, string $action, int $userId, ?int $sessionId): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST,
            new UserSessionsEndRequestSignalData(
                userId: $userId,
                sessionId: $sessionId,
                replySignal: HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: $action,
                successMessage: null,
            ),
        );
    }
}

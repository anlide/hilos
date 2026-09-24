<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\HilosException;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;
use Hilos\Users\DTO\AccountMergeActionDTO;
use Hilos\Users\DTO\AccountMergeSignalData;

/**
 * Base class for the framework Hilos single-user page.
 *
 * The default subscription path answers the client, parses the `userId` route param, and then
 * calls {@see self::onHilosUserSubscribe()}.
 *
 * It also owns account merging the way the users list owns impersonation (HIL-824): this page's
 * ADMIN level decides who may ask, then the sessions library judges and writes the merge. The
 * answer returns here so the tracked submit is completed by the surface it was made on.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_USER;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_MERGE => AccountMergeActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    /**
     * Hands the merge action to its typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply, or null while the library owes the answer
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the merge cannot be handed to the library
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_USER_MERGE:
                if (!$dto instanceof AccountMergeActionDTO) {
                    throw new InvalidActionPayloadException($action, AccountMergeActionDTO::class, $dto);
                }
                $this->handleAccountMerge($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose merge the sessions library has finished.
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
        if ($name !== HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE) {
            throw new AgentUnknownSignalException($name);
        }
        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Parses route params and runs the typed hook, once the client has been answered.
     *
     * Final: subclasses customize subscribe behavior through
     * {@see self::onHilosUserSubscribe()}, not this method.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     * @throws HilosException Whatever else the typed hook raises
     */
    final protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->onHilosUserSubscribe(
            $acceptKey,
            HilosUserPageSubscribeParams::fromPageRouteParams($params),
        );
    }

    /**
     * Runs optional project-specific subscribe behavior after the page has been answered.
     *
     * Default intentionally does nothing.
     *
     * @param string $acceptKey WebSocket accept key
     * @param HilosUserPageSubscribeParams $params Parsed subscribe params (always has `userId > 0`)
     */
    protected function onHilosUserSubscribe(string $acceptKey, HilosUserPageSubscribeParams $params): void
    {
    }

    /**
     * Forwards one merge without judging it in the page worker.
     *
     * @param string $acceptKey WebSocket accept key of the requesting admin
     * @param AccountMergeActionDTO $dto Account pair and optional password choice
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     */
    private function handleAccountMerge(string $acceptKey, AccountMergeActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
            new AccountMergeSignalData(
                survivorUserId: $dto->survivorUserId,
                loserUserId: $dto->loserUserId,
                passwordFate: $dto->passwordFate?->value,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_MERGE,
                successMessage: null,
            ),
        );
    }
}

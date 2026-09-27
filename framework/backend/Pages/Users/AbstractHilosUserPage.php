<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\HilosException;
use Hilos\Pages\Users\DTO\HilosUserPageSubscribeParams;
use Hilos\Users\DTO\AccountAdminSetSignalData;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Users\DTO\AccountMergeActionDTO;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\DTO\HilosUserAdminSetActionDTO;
use Hilos\Users\DTO\HilosUserBlockSetActionDTO;
use Hilos\Users\DTO\HilosUserDeletionSetActionDTO;

/**
 * Base class for the framework Hilos single-user page.
 *
 * The default subscription path answers the client, parses the `userId` route param, and then
 * calls {@see self::onHilosUserSubscribe()}.
 *
 * Its ADMIN gate closes account merging, rights, blocking and scheduled deletion. The sessions
 * or users library judges and writes each change, then returns its outcome here to complete
 * the tracked submit on the surface that accepted it.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_USER;

    public const string ACCOUNT_DELETION_GRACE_DAYS = 'accountDeletionGraceDays';

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_USER_MERGE => AccountMergeActionDTO::class,
        HilosSignalConstants::HILOS_USER_ADMIN_SET => HilosUserAdminSetActionDTO::class,
        HilosSignalConstants::HILOS_USER_BLOCK_SET => HilosUserBlockSetActionDTO::class,
        HilosSignalConstants::HILOS_USER_DELETION_SET => HilosUserDeletionSetActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    /**
     * Hands account lifecycle actions to their owning libraries.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply, or null while the library owes the answer
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the request cannot be handed to the library
     * @throws InvalidFormatException When the lifecycle target id is not positive
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

            case HilosSignalConstants::HILOS_USER_ADMIN_SET:
                if (!$dto instanceof HilosUserAdminSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserAdminSetActionDTO::class, $dto);
                }
                $this->handleAdminSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_BLOCK_SET:
                if (!$dto instanceof HilosUserBlockSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserBlockSetActionDTO::class, $dto);
                }
                $this->handleBlockSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_USER_DELETION_SET:
                if (!$dto instanceof HilosUserDeletionSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosUserDeletionSetActionDTO::class, $dto);
                }
                $this->handleDeletionSet($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the admin whose request the owning library has finished.
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
            case HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE:
            case HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE:
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
     * Carries the deletion confirmation's grace period in the subscription's first answer.
     *
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route params (unused)
     * @return ?PagePayload Grace period snapshot, supplemented by the page's identity
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: [self::ACCOUNT_DELETION_GRACE_DAYS => AccountDeletionSettings::graceDays()]);
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

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserAdminSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleAdminSet(string $acceptKey, HilosUserAdminSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET,
            new AccountAdminSetSignalData(
                userId: $dto->userId,
                admin: $dto->admin,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_ADMIN_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserBlockSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleBlockSet(string $acceptKey, HilosUserBlockSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
            new AccountBlockSetSignalData(
                userId: $dto->userId,
                block: $dto->block,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_BLOCK_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * @param string $acceptKey Initiating administrator connection
     * @param HilosUserDeletionSetActionDTO $dto Target account and requested state
     * @throws InvalidArgumentException When the request frame cannot be named or queued
     * @throws InvalidFormatException When the target id is not positive
     */
    private function handleDeletionSet(string $acceptKey, HilosUserDeletionSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
            new AccountDeletionSetSignalData(
                userId: $dto->userId,
                scheduled: $dto->scheduled,
                replySignal: HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::HILOS_USER_DELETION_SET,
                successMessage: null,
            ),
        );
    }
}

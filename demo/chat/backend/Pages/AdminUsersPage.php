<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
use Hilos\Users\DTO\AdminRenameSignalData;
use Throwable;

/**
 * Admin users browser table page action handler.
 *
 * @property ChatAgent $agent Page-owning chat agent
 */
final class AdminUsersPage extends AbstractPage
{
    /** @var list<string> The people its actions act on, and the events they are counted over */
    public const array READS_DB = [ChatDbContext::users, ChatDbContext::events];

    public const string PAGE = PageConstants::ADMIN_USERS;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::USER_UPDATE => AdminUserUpdateActionDTO::class,
    ];

    /**
     * The library's answer to the rename this page forwarded (HIL-771).
     *
     * Declaring it here is what brings the answer back to the surface that asked: a page-owned
     * signal is routed to the agent serving this page, which hands it to this handler.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::USER_ADMIN_RENAME_DONE => AdminRenameDoneSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::ACCESS,
                BrowserGuardKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserGuardKey::FIELD => User::admin,
            ],
        ],
    ];

    /**
     * Routes admin user actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws TableActionException When the target user id is invalid
     * @throws InvalidArgumentException When the rename cannot be handed to the library
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::USER_UPDATE:
                if (!$dto instanceof AdminUserUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, AdminUserUpdateActionDTO::class, $dto);
                }
                $this->handleUserUpdate($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Sends admin users table action failures to the initiating client.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(ChatTableContext::adminUsers, $action, $e->getMessage()),
        );
    }

    /**
     * Answers the admin whose rename the library has finished (HIL-771).
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
        if ($name !== ChatSignalConstants::USER_ADMIN_RENAME_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof AdminRenameDoneSignalData) {
            throw new LogicException($name . ' payload must be ' . AdminRenameDoneSignalData::class);
        }

        $this->answerRename($data->data);
    }

    /**
     * Hands one rename to the library that owns the account, and stops owing the caller an answer.
     *
     * The admin half of a two-step action (HIL-771): the page keeps the submit, because the
     * admin guard that closes this surface is the page's, and hands the write to
     * {@see UsersLibraryAgent}, which owns the account row and the room's log line for it. The
     * id is still refused here, because an id that is not one is not a question for the owner.
     *
     * Who is asking is resolved HERE and sent along: this worker serves the admin's socket, so
     * it is the one that can read the session behind it - the library would be asking about a
     * connection somebody else holds.
     *
     * @param string $acceptKey Requesting WebSocket accept key
     * @param AdminUserUpdateActionDTO $dto Update action payload
     * @throws TableActionException When the user id is invalid
     * @throws InvalidArgumentException When the rename frame cannot be named or queued
     */
    private function handleUserUpdate(string $acceptKey, AdminUserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid user ID');
        }

        $requestId = $this->currentActionRequestId();
        $this->agent->sendToAgent(
            ChatSignalConstants::USER_ADMIN_RENAME,
            new AdminRenameSignalData(
                userId: $dto->id,
                name: $dto->name,
                acceptKey: $acceptKey,
                requestId: $requestId,
                adminUserId: Hilos::$rt->selfConnection?->userId,
            ),
        );

        if ($requestId !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Turns the library's outcome into the ack the admin's submit is waiting on.
     *
     * A tracked submit is correlated by its request id and answered on it; an untracked one has
     * nothing to correlate, so its refusal rides the same table-action error frame this page's
     * exception hook sends. Only the tracked one gets the success sentence: the untracked path
     * has no ack to carry it, and the renamed row returns over the live table either way.
     *
     * @param AdminRenameDoneSignalData $done Whom to answer, and why the rename was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerRename(AdminRenameDoneSignalData $done): void
    {
        if ($done->requestId !== null) {
            if ($done->error === null) {
                // Set right before the send: the slot is consumed by sendSuccess() on the spot,
                // because a deferred reply leaves after the action dispatch has already ended.
                $this->setActionSuccessMessage('User renamed.');
                $this->sendActionSuccess($done->acceptKey, ChatSignalConstants::USER_UPDATE, $done->requestId);

                return;
            }

            $this->sendActionFail(
                $done->acceptKey,
                ChatSignalConstants::USER_UPDATE,
                $done->requestId,
                $done->error,
            );

            return;
        }

        if ($done->error === null) {
            return;
        }

        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $done->acceptKey,
            new TableActionErrorSignalData(ChatTableContext::adminUsers, ChatSignalConstants::USER_UPDATE, $done->error),
        );
    }
}

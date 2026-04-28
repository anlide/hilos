<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;
use Throwable;

/**
 * AdminUsersPage - Admin users table page handler.
 *
 * Handles initial data load on subscribe and user_update actions.
 */
final class AdminUsersPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_USERS;

    /**
     * Narrows the page agent to the chat worker used for admin user table actions.
     *
     * @return ChatAgent Chat worker bound to this admin users page
     */
    protected function getChatAgent(): ChatAgent
    {
        assert($this->agent instanceof ChatAgent);

        return $this->agent;
    }

    /**
     * Sends the initial users table full snapshot to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription (unused for admin users page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::adminUsers => Hilos::$table->adminUsers->getFullSnapshot()],
                frontend: UserFrontendStateProjector::fullForUsers(Hilos::$db->users, includeConnectionStats: true),
            ),
        );
    }

    /**
     * Routes incoming actions to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On table mutation or signal failure
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
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
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(TableChatContext::adminUsers, $action, $e->getMessage()),
        );
    }

    /**
     * Updates an existing user and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param AdminUserUpdateActionDTO $dto Update action payload
     *
     * @throws TableActionException If user ID is invalid or user not found
     * @throws HilosException If update or broadcast fails
     */
    private function handleUserUpdate(string $acceptKey, AdminUserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid user ID');
        }

        if (!isset(Hilos::$db->users[$dto->id])) {
            throw new TableActionException("User #{$dto->id} not found");
        }

        $dbUser = Hilos::$db->users[$dto->id];
        $oldName = $dbUser->name;

        Hilos::$table->adminUsers[$dto->id]->actions->update($dto);
        $sourceEvent = new TableSourceEventDTO(
            sourceKey: DbChatContext::users,
            sourceRowKey: $dto->id,
            mutationType: TableMutationType::Update,
        );

        foreach ($this->buildTableMutationSignalsForSourceEvent(ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED, $sourceEvent) as $tableSignal) {
            $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $tableSignal);
        }
        $this->emitChangeDb(
            ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED,
            new EmitDbChangeSignalData(
                sourceEvent: $sourceEvent,
                excludeAcceptKey: $acceptKey,
                actorUserId: Hilos::$rt->connections[$acceptKey]?->userId,
            ),
        );

        $event = Hilos::$db->events->actions->addUserRenamedByAdmin(
            userId: $dto->id,
            oldName: $oldName,
            newName: $dto->name,
            adminUserId: Hilos::$rt->connections[$acceptKey]?->userId,
        );
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
            ), frontend: UserFrontendStateProjector::updatesForUser($dbUser, includePublicUser: true)),
        );
    }

}

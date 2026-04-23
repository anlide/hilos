<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\DTO\TableRefreshActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingsTableResultDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;
use Throwable;
use Hilos\Core\Page\PageRouteParams;

/**
 * AdminUsersPage - Admin users table page handler.
 *
 * Handles initial data load on subscribe, user_update actions,
 * and the universal table_refresh action (routed here for all tables).
 */
final class AdminUsersPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_USERS;

    /**
     * Sends initial users table data to the user on page subscription.
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
                [TableChatContext::adminUsers => Hilos::$table->adminUsers->get()],
            ),
        );
    }

    /**
     * Routes incoming actions (user_update, table_refresh) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (AdminUserUpdateActionDTO|TableRefreshActionDTO)
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            switch ($action) {
                case ChatSignalConstants::USER_UPDATE:
                    if ($dto instanceof AdminUserUpdateActionDTO) {
                        $this->handleUserUpdate($acceptKey, $dto);
                    }

                    break;

                case ChatSignalConstants::TABLE_REFRESH:
                    if ($dto instanceof TableRefreshActionDTO) {
                        $this->handleTableRefresh($acceptKey, $dto);
                    }

                    break;

                default:
                    throw new TableActionException("Unknown action: {$action}");
            }
        } catch (Throwable $e) {
            $tableKey = $dto instanceof TableRefreshActionDTO ? ($dto->tableKey ?: TableChatContext::adminUsers) : TableChatContext::adminUsers;

            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData($tableKey, $action, $e->getMessage()),
            );
        }
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

        $mutation = Hilos::$table->adminUsers[$dto->id]->actions->update($dto);
        $signal = new TableMutationSignalData(TableChatContext::adminUsers, $mutation);
        $hilosUsersSignal = new TableMutationSignalData(
            TableChatContext::hilosUsers,
            new TableMutationEntry(
                TableMutationType::Updated,
                $dto->id,
                Hilos::$table->hilosUsers->makeRow($dbUser->toArray(toFrontend: true)),
            ),
        );

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $hilosUsersSignal, $acceptKey);

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
                updates: [DbChatContext::users => [[User::id => $dto->id, User::name => $dto->name]]],
            )),
        );
    }

    /**
     * Refreshes table data by key and sends it to the requesting client.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param TableRefreshActionDTO $dto Refresh action payload (contains tableKey)
     *
     * @throws TableActionException If table key is empty or table not found
     */
    private function handleTableRefresh(string $acceptKey, TableRefreshActionDTO $dto): void
    {
        if ($dto->tableKey === '') {
            throw new TableActionException('Table key is required for refresh');
        }

        $tableDef = Hilos::$table?->get($dto->tableKey);
        if ($tableDef === null) {
            throw new TableActionException("Table '{$dto->tableKey}' not found");
        }

        $result = $tableDef->get();

        if ($dto->tableKey === TableChatContext::settings) {
            $catalogKeys = array_keys(SettingsCatalog::getCatalog());
            $result = new SettingsTableResultDTO($result, $catalogKeys);
        }

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_DATA,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [$dto->tableKey => $result],
            ),
        );
    }
}

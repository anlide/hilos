<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\DTO\TableRefreshActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;

/**
 * AdminUsersPage — Admin users table page handler.
 *
 * Handles initial data load on subscribe, user_update actions,
 * and the universal table_refresh action (routed here for all tables).
 */
class AdminUsersPage extends AbstractChatPage
{
    public function getPageName(): string
    {
        return PageConstants::ADMIN_USERS;
    }

    public function onSubscribe(string $acceptKey): void
    {
        $result = Hilos::$table->users->get();

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::users => $result],
            ),
        );
    }

    public function onUnsubscribe(string $acceptKey): void
    {
    }

    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            match (true) {
                $dto instanceof UserUpdateActionDTO => $this->handleUserUpdate($acceptKey, $dto),
                $dto instanceof TableRefreshActionDTO => $this->handleTableRefresh($acceptKey, $dto),
                default => throw new TableActionException("Unexpected action payload for users page"),
            };
        } catch (TableActionException $e) {
            $tableKey = $dto instanceof TableRefreshActionDTO ? ($dto->tableKey ?: TableChatContext::users) : TableChatContext::users;

            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData($tableKey, $action, $e->getMessage()),
            );
        }
    }

    /**
     * @throws TableActionException
     */
    private function handleUserUpdate(string $acceptKey, UserUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid user ID');
        }

        $objectCollection = Hilos::$db->users->getObjectCollection();
        if (!isset($objectCollection[(string) $dto->id])) {
            throw new TableActionException("User #{$dto->id} not found");
        }

        $mutation = Hilos::$table->users[$dto->id]->actions->update($dto);
        $signal = new TableMutationSignalData(TableChatContext::users, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * @throws TableActionException
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

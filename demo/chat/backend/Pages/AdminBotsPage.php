<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;

/**
 * AdminBotsPage — Admin bots table page handler.
 *
 * Handles initial data load on subscribe and bot create/update/delete actions.
 */
class AdminBotsPage extends AbstractChatPage
{
    /**
     * Returns the page identifier for routing.
     */
    public function getPageName(): string
    {
        return PageConstants::ADMIN_BOTS;
    }

    /**
     * Sends initial bots table data to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     */
    public function onSubscribe(string $acceptKey): void
    {
        $result = Hilos::$table->bots->get();

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::bots => $result],
            ),
        );
    }

    /**
     * Handles page unsubscription (no-op for bots page).
     *
     * @param string $acceptKey WebSocket accept key for the unsubscribing client
     */
    public function onUnsubscribe(string $acceptKey): void
    {
    }

    /**
     * Routes incoming bot actions (create/update/delete) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (BotCreateActionDTO|BotUpdateActionDTO|BotDeleteActionDTO)
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            match (true) {
                $dto instanceof BotCreateActionDTO => $this->handleCreate($acceptKey, $dto),
                $dto instanceof BotUpdateActionDTO => $this->handleUpdate($acceptKey, $dto),
                $dto instanceof BotDeleteActionDTO => $this->handleDelete($acceptKey, $dto),
                default => throw new TableActionException("Unexpected action payload for bots page"),
            };
        } catch (TableActionException $e) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::bots, $action, $e->getMessage()),
            );
        }
    }

    /**
     * Creates a new bot and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param BotCreateActionDTO $dto Create action payload
     */
    private function handleCreate(string $acceptKey, BotCreateActionDTO $dto): void
    {
        $mutation = Hilos::$table->bots->actions->create($dto);
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * Updates an existing bot and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param BotUpdateActionDTO $dto Update action payload
     *
     * @throws TableActionException If bot ID is invalid or bot not found
     */
    private function handleUpdate(string $acceptKey, BotUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid bot ID');
        }

        if (!isset(Hilos::$db->bots[$dto->id])) {
            throw new TableActionException("Bot #{$dto->id} not found");
        }

        $mutation = Hilos::$table->bots[$dto->id]->actions->update($dto);
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }

    /**
     * Deletes a bot and broadcasts the mutation to all clients.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param BotDeleteActionDTO $dto Delete action payload
     *
     * @throws TableActionException If bot ID is invalid or bot not found
     */
    private function handleDelete(string $acceptKey, BotDeleteActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid bot ID');
        }

        if (!isset(Hilos::$db->bots[$dto->id])) {
            throw new TableActionException("Bot #{$dto->id} not found");
        }

        $mutation = Hilos::$table->bots[$dto->id]->actions->delete();
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }
}

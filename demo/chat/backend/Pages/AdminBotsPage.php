<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
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
use Hilos\HilosException;
use Throwable;
use Hilos\Core\Page\PageRouteParams;

/**
 * AdminBotsPage - Admin bots table page handler.
 *
 * Handles initial data load on subscribe and bot create/update/delete actions.
 */
final class AdminBotsPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_BOTS;

    /**
     * Sends initial bots table data to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription (unused for admin bots page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::bots => Hilos::$table->bots->get()],
            ),
        );
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
            switch ($action) {
                case ChatSignalConstants::BOT_CREATE:
                    if ($dto instanceof BotCreateActionDTO) {
                        $this->handleCreate($acceptKey, $dto);
                    }

                    break;

                case ChatSignalConstants::BOT_UPDATE:
                    if ($dto instanceof BotUpdateActionDTO) {
                        $this->handleUpdate($acceptKey, $dto);
                    }

                    break;

                case ChatSignalConstants::BOT_DELETE:
                    if ($dto instanceof BotDeleteActionDTO) {
                        $this->handleDelete($acceptKey, $dto);
                    }

                    break;

                default:
                    throw new TableActionException("Unknown action: {$action}");
            }
        } catch (Throwable $e) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::bots, $action, $e->getMessage()),
            );
        }
    }

    /**
     * Creates a new bot and broadcasts the mutation to all clients.
     * Starts BotAgent if bot is created with active flag.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param BotCreateActionDTO $dto Create action payload
     * @throws HilosException If bot creation fails due to validation or database errors
     */
    private function handleCreate(string $acceptKey, BotCreateActionDTO $dto): void
    {
        $mutation = Hilos::$table->bots->actions->create($dto);
        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);

        if ((Hilos::$db->bots[$mutation->rowId]->active ?? false) === true) {
            $this->getChatAgent()->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: (int) $mutation->rowId),
            );
        }
    }

    /**
     * Updates an existing bot and broadcasts the mutation to all clients.
     * On active change: start agent when active false->true; stop via data sync on stage 3 when true->false.
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

        $oldActive = Hilos::$db->bots[$dto->id]->active;
        $mutation = Hilos::$table->bots[$dto->id]->actions->update($dto);
        $newActive = ($mutation->row[ObjectBot::active] ?? $oldActive) === true;

        $signal = new TableMutationSignalData(TableChatContext::bots, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);

        if (!$oldActive && $newActive) {
            $this->getChatAgent()->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: $dto->id),
            );
        }
        // active true->false: BotAgent learns via DB_SYNC_UPDATED and calls selfStop()

        // Push updated bot to main chat store so bot names display correctly (for both active change and data-only edits)
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::BOT_UPDATED,
            new ChatEventSignalDTO(new EntitiesChangesDTO(updates: [
                DbChatContext::bots => [Hilos::$db->bots[$dto->id]->toArray(toFrontend: true)],
            ])),
        );
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

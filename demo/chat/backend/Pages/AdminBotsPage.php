<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;
use Throwable;

/**
 * AdminBotsPage - Admin bots table page handler.
 *
 * Handles initial data load on subscribe and bot create/update/delete actions.
 */
final class AdminBotsPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::ADMIN_BOTS;

    /**
     * Narrows the page agent to the chat worker used for admin bot table actions.
     *
     * @return ChatAgent Chat worker bound to this admin bots page
     */
    protected function getChatAgent(): ChatAgent
    {
        assert($this->agent instanceof ChatAgent);

        return $this->agent;
    }

    /**
     * Sends the initial bots table full snapshot to the user on page subscription.
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
                [TableChatContext::bots => Hilos::$table->bots->getFullSnapshot()],
                frontend: BotFrontendStateProjector::fullForBots(Hilos::$db->bots),
            ),
        );
    }

    /**
     * Routes incoming bot actions (create/update/delete) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (BotCreateActionDTO|BotUpdateActionDTO|BotDeleteActionDTO)
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On table mutation or signal failure
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::BOT_CREATE:
                if (!$dto instanceof BotCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, BotCreateActionDTO::class, $dto);
                }
                $this->handleCreate($acceptKey, $dto);

                break;

            case ChatSignalConstants::BOT_UPDATE:
                if (!$dto instanceof BotUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, BotUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($acceptKey, $dto);

                break;

            case ChatSignalConstants::BOT_DELETE:
                if (!$dto instanceof BotDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, BotDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Sends bot table action failures to the initiating client.
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
            new TableActionErrorSignalData(TableChatContext::bots, $action, $e->getMessage()),
        );
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

        if ((Hilos::$db->bots[$mutation->rowKey]->active ?? false) === true) {
            $this->getChatAgent()->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: (int) $mutation->rowKey),
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
        Hilos::$table->bots[$dto->id]->actions->update($dto);
        $newActive = Hilos::$db->bots[$dto->id]->active === true;

        if (!$oldActive && $newActive) {
            $this->getChatAgent()->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: $dto->id),
            );
        }
        // active true->false: BotAgent learns via DB_SYNC_UPDATED and calls selfStop()
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

        Hilos::$table->bots[$dto->id]->actions->delete();
    }
}

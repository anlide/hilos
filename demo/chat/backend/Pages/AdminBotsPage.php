<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;
use Throwable;

/**
 * Handles admin bot table actions for the chat demo.
 *
 * @property LibraryAgent $agent
 */
final class AdminBotsPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_BOTS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
        BrowserConfigKey::TABLES => [
            ChatTableContext::bots => [],
        ],
    ];

    /**
     * Routes bot create, update, and delete actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When a routed table mutation or agent signal fails
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
        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(ChatTableContext::bots, $action, $e->getMessage()),
        );
    }

    /**
     * Creates a bot through the table action and starts BotAgent when active.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param BotCreateActionDTO $dto Create action payload
     * @throws HilosException When bot validation, persistence, or agent startup fails
     */
    private function handleCreate(string $acceptKey, BotCreateActionDTO $dto): void
    {
        $mutation = Hilos::$table->bots->actions->create($dto);

        if ((Hilos::$db->bots[$mutation->rowKey]->active ?? false) === true) {
            $this->agent->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: (int) $mutation->rowKey),
            );
        }
    }

    /**
     * Updates a bot and starts BotAgent when an inactive bot becomes active.
     *
     * Active true-to-false changes are handled by BotAgent through data sync.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param BotUpdateActionDTO $dto Update action payload
     * @throws TableActionException When bot id is invalid or the bot is missing
     * @throws HilosException When bot persistence or agent startup fails
     */
    private function handleUpdate(string $acceptKey, BotUpdateActionDTO $dto): void
    {
        if ($dto->id <= 0) {
            throw new TableActionException('Invalid bot ID');
        }

        $dbBot = Hilos::$db->bots[$dto->id]
            ?? throw new TableActionException("Bot #{$dto->id} not found");
        $oldActive = $dbBot->active;
        Hilos::$table->bots[$dto->id]->actions->update($dto);
        $newActive = Hilos::$db->bots[$dto->id]->active === true;

        if (!$oldActive && $newActive) {
            $this->agent->sendToAgent(
                ChatSignalConstants::BOT_AGENT_START,
                new BotAgentSignalData(botId: $dto->id),
            );
        }
        // active true->false: BotAgent learns via DB_SYNC_UPDATED and calls selfStop()
    }

    /**
     * Deletes a bot through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param BotDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When bot id is invalid or the bot is missing
     * @throws HilosException When bot persistence fails
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

<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\HilosException;

/**
 * Handles admin bot table actions for the chat demo.
 *
 * @property LibraryAgent $agent
 */
final class AdminBotsPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_BOTS;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the library agent, which owns bots and prompt pieces but only MIRRORS
     * users and connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::LIBRARY;

    public const array ACTIONS = [
        ChatSignalConstants::BOT_CREATE => BotCreateActionDTO::class,
        ChatSignalConstants::BOT_UPDATE => BotUpdateActionDTO::class,
        ChatSignalConstants::BOT_DELETE => BotDeleteActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
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
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
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

        return null;
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

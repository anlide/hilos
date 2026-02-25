<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Agent\ChatAgentManager;
use Demo\Chat\Core\Page\ChatPageFactory;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
class ChatWorkerManager extends WorkerManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    protected function createAgentManager(SignalRouter $signalRouter): AgentManager
    {
        return new ChatAgentManager($signalRouter);
    }

    protected function onTick(): void
    {
    }

    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        if (!($agent instanceof ChatAgent)) {
            throw new PageSignalRouterNotFoundException($agent::class);
        }

        $pageFactory = new ChatPageFactory($this->signalRouter, $agent);
        $actionRoutes = new ActionRouteConfig([
            ChatSignalConstants::MESSAGE => PageConstants::MAIN,
            ChatSignalConstants::RENAME => PageConstants::PROFILE,
            ChatSignalConstants::FILE => PageConstants::MAIN,

            ChatSignalConstants::TABLE_REFRESH => PageConstants::ADMIN_USERS,
            ChatSignalConstants::USER_UPDATE => PageConstants::ADMIN_USERS,

            ChatSignalConstants::BOT_CREATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_UPDATE => PageConstants::ADMIN_BOTS,
            ChatSignalConstants::BOT_DELETE => PageConstants::ADMIN_BOTS,

            ChatSignalConstants::MODERATOR_PIECE_CREATE => PageConstants::ADMIN_MODERATOR,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => PageConstants::ADMIN_MODERATOR,
            ChatSignalConstants::MODERATOR_PIECE_DELETE => PageConstants::ADMIN_MODERATOR,
        ]);

        return new PageSignalRouter($pageFactory, $actionRoutes);
    }
}

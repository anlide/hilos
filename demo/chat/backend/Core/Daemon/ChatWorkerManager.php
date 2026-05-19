<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Agent\ChatAgentManager;
use Demo\Chat\Core\Page\ChatPageFactory;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatWorkerManager - Worker manager for chat demo.
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
final class ChatWorkerManager extends WorkerManager
{
    /**
     * Create chat-specific signal router.
     *
     * @return SignalRouter Chat signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create chat-specific agent manager.
     *
     * @return AgentManager Chat agent manager instance
     */
    protected function createAgentManager(): AgentManager
    {
        return new ChatAgentManager();
    }

    /**
     * Create page signal router for the given agent.
     *
     * @param AgentInterface $agent Agent to create router for
     * @return PageSignalRouter Page signal router with action routes
     * @throws PageSignalRouterNotFoundException If agent type is not supported
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        if (!$agent instanceof PageAgentInterface) {
            throw new PageSignalRouterNotFoundException($agent::class);
        }

        $pageFactory = new ChatPageFactory($agent);
        $actionRoutes = new ActionRouteConfig(Hilos::getPageActionRoutes());

        $signalRoutes = [
            SignalTypeConstants::FRAME_BINARY => PageConstants::MAIN,
            SignalTypeConstants::AGENT_SIGNAL => [
                ChatSignalConstants::MODERATION_RESULT => PageConstants::MAIN,
                ChatSignalConstants::RENAME_MODERATION_RESULT => PageConstants::PROFILE,
            ],
        ];

        return new PageSignalRouter($pageFactory, $actionRoutes, $signalRoutes);
    }
}

<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Agents\ChatAgent;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Agent\ChatAgentManager;
use Demo\WebSocketTest\Core\Page\ChatPageFactory;
use Demo\WebSocketTest\Core\Router\ChatSignalRouter;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Exception\Page\PageSignalRouterNotFoundException;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatWorkerManager - Worker manager for chat demo
 *
 * Extends base WorkerManager to provide chat-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
class ChatWorkerManager extends WorkerManager
{
    /**
     * Create signal router instance
     *
     * @return SignalRouter Signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create agent manager instance
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentManager Agent manager instance
     */
    protected function createAgentManager(SignalRouter $signalRouter): AgentManager
    {
        return new ChatAgentManager($signalRouter);
    }

    /**
     * Worker tick implementation
     *
     * Called regularly when connected to daemon.
     * Base class already ticks all agents, so this is for worker-specific work.
     */
    protected function onTick(): void
    {
        // Worker-specific tick logic (if any)
        // Agents are already ticked by base class
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
        ]);

        return new PageSignalRouter($pageFactory, $actionRoutes);
    }
}

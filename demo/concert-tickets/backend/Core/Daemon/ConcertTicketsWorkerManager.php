<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Core\Daemon;

use Demo\ConcertTickets\Core\Agent\ConcertTicketsAgentManager;
use Demo\ConcertTickets\Core\Router\ConcertTicketsSignalRouter;
use Demo\ConcertTickets\Hilos;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\SignalRouter;

/**
 * ConcertTicketsWorkerManager - Worker manager for the concert-tickets demo.
 *
 * Extends base WorkerManager to provide demo-specific agent creation.
 * All daemon connection and agent management is handled by base WorkerManager.
 */
final class ConcertTicketsWorkerManager extends WorkerManager
{
    /**
     * Create demo-specific signal router.
     *
     * @return SignalRouter Concert Tickets signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ConcertTicketsSignalRouter();
    }

    /**
     * Create demo-specific agent manager.
     *
     * @return AgentManager Concert Tickets agent manager instance
     */
    protected function createAgentManager(): AgentManager
    {
        return new ConcertTicketsAgentManager();
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

        $pageFactory = new HilosPageFactory($agent, Hilos::class);
        $actionRoutes = new ActionRouteConfig(Hilos::getPageActionRoutes());
        $signalRoutes = new SignalRouteConfig(Hilos::getPageSignalRoutes());

        return new PageSignalRouter($pageFactory, $actionRoutes, $signalRoutes, $agent::THROTTLE_VERDICT_SIGNAL);
    }
}

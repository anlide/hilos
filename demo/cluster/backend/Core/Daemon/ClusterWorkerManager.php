<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Daemon;

use Demo\Cluster\Core\Agent\ClusterAgentManager;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Hilos;
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
 * ClusterWorkerManager - Worker manager for the cluster demo (worker process side).
 *
 * The demo has no pages, so the page signal router is never exercised, but the
 * framework default throws, so it is implemented here to keep the worker whole.
 */
final class ClusterWorkerManager extends WorkerManager
{
    /**
     * Create demo-specific signal router.
     *
     * @return SignalRouter Cluster signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ClusterSignalRouter();
    }

    /**
     * Create demo-specific agent manager.
     *
     * @return AgentManager Cluster agent manager instance
     */
    protected function createAgentManager(): AgentManager
    {
        return new ClusterAgentManager();
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

        return new PageSignalRouter($pageFactory, $actionRoutes, $signalRoutes);
    }
}

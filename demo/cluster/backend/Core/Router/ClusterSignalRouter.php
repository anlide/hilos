<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Router;

use Demo\Cluster\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * ClusterSignalRouter - Signal router for the cluster demo.
 *
 * The demo is headless (no pages, no WebSocket) and its only agent is placed by
 * the leader, not started on the bootstrap signal, so the bootstrap agent list is
 * intentionally empty. Only the project-facade binding is overridden so topology
 * reads resolve against the demo's registries rather than the empty framework one.
 */
final class ClusterSignalRouter extends SignalRouter
{
    /**
     * Returns the cluster-demo project facade for topology registry reads.
     *
     * @return class-string<Hilos> Cluster-demo project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns no bootstrap agents: the demo's only agent is leader-placed, not booted.
     *
     * @return list<string> Empty agent-type list
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [];
    }
}

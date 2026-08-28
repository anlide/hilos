<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Router;

use Demo\Cluster\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * ClusterSignalRouter - Signal router for the cluster demo.
 *
 * The demo is headless (no pages, no WebSocket) and nothing it registers is started
 * from the bootstrap signal, so that list is intentionally empty: the fleet and the
 * claimer are placed by the leader, and the per-node probe comes up on its own node as
 * that node's workers become ready. Only the project-facade binding is overridden so
 * topology reads resolve against the demo's registries rather than the empty framework
 * one.
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
     * Returns no bootstrap agents: nothing this demo registers is booted from the signal.
     *
     * @return list<string> Empty agent-type list
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * ClusterWorkerServer - Worker server for the cluster demo.
 *
 * The base WorkerServer already implements the PlacementExecutor / AgentSignalSink
 * ports the cluster transport binds to, so a placed agent is launched through the
 * ordinary startAgent path. Nothing to add beyond the empty start hook.
 */
final class ClusterWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

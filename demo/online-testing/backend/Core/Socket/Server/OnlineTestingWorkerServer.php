<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * OnlineTestingWorkerServer - Worker server for the online-testing demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class OnlineTestingWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

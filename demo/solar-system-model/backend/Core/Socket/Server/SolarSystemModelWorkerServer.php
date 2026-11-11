<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * SolarSystemModelWorkerServer - Worker server for the Solar System Model demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class SolarSystemModelWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

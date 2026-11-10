<?php

declare(strict_types=1);

namespace Demo\Pmd\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * PmdWorkerServer - Worker server for the PMD demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class PmdWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

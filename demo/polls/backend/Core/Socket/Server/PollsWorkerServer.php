<?php

declare(strict_types=1);

namespace Demo\Polls\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * PollsWorkerServer - Worker server for the polls demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class PollsWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * PollWorkerServer - Worker server for the simple-poll demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class PollWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

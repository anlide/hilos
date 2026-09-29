<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Core\Socket\Server;

use Hilos\Socket\Server\WorkerServer;

/**
 * BinanceBtcTrackerWorkerServer - Worker server for the binance-btc-tracker demo.
 *
 * Extends base WorkerServer; the framework default already queues
 * INITIAL_AGENTS_START once minimum workers register, so no override needed.
 */
final class BinanceBtcTrackerWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }
}

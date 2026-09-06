<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Http\StatusHandler;
use Hilos\Socket\Server\CommandServer;

/**
 * DaemonStatusSource - master-side seam that reports this node's own runtime status.
 *
 * The sibling of {@see ProtectedModeSnapshotSource} in role and in wiring: uptime, memory,
 * CPU and the worker counts live in the master process and nowhere else, so both doors that
 * publish them - the {@see StatusHandler} behind GET /status and the `daemon:status` branch
 * of the command channel - ask the master for the same snapshot. The {@see DaemonManager}
 * implements this; the HTTP handler is handed it at registration and the
 * {@see CommandServer} is wired with it in {@see DaemonManager::registerServer()}, so
 * neither depends on the concrete manager.
 *
 * One source rather than one per door on purpose: a {@see DaemonStatus} of its own would give
 * each door its own uptime anchor and its own CPU delta, and two answers about one daemon
 * would disagree.
 */
interface DaemonStatusSource
{
    /**
     * Samples this node's runtime status.
     *
     * Reads in-memory counters plus /proc/stat only - no database and no socket I/O - because
     * it runs on the master's connection-accept path, where a blocking call stalls every client.
     *
     * @return DaemonStatusDTO Fresh status sample of this daemon
     */
    public function daemonStatusSnapshot(): DaemonStatusDTO;
}

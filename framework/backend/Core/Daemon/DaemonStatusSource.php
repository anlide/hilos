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
 * The sibling of {@see ProtectedModeSnapshotSource} in role and in wiring: uptime, the last
 * process measurement and worker counts live in the master process, so both doors that
 * publish them - the {@see StatusHandler} behind GET /status and the `daemon:status` branch
 * of the command channel - ask the master for the same snapshot. The {@see DaemonManager}
 * implements this; the HTTP handler is handed it at registration and the
 * {@see CommandServer} is wired with it in {@see DaemonManager::registerServer()}, so
 * neither depends on the concrete manager.
 *
 * One source rather than one per door on purpose: a {@see DaemonStatus} of its own would give
 * each door its own uptime anchor and its own measurement, and two answers about one daemon
 * would disagree.
 */
interface DaemonStatusSource
{
    /**
     * Reports this node's runtime status.
     *
     * Reads the master's last completed measurement and current worker counts without sampling.
     * This runs on the master's connection-accept path, where blocking stalls every client.
     *
     * @return DaemonStatusDTO Last measurement with current uptime and worker counts
     */
    public function daemonStatusSnapshot(): DaemonStatusDTO;
}

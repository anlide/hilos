<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;

/**
 * LiveConnectionRoster - master-side seam that names live WebSocket connections across the cluster.
 *
 * Local sockets live in the master process and remote keys are mirrored in the cluster
 * connection index, so the daemon combines both. The {@see DaemonManager} implements this and is
 * wired into the {@see WorkerServer} at registration, the same way {@see ConnectionDropper}
 * is wired into the {@see WebSocketServer}, so the server that starts agents does not depend
 * on the concrete manager.
 *
 * One caller, and it is the reason the seam exists (HIL-664): every agent start carries the
 * roster to its worker, which strikes out the connection rows whose socket is gone. While an
 * agent is stopped nobody may write its runtime collection, so a tab closed in that window
 * leaves a row behind - the roster is what the worker compares against to find those rows,
 * and it makes the freeze, a crashed agent's restart, and a first start one behavior.
 */
interface LiveConnectionRoster
{
    /**
     * Names local socket keys and keys the cluster index attributes to other nodes.
     *
     * A connection that has not finished its handshake carries no accept key yet and is not
     * named: it has no runtime row either, so there is nothing about it to reconcile.
     *
     * @return list<string> Accept keys known live at the moment of the call
     */
    public function liveAcceptKeys(): array;
}

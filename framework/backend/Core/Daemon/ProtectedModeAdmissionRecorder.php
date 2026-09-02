<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;

/**
 * ProtectedModeAdmissionRecorder - master-side seam that records a verifier as let in.
 *
 * The mirror of {@see ConnectionDropper} in role and in wiring. A connection that presented a
 * valid pass on its upgrade request has to be written onto the freeze row, because that row is
 * what the worker reads over RT sync: page_subscribe reaches the same verdict as the master
 * without asking the master anything. The connection code cannot write that row itself - it runs
 * on the master's accept path, where the runtime is the daemon's to write - so it asks through
 * here, and the {@see DaemonManager} implements it and is wired into the {@see WebSocketServer}
 * at registration.
 *
 * Unregistered the whole admission is inert: a project that mounts no runtime row has no freeze
 * to be let into, and a null seam simply leaves the connection on the stub.
 */
interface ProtectedModeAdmissionRecorder
{
    /**
     * Records the browser session behind this connection as admitted for the verification in flight.
     *
     * Writes {@see ProtectedModeRuntime::$admittedSessionTokenHashes} through the daemon's runtime
     * actions, which is an in-memory write plus the RT sync that carries it to this node's workers -
     * no database, no file and no socket I/O, because it runs on the master's connection-accept
     * path. A session already recorded is ignored, so a verifier that reconnects or opens a second
     * tab costs nothing.
     *
     * The caller has already checked the pass ({@see WebSocketClient}); this seam records a
     * decision, it does not make one.
     *
     * @param string $sessionTokenHash Hash of the session token of the admitted browser
     */
    public function admitProtectedModeSession(string $sessionTokenHash): void;
}

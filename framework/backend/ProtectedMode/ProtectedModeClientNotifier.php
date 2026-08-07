<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;

/**
 * Local port the daemon uses to tell every open browser connection on this node that protected
 * mode turned on or off.
 *
 * A connection opened after the freeze learns the state from the welcome frame; one that was
 * already open learns it only if somebody pushes, and that push is what this seam is. The
 * WebSocket server and the broadcast path live in {@see DaemonManager}, while
 * {@see DaemonProtectedModeExecutor} does not, so the executor asks through a port rather than
 * reaching for the server — and stays inert where the port was never registered, exactly as it
 * already does when no runtime row is mounted.
 */
interface ProtectedModeClientNotifier
{
    /**
     * Pushes the protected-mode state to every connected browser client on this node.
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @param ?string $excludeAcceptKey Accept key kept out of the broadcast (the initiator, which
     *                                  must keep seeing the real app), or null to tell everyone
     */
    public function notifyProtectedModeState(ProtectedModeStateSignalData $state, ?string $excludeAcceptKey): void;
}

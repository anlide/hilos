<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
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
     * The initiator is kept out by both halves of its identity, and the session half is the one
     * that matters to a person: the accept key spares the socket that asked, while every other tab
     * of the same browser is raised to a stub describing the operation its owner is running. Kept
     * as two arguments rather than one, because an initiator with no browser behind it - a CLI
     * trigger, a scheduled run - leaves the hash null and must go on being served as before.
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @param ?string $excludeAcceptKey Accept key kept out of the broadcast (the initiator, which
     *                                  must keep seeing the real app), or null to tell everyone
     * @param ?string $excludeSessionTokenHash Hash of the initiator browser's session token, kept out
     *                                         with all its tabs, or null when no browser asked
     * @throws InvalidArgumentException When the protected-mode signal cannot be named
     */
    public function notifyProtectedModeState(
        ProtectedModeStateSignalData $state,
        ?string $excludeAcceptKey,
        ?string $excludeSessionTokenHash,
    ): void;

    /**
     * Pushes the protected-mode state to every connection of one browser session on this node.
     *
     * The narrow half of the same seam, and the one the admission owes: a verifier types the code
     * in one tab while its other tabs stand on the stub, and nothing tears those sockets down, so
     * without a push they stay there for the whole window. The broadcast above cannot serve this -
     * it carries the row's verdict, and what these connections are owed is a verdict about
     * themselves.
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @param string $sessionTokenHash Hash of the session token whose connections receive the frame
     * @throws InvalidArgumentException When the protected-mode signal cannot be named
     */
    public function notifyProtectedModeSessionState(
        ProtectedModeStateSignalData $state,
        string $sessionTokenHash,
    ): void;
}

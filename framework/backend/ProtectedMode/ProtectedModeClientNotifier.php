<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Local port the daemon uses to tell the open browser connections on this node what protected
 * mode holds for them - everybody at once, one browser session, or whoever the freeze still holds.
 *
 * A connection opened after the freeze learns the state from the welcome frame; one that was
 * already open learns it only if somebody pushes, and that push is what this seam is. The
 * WebSocket server and the delivery paths live in {@see DaemonManager}, while
 * {@see DaemonProtectedModeExecutor} does not, so the executor asks through a port rather than
 * reaching for the server — and stays inert where the port was never registered, exactly as it
 * already does when no runtime row is mounted.
 */
interface ProtectedModeClientNotifier
{
    /**
     * Pushes the protected-mode state to every connected browser client on this node.
     *
     * A caller that keeps the initiator out names both halves of its identity, and the session half
     * is the one that matters to a person: the accept key spares the socket that asked, the hash
     * spares every other tab of the same browser. Kept as two arguments rather than one, because an
     * initiator with no browser behind it - a CLI trigger, a scheduled run - leaves the hash null.
     * Who is spared is the caller's to decide and changes by phase: entering the freeze and closing
     * back into it spare nobody, while the verification window spares the session it is about to
     * address on its own ({@see notifyProtectedModeSessionState()}).
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @param ?string $excludeAcceptKey Accept key kept out of the broadcast (the initiator, when the
     *                                  phase owes it a verdict of its own), or null to tell everyone
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

    /**
     * Pushes the protected-mode state to every connection on this node the freeze still locks out.
     *
     * The third address of the seam, for a verdict meant for the held alone: the first minted pass
     * turns the waiting sentence on the stub into the code field, and nobody the window has let in
     * is owed a word of it. It takes no exclusion, because whom the window has let in is the row's
     * to say, and the row says it with the very test the 101 composes the welcome with
     * ({@see ProtectedModeRuntime::locksOut()}). An exclusion would have to list the operator by
     * both halves of their identity, every session of the circle and every pass holder - and a
     * frame saying active that reaches any of them takes their page down under the stub (HIL-1082).
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @throws InvalidArgumentException When the protected-mode signal cannot be named
     */
    public function notifyProtectedModeLockedOutState(ProtectedModeStateSignalData $state): void;

    /**
     * Asks every open page of one browser session on this node to be answered again.
     *
     * The frames above move the stub; this moves what stands behind it, for every browser the
     * verification window lets in. A tab carried out of the stub lands on the page it already had,
     * holding the answer it was given before the phase moved - and a page that builds something
     * from the phase at subscribe (the backup page's reopen block) would show the old one until a
     * reload. The answer travels as an ordinary page answer, decided again by the code that
     * decides a subscribe (HIL-911).
     *
     * @param string $sessionTokenHash Hash of the session token whose open pages are re-judged
     * @throws InvalidArgumentException When the announcement cannot be named
     */
    public function reassessPagesOfSession(string $sessionTokenHash): void;
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;

/**
 * The browser a sign-in command is acting for, resolved once at the top of it (HIL-622).
 *
 * Three facts, and a command needs all three of them: the accept key to answer the socket
 * that asked, the session token because a hold, a wait and a grant all belong to the
 * browser rather than to the socket, and the signed-in user for the commands that add to
 * an account instead of opening one.
 *
 * It exists because the library is not the page the commands came off. A page had the
 * acting connection handed to it and could read the three off that row; an agent is given
 * an accept key and looks the row up ({@see AbstractLibraryCommands::acting()}), which is
 * one place to fail rather than one per command - and it fails BEFORE the command runs,
 * which is what keeps a half-done sign-in from being possible at all.
 *
 * Not a runtime row and deliberately not a reference to one: a command reads these three
 * and never writes them, and the writes that DO move a session belong to its holder.
 */
final class ActingSession
{
    /**
     * @param string $acceptKey WebSocket accept key of the socket that submitted
     * @param string $sessionToken Session cookie token of the browser behind it
     * @param ?int $userId Signed-in user of that session, or null while it is anonymous
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $sessionToken,
        public readonly ?int $userId,
    ) {
    }
}

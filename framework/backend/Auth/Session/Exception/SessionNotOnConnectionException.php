<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\Exception;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\HilosException;

/**
 * Thrown when a control of the sign-in surface is asked of a connection that holds no session
 * (HIL-730).
 *
 * Every one of those controls - sign out, dismiss an ack, take over a user, hand them back -
 * acts on the session of the ACTING connection, read off the runtime connection rows. When that
 * row carries no token there is nothing to act on, and the four branches used to return as if
 * they had acted. The browser was told the action succeeded and nothing changed: the tab still
 * showed the person signed in, the takeover it asked for never happened, and the only way to
 * find out was to reload.
 *
 * Deliberately NOT an {@see ActionUnauthorizedException}: {@see PageSignalRouter::failAction()}
 * passes that family's error code to the client, and the frontend auth gate opens the sign-in
 * window on a 401-level one. An empty token is a reconnect that has not finished, not a person
 * who is not signed in - answering it with a login form would replace one lie with another.
 * Without an error code the frame is an ordinary action failure, which is what this is.
 *
 * @see AbstractSessionsLibraryAgent::onAgentAction() Where the four controls refuse
 */
class SessionNotOnConnectionException extends HilosException
{
}

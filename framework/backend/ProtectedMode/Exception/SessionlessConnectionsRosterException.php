<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\Exception;

use Hilos\ProtectedMode\SessionStageStartupGuard;

/**
 * Exception: a browser connections roster carries no session token.
 *
 * Raised by {@see SessionStageStartupGuard::assertRosterCarriesSessions()} at the startup
 * of a node, before any server binds or peer sees it. The reader is the author of the
 * project's runtime context: the refusal names the mounted collection, the base it uses,
 * and the session-stage base it must use instead.
 */
final class SessionlessConnectionsRosterException extends ProtectedModeException
{
}

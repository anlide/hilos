<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Auth\Library\AbstractSessionsLibraryAgentDaemon;

/**
 * SessionsLibraryAgentDaemon - daemon proxy for the chat sessions library (HIL-710).
 *
 * Inherits the framework placement whole: a monopolistic singleton, because a cookie is
 * resolved into a session in one process or it is raced. Where that process runs the chat
 * leaves to the placement policy in its registry entry - the sessions are the hot half of
 * the sign-in surface and have no reason to sit on the leader.
 */
final class SessionsLibraryAgentDaemon extends AbstractSessionsLibraryAgentDaemon
{
}

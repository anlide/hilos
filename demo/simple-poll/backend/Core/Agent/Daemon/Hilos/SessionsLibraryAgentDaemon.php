<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Agent\Daemon\Hilos;

use Hilos\Auth\Library\AbstractSessionsLibraryAgentDaemon;

/**
 * SessionsLibraryAgentDaemon - daemon proxy for the poll sessions library (HIL-710).
 *
 * Inherits the framework placement whole: a monopolistic singleton, because a cookie is
 * resolved into a session in one process or it is raced. Where that process runs is left to
 * the placement policy in this demo's registry entry.
 */
final class SessionsLibraryAgentDaemon extends AbstractSessionsLibraryAgentDaemon
{
}

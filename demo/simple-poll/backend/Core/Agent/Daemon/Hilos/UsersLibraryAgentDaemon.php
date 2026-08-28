<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Agent\Daemon\Hilos;

use Hilos\Auth\Library\AbstractUsersLibraryAgentDaemon;

/**
 * UsersLibraryAgentDaemon - daemon proxy for the simple-poll users library (HIL-634).
 *
 * Inherits the framework placement whole: a cluster-leader-pinned monopolistic singleton,
 * because minting an account is a claim only one process may hold. This demo has nothing
 * to add to that - it hosts the library, it does not place it differently.
 */
final class UsersLibraryAgentDaemon extends AbstractUsersLibraryAgentDaemon
{
}

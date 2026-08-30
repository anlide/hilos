<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Auth\Library\AbstractUsersLibraryAgentDaemon;

/**
 * UsersLibraryAgentDaemon - daemon proxy for the chat users library (HIL-622).
 *
 * Inherits the framework placement whole: a cluster-wide monopolistic singleton, because
 * minting an account is a claim only one process may hold. Where that process runs the chat
 * leaves to the placement policy in its registry entry - it hosts the library, it does not
 * place it differently.
 */
final class UsersLibraryAgentDaemon extends AbstractUsersLibraryAgentDaemon
{
}

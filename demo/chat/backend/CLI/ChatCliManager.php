<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager - the place a project adds commands of its own, kept empty on purpose.
 *
 * Chat has no command of its own any more: the fifteen it used to carry are the framework's
 * since HIL-729, and `account:merge` was the last of them to go. What is left is the seam
 * itself - a project that needs a command of its own subclasses {@see CliManager} exactly
 * like this, overrides {@see CliManager::registerProjectCommands()} and calls
 * {@see CliManager::addCommand()} in it.
 *
 * The empty override is kept rather than deleted because the demos are read as a worked
 * example: the other three name {@see CliManager} directly in their `cli.php`, so this is the
 * only place showing what the other choice looks like. Deleting it would leave the seam
 * documented and never demonstrated.
 */
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
    }
}

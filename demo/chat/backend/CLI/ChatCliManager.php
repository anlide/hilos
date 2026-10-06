<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager - the place a project adds commands of its own, kept empty on purpose.
 *
 * Chat replaces only the test database reset so its paired journal database is
 * recreated alongside the primary database. The other commands remain the
 * framework's; this override demonstrates the project command seam.
 */
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new ChatChangeLogDbTestResetCommand());
    }
}

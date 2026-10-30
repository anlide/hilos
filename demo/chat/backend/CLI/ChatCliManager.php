<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Demo\Chat\CLI\Commands\SubmissionTestObserveCommand;
use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager - the project's own submission observation command.
 *
 * General commands, including the feature-aware paired test database reset, come from the framework.
 */
final class ChatCliManager extends CliManager
{
    /** Registers the read-only checkpoint answered by the Chat agent. */
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new SubmissionTestObserveCommand());
    }
}

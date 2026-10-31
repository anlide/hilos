<?php

declare(strict_types=1);

namespace Demo\Chat\CLI;

use Demo\Chat\CLI\Commands\BotTestAnswerNowCommand;
use Demo\Chat\CLI\Commands\SendPauseTestEndCommand;
use Demo\Chat\CLI\Commands\SubmissionTestObserveCommand;
use Hilos\Core\CLI\CliManager;

/**
 * Chat CLI manager - the project's own test-only commands.
 *
 * General commands, including the feature-aware paired test database reset, come from the framework.
 */
final class ChatCliManager extends CliManager
{
    /** Registers the test-only commands answered by the Chat agent and by the chat library. */
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new SubmissionTestObserveCommand());
        $this->addCommand(new SendPauseTestEndCommand());
        $this->addCommand(new BotTestAnswerNowCommand());
    }
}

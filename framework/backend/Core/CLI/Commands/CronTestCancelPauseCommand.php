<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/** CLI entrance for canceling one exact schedule pause. */
class CronTestCancelPauseCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::CRON_TEST_CANCEL_PAUSE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Cancel one cron schedule pause by its exact id (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Cancel only the pause named by its id. Other pauses on the same rule stay.
  Removing the final pause resumes ordinary schedule checks without running
  the rule now or catching up. Unknown or already canceled ids are refused.
  Refuses a production-like environment.

Exit codes:
  0  the pause was canceled
  1  the daemon did not answer or refused
  2  the pause id is missing or more than one argument was given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} <id>
HELP;
    }

    /**
     * Asks the owner to cancel exactly the named pause.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments: one pause id
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if (count($args) !== 1 || $args[0] === '') {
            echo "Usage: {$this->getName()} <id>\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        $pauseId = $args[0];

        try {
            $result = $this->sendCommand($this->getName(), [
                CommandConstants::FIELD_PAUSE_ID => $pauseId,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        echo "Canceled pause {$pauseId}\n";

        return ExitCode::SUCCESS;
    }
}

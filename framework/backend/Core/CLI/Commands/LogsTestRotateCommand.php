<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Log\LogCommandConstants;
use Hilos\Log\LogStoreAgent;

/**
 * LogsTestRotateCommand - rotate this node's live logs now, the way an axis does (test-only).
 *
 * Sends `test:logs:rotate` over the command socket channel; the daemon parks the connection and
 * routes it to {@see LogStoreAgent} of the node that took the command, the one owner of that
 * node's log directory. The agent makes the very rotation attempt a fired axis makes - the batch,
 * the cleanup of confirmed batches, the line in its journal - then walks the store and sends the
 * index at once, so a test brings that moment to now instead of waiting for a schedule, an age or
 * a size. No axis changes.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) on both sides,
 * and database-free: the files are the agent's to move, so the CLI process needs no connection of
 * its own.
 */
final class LogsTestRotateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:logs:rotate)
     */
    public function getName(): string
    {
        return CliCommands::LOGS_TEST_ROTATE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Rotate this node\'s live logs now through the live daemon, as a rotation axis does (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Asks the running daemon's log-store agent of this node to rotate the live logs
  now, exactly as a fired rotation axis does: the live files move into a new
  batch, confirmed batches are cleaned up, and the index is walked and sent at
  once. Refuses when the log store cannot be read or every rotation axis is off.
  The rotation axes do not change. Refuses on a production-like environment.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Sends the rotation command and prints how many live files it moved.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments, which must be empty
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if ($args !== []) {
            echo "Usage: {$this->getName()}\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), []);
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

        $moved = (int)$result->reply->payload[LogCommandConstants::FIELD_MOVED];
        echo "Rotated the live logs: moved {$moved} file(s)\n";

        return ExitCode::SUCCESS;
    }
}

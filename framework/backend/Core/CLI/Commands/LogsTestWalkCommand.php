<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Log\LogStoreAgent;

/**
 * LogsTestWalkCommand - walk this node's whole log store and send its index now (test-only).
 *
 * Sends `test:logs:walk` over the command socket channel; the daemon parks the connection and
 * routes it to {@see LogStoreAgent} of the node that took the command. The agent makes the full
 * walk out of turn and sends the index to the aggregator at once, even when nothing changed, so a
 * test brings the next walk and the next frame to now instead of waiting out the walk intervals
 * or the keepalive. The intervals themselves do not change.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) on both sides,
 * and database-free: the walk is the agent's, so the CLI process needs no connection of its own.
 */
final class LogsTestWalkCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:logs:walk)
     */
    public function getName(): string
    {
        return CliCommands::LOGS_TEST_WALK;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Walk this node\'s log store and send its index now through the live daemon (test-only)';
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
  Asks the running daemon's log-store agent of this node to walk the whole log
  store now - live files and archive - and to send its index at once, even when
  nothing changed. The walk intervals and the keepalive do not change.
  Refuses on a production-like environment.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Sends the walk command and prints that the index went out.
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

        echo "Walked the log store and sent its index\n";

        return ExitCode::SUCCESS;
    }
}

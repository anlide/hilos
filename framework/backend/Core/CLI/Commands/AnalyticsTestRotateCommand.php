<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Analytics\AnalyticsCommandConstants;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * AnalyticsTestRotateCommand - rotate this node's open analytics journal file now (test-only).
 *
 * Sends `test:analytics:rotate` over the command socket channel; the daemon parks the connection
 * and routes it to {@see AnalyticsJournalAgent} of the node that took the command, the one owner
 * of that node's journal. The agent rotates the open file and tells the writer, exactly as the
 * rotation by age does after {@see AnalyticsJournalDirectory::ROTATE_AGE_MS}, so a test brings
 * that moment to now instead of waiting it out; the period itself does not change.
 *
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}) on both sides,
 * and database-free: the file is the agent's to rotate, so the CLI process needs no connection of
 * its own.
 */
final class AnalyticsTestRotateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:analytics:rotate)
     */
    public function getName(): string
    {
        return CliCommands::ANALYTICS_TEST_ROTATE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Rotate this node\'s open analytics journal file now through the live daemon (test-only)';
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
  Asks the running daemon's analytics journal agent of this node to rotate its open
  file now: the file becomes ready and the writer is told, as when the file grows old.
  Without an open file nothing happens. The production rotation period does not change.
  Refuses on a production-like environment.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Sends the rotation command and prints whether a file was rotated.
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

        echo $result->reply->payload[AnalyticsCommandConstants::FIELD_ROTATED] === true
            ? "Rotated the open analytics journal file; the writer was told\n"
            : "No open analytics journal file to rotate\n";

        return ExitCode::SUCCESS;
    }
}

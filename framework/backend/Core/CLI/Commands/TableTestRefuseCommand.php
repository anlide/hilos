<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * TableTestRefuseCommand - refuse the windows of one table, as if they could not be built (HIL-1131).
 *
 * A browser table whose window the server cannot build shows the reader "List unavailable" while
 * the rest of its page keeps working, and a stand has no honest way to make a window fail. This
 * command names one table by its wire key; from then on every window of that table fails inside
 * the build's own trap, the way a real failure does, until the command is called again.
 *
 * A test-only command (extends {@see TestOnlyCommand}, so it refuses on a production-like env)
 * and database-free by contract: the master answers from its own runtime state and writes the
 * node's refusal row, so the CLI process has nothing to read or write.
 */
class TableTestRefuseCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:table:refuse)
     */
    public function getName(): string
    {
        return CliCommands::TABLE_TEST_REFUSE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Refuse the windows of one table, as if they could not be built (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:table:refuse

Description:
  Refuse every window of one browser table on this node, as if the server could not
  build it: the table draws "List unavailable" in place of its rows while the rest of
  the page keeps working, and every refused window writes one error line to the log
  of the worker that tried to build it. The table is named by its wire key. Each call
  sets the whole state: a call without a key takes the refusal off. Refuses on a
  production-like environment.

Usage:
  php cli.php test:table:refuse [<tableKey>]

Examples:
  php cli.php test:table:refuse hilosSecurityStepUp
  php cli.php test:table:refuse
HELP;
    }

    /**
     * Sends the table to refuse to the master and prints the state it wrote.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first names the table to refuse
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line; no key means the refusal is off
        $tableKey = $args[0] ?? '';

        try {
            $result = $this->sendCommand(
                CliCommands::TABLE_TEST_REFUSE,
                [CommandConstants::FIELD_TABLE_KEY => $tableKey],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::TABLE_TEST_REFUSE);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // The master writes the key on every successful reply; a reply without one is an
        // incomplete answer, and printing it would report a refusal nobody set.
        $refused = $reply->payload[CommandConstants::FIELD_TABLE_KEY] ?? null;
        if (!is_string($refused)) {
            echo "Command failed: the reply names no table refusal\n";

            return ExitCode::ERROR;
        }

        echo $refused === ''
            ? "Table refusal off\n"
            : "Table windows refused: {$refused}\n";

        return ExitCode::SUCCESS;
    }
}

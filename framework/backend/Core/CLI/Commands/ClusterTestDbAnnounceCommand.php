<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestDbAnnounceCommand - Announce a database row change to the other nodes (HIL-670)
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env): it raises on
 * this node the DB sync fact a worker raises after writing a row, and lets the ordinary dispatch
 * pass carry it to the mesh. The receiving node reports what arrived through
 * `test:cluster:inspect`.
 *
 * It names a row id that does NOT exist, and that is the point rather than a shortcut: nothing is
 * written to any database, so a node whose copy of the collection is somebody else's settings is
 * not disturbed by the drill. What the stand is checking is that the frame crosses — the stand's
 * nodes carry different schemas, so whether a row lands is not a question it can ask. Whether it
 * lands is settled by unit tests, against a collection whose fullness is known.
 *
 * Database-free by contract: it talks to nothing but the local command socket.
 */
class ClusterTestDbAnnounceCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:db:announce)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_DB_ANNOUNCE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Announce a database row change to the other nodes (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:db:announce

Description:
  Raise the DB sync fact a worker raises after writing a row, and let the dispatch pass
  carry it to the other nodes; each of them reports what arrived through
  test:cluster:inspect. Nothing is written to any database - name a row id that does not
  exist, so the drill cannot disturb a node's own copy of that collection.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:db:announce <collection> <rowId>

Examples:
  php cli.php test:cluster:db:announce settings 999999
HELP;
    }

    /**
     * Sends the announcement request and reports what was raised.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the collection key, then the row id
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked two lines below
        $collection = $args[0] ?? '';
        // external-boundary: the harness's command line, checked on the very next line
        $rowId = $args[1] ?? '';
        if ($collection === '' || $rowId === '') {
            echo "Error: collection and rowId arguments are required\n";
            return ExitCode::ERROR;
        }

        try {
            $reply = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_DB_ANNOUNCE, [
                CommandConstants::FIELD_COLLECTION => $collection,
                CommandConstants::FIELD_ROW_ID => $rowId,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo "Announced {$collection}/{$rowId}\n";

        return ExitCode::SUCCESS;
    }
}

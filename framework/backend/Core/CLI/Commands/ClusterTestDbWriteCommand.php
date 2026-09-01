<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestDbWriteCommand - Write a settings row on one node of the cluster stand (HIL-712)
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env). Together
 * with {@see ClusterTestDbReadCommand} it is what makes "node A writes a row, node B reads it"
 * sayable: the stand carries one schema for every node, so the row this puts in is the same row
 * the other node's copy is about.
 *
 * The write itself is an ordinary one, done by the node's own probe agent through the ordinary
 * settings actions - so the DB sync fact that follows is raised by the framework rather than by
 * this drill, which is the difference between checking the mechanism and checking an imitation
 * of it. Unlike {@see ClusterTestDbAnnounceCommand}, which names a row that exists nowhere and
 * only proves the frame crosses, this one leaves a value behind for somebody to read back.
 *
 * Database-free by contract: the CLI process talks to nothing but the local command socket, and
 * the node it reaches does the database work.
 */
class ClusterTestDbWriteCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:db:write)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_DB_WRITE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Write a settings row on this node of the cluster stand (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:db:write

Description:
  Ask this node's database probe to write a settings row of the schema the whole
  stand shares. The row is written through the ordinary settings actions, so the
  framework raises the DB sync fact off the write itself and the other nodes learn
  their copy has gone stale. Read the value back from another node with
  test:cluster:db:read.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:db:write <key> <value>

Examples:
  php cli.php test:cluster:db:write cluster_probe_value v1
HELP;
    }

    /**
     * Sends the write request and reports what was written.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the settings key, then the value
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked two lines below
        $key = $args[0] ?? '';
        // external-boundary: the harness's command line, checked on the very next line
        $value = $args[1] ?? '';
        if ($key === '' || $value === '') {
            echo "Error: key and value arguments are required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_DB_WRITE, [
                CommandConstants::FIELD_SETTING_KEY => $key,
                CommandConstants::FIELD_SETTING_VALUE => $value,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_DB_WRITE);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo "Wrote {$key}={$value}\n";

        return ExitCode::SUCCESS;
    }
}

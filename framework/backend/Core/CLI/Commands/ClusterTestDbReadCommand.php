<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestDbReadCommand - Read a settings row as one node holds it (HIL-712)
 *
 * The reading half of the pair {@see ClusterTestDbWriteCommand} opens. A test-only driver
 * (extends {@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}, so it refuses
 * on a production-like env).
 *
 * The node answers out of the copy its own process holds, never with a fresh query, and that is
 * the whole point of the command: a value another node wrote can only come back here if the
 * announcement crossed the mesh and this node's copy went stale on purpose (HIL-670). A query
 * would answer correctly while proving nothing but that both nodes can reach the same server.
 * {@see NO_ROW} is printed in place of the value when this node holds no row for the key.
 *
 * Database-free by contract: the CLI process talks to nothing but the local command socket.
 */
class ClusterTestDbReadCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @var string Printed in place of the value when the node holds no row for the key */
    public const string NO_ROW = '(none)';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:db:read)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_DB_READ;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Read a settings row as this node holds it (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:db:read

Description:
  Ask this node's database probe for the settings row it holds under the given key.
  The answer comes out of the node's own copy of the collection rather than out of a
  fresh query, so a value written on another node appears here only once the mesh has
  carried the fact and this node has re-read the row. Prints (none) as the value when
  this node holds no row for the key.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:db:read <key>

Examples:
  php cli.php test:cluster:db:read cluster_probe_value
HELP;
    }

    /**
     * Sends the read request and prints the value the node answered with.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the settings key
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked on the very next line
        $key = $args[0] ?? '';
        if ($key === '') {
            echo "Error: key argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_DB_READ, [
                CommandConstants::FIELD_SETTING_KEY => $key,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_DB_READ);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // external-boundary: the node's reply, rendered for the harness that reads this line
        $value = $reply->payload[CommandConstants::FIELD_SETTING_VALUE] ?? null;
        if (!is_string($value)) {
            // Said in a word rather than as an empty value: "this node holds no row" and "this
            // node holds a row that is empty" are different answers, and the drill turns on it.
            echo "{$key}=" . self::NO_ROW . "\n";

            return ExitCode::SUCCESS;
        }

        echo "{$key}={$value}\n";

        return ExitCode::SUCCESS;
    }
}

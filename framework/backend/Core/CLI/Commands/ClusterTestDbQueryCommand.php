<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/**
 * Queries a stored probe value without refreshing the answering process's copy.
 *
 * Database-free in the CLI process: this node's probe agent reads the value and session.
 */
class ClusterTestDbQueryCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * @return string Fresh probe query command name
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_DB_QUERY;
    }

    /**
     * @return string One-line probe query description
     */
    public function getDescription(): string
    {
        return 'Query a probe row and its application session as JSON (test-only)';
    }

    /**
     * @return string Probe query usage and example
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:db:query

Description:
  Ask this node's database probe to query the stored value under the given key
  without refreshing its local copy. Prints the key, value and application session
  facts as JSON. An absent row has a null value. A session change during the read
  is refused, so the value is never attributed to a different session.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:db:query <key>

Examples:
  php cli.php test:cluster:db:query cluster_probe_value
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the probe key
     * @return int Exit code for the probe query
     * @throws CommandException When the command name is not registered as test-only
     * @throws JsonException When the reply payload cannot be encoded to JSON
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
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_DB_QUERY, [
                CommandConstants::FIELD_SETTING_KEY => $key,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_DB_QUERY);
        }

        $reply = $result->reply;
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo json_encode($reply->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}

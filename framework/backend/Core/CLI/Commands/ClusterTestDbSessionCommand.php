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
 * Inspects the application database session through this node's probe agent.
 *
 * Database-free in the CLI process: only the answering worker reads the session.
 */
class ClusterTestDbSessionCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * @return string Session inspection command name
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_DB_SESSION;
    }

    /**
     * @return string One-line session inspection description
     */
    public function getDescription(): string
    {
        return 'Inspect an application database session as JSON (test-only)';
    }

    /**
     * @return string Session inspection usage and example
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:db:session

Description:
  Ask this node's database probe for the application session bound to a logical
  database. Prints the database name, server member, connection id, wsrep_sync_wait
  and wsrep_dirty_reads as JSON. Unknown database names are refused.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:db:session <database>

Examples:
  php cli.php test:cluster:db:session primary
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the logical database name
     * @return int Exit code for the session inspection
     * @throws CommandException When the command name is not registered as test-only
     * @throws JsonException When the reply payload cannot be encoded to JSON
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked on the very next line
        $database = $args[0] ?? '';
        if ($database === '') {
            echo "Error: database argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_DB_SESSION, [
                CommandConstants::FIELD_DATABASE_NAME => $database,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_DB_SESSION);
        }

        $reply = $result->reply;
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo json_encode($reply->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}

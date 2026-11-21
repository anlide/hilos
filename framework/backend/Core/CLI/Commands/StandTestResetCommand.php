<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\HilosException;
use JsonException;

/**
 * Resets the entire test stand offline across all state kinds.
 *
 * State is reset in a strictly ordered sequence:
 * 1. test:logs:reset — rotation archive, staging and live log files
 * 2. test:backup:reset — backup root and archives
 * 3. test:files:reset — published files directory
 * 4. test:db:reset — database DROP, migrations, and seed files
 *
 * The database reset runs last because database seeding populates test files through the storage
 * door (see Database\Seed).
 *
 * Scope exclusions:
 * - Analytics queue and Change Log journal database are reset inside test:db:reset.
 * - test:throttle:reset is excluded because it requires an active daemon channel.
 * - Temporary upload files in files/tmp are not cleaned.
 */
final class StandTestResetCommand extends TestOnlyCommand implements DatabaseFreeCommand
{
    /**
     * @var list<string> Ordered sequence of stand reset commands.
     *
     * Database reset is last: seeding puts files through the storage door after the directory sweep.
     */
    public const array STEPS = [
        CliCommands::LOGS_TEST_RESET,
        CliCommands::BACKUP_TEST_RESET,
        CliCommands::FILES_TEST_RESET,
        CliCommands::DB_TEST_RESET,
    ];

    /** @var array<string, CommandInterface> Registered CLI commands map */
    private array $commands;

    /**
     * @param array<string, CommandInterface> $commands Registry of available commands
     */
    public function __construct(array $commands)
    {
        $this->commands = $commands;
    }

    public function getName(): string
    {
        return CliCommands::STAND_TEST_RESET;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'runs the offline reset of every kind of stand state, each of which a running daemon would be writing',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: reset the whole test stand (logs, backups, published files, database)';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: test:stand:reset

Description:
  Runs the offline reset of every kind of stand state in sequence: logs,
  backups, published files, and finally the database. Stops at the first
  step that does not succeed. Refuses on a production-like environment, and
  beside a daemon that is answering.

Usage:
  php cli.php test:stand:reset
HELP;
    }

    /**
     * Runs every reset step in order and stops on first failure.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws HilosException When a step command refuses or fails
     * @throws JsonException When a step command cannot encode output
     */
    protected function run(array $options, array $args): int
    {
        echo "\n=== Test stand reset ===\n\n";

        $missing = [];
        foreach (self::STEPS as $stepName) {
            if (!isset($this->commands[$stepName])) {
                $missing[] = $stepName;
            }
        }
        if ($missing !== []) {
            echo 'Cannot run test stand reset: missing command(s) in registry: ' . implode(', ', $missing) . ".\n";

            return ExitCode::CONFIG_ERROR;
        }

        foreach (self::STEPS as $stepName) {
            echo "→ {$stepName}\n";
            $code = $this->commands[$stepName]->execute([], []);
            if ($code !== ExitCode::SUCCESS) {
                echo "\nTest stand reset stopped at {$stepName} with code {$code}.\n";

                return $code;
            }
        }

        echo "\nTest stand reset complete (" . count(self::STEPS) . " step(s)).\n";

        return ExitCode::SUCCESS;
    }
}

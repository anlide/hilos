<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupTestAgeCommand;
use Hilos\Core\CLI\Commands\BackupTestPruneCommand;
use Hilos\Core\CLI\Commands\BackupTestRunScheduleCommand;
use Hilos\Core\CLI\Commands\ClusterNodesCommand;
use Hilos\Core\CLI\Commands\ClusterReloadCommand;
use Hilos\Core\CLI\Commands\ClusterTestInspectCommand;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\ConnectionTestDropCommand;
use Hilos\Core\CLI\Commands\DbSchemaStatusCommand;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\CLI\Commands\DbWaitCommand;
use Hilos\Core\CLI\Commands\HelpCommand;
use Hilos\Core\CLI\Commands\LlmPingCommand;
use Hilos\Core\CLI\Commands\MigrationDownCommand;
use Hilos\Core\CLI\Commands\MigrationRetryCommand;
use Hilos\Core\CLI\Commands\MigrationStatusCommand;
use Hilos\Core\CLI\Commands\MigrationUpCommand;
use Hilos\Core\CLI\Commands\SeedApplyCommand;
use Hilos\Core\CLI\Commands\MonitorCommand;
use Hilos\Core\CLI\Commands\PingCommand;
use Hilos\Core\CLI\Commands\StatusCommand;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\CLI\Commands\VerificationTestExpireCommand;
use Hilos\Database\DatabaseException;

/**
 * CliManager - Main CLI management class.
 *
 * Parses the command name, positional arguments and `--key=value` / `--flag`
 * options from argv, then routes execution to the matching registered command.
 * Each command receives the parsed options and arguments through its execute().
 */
class CliManager
{
    /** @var string Option prefix */
    private const string OPTION_PREFIX = '--';

    /** @var list<string> command line arguments */
    private array $argv;

    /** @var ?string current command name */
    private ?string $command = null;

    /** @var list<string> parsed positional arguments */
    private array $args = [];

    /** @var array<string, mixed> parsed options (--key=value or --flag) */
    private array $options = [];

    /** @var array<string, CommandInterface> Registered commands */
    private array $commands = [];

    /**
     * Initializes CLI manager with command line arguments.
     *
     * @param list<string> $argv Command line arguments (from global $argv)
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        $this->registerCommands();
        $this->parseArguments();
    }

    /**
     * Registers available commands.
     *
     * Initializes command instances and maps them to command names.
     */
    private function registerCommands(): void
    {
        $this->commands[CliCommands::DAEMON_STATUS] = new StatusCommand();
        $this->commands[CliCommands::DAEMON_MONITOR] = new MonitorCommand();
        $this->commands[CliCommands::DAEMON_PING] = new PingCommand();
        $this->commands[CliCommands::CLUSTER_NODES] = new ClusterNodesCommand();
        $this->commands[CliCommands::CLUSTER_RELOAD] = new ClusterReloadCommand();
        $this->commands[CliCommands::CLUSTER_TEST_INSPECT] = new ClusterTestInspectCommand();
        $this->commands[CliCommands::MIGRATION_UP] = new MigrationUpCommand();
        $this->commands[CliCommands::MIGRATION_DOWN] = new MigrationDownCommand();
        $this->commands[CliCommands::MIGRATION_STATUS] = new MigrationStatusCommand();
        $this->commands[CliCommands::MIGRATION_RETRY] = new MigrationRetryCommand();
        $this->commands[CliCommands::SEED_APPLY] = new SeedApplyCommand();
        $this->commands[CliCommands::DB_SCHEMA_STATUS] = new DbSchemaStatusCommand();
        $this->commands[CliCommands::DB_WAIT] = new DbWaitCommand();
        $this->commands[CliCommands::DB_TEST_RESET] = new DbTestResetCommand();
        $this->commands[CliCommands::VERIFICATION_TEST_EXPIRE] = new VerificationTestExpireCommand();
        $this->commands[CliCommands::USER_TEST_SEED] = new UserTestSeedCommand();
        $this->commands[CliCommands::BACKUP_TEST_AGE] = new BackupTestAgeCommand();
        $this->commands[CliCommands::BACKUP_TEST_PRUNE] = new BackupTestPruneCommand();
        $this->commands[CliCommands::BACKUP_TEST_RUN_SCHEDULE] = new BackupTestRunScheduleCommand();
        $this->commands[CliCommands::CONNECTION_TEST_DROP] = new ConnectionTestDropCommand();
        $this->commands[CliCommands::LLM_PING] = new LlmPingCommand();

        $this->registerProjectCommands();

        $this->commands[CliCommands::HELP] = new HelpCommand($this->commands);
    }

    /**
     * Registers project-specific commands.
     *
     * Override point for a project: a CliManager subclass overrides this and calls
     * addCommand() for each project command. The default registers none.
     */
    protected function registerProjectCommands(): void
    {
    }

    /**
     * Registers a command under its own name.
     *
     * @param CommandInterface $command Command to register
     */
    protected function addCommand(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Runs CLI manager.
     *
     * Main entry point for CLI execution. Parses command and routes
     * to appropriate handler. Displays help if no command provided.
     * Handles exceptions and displays user-friendly error messages.
     *
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(): int
    {
        try {
            // Show help if no command provided
            if ($this->command === null) {
                return $this->commands[CliCommands::HELP]->execute($this->options, $this->args);
            }

            // Execute command if registered
            if (isset($this->commands[$this->command])) {
                return $this->commands[$this->command]->execute($this->options, $this->args);
            }

            // Handle unknown command
            return $this->handleUnknownCommand();
        } catch (DatabaseException $e) {
            // Handle database exceptions with detailed error information
            echo "\n✗ Database Error\n";
            echo "Error: {$e->getMessage()}\n";
            
            if ($e->getMysqlErrorCode() > 0) {
                echo "MySQL Error Code: {$e->getMysqlErrorCode()}\n";
                echo "MySQL Error: {$e->getMysqlErrorMessage()}\n";
            }
            
            if ($e->getQuery()) {
                echo "Query: {$e->getQuery()}\n";
            }
            
            echo "\n";
            return ExitCode::ERROR;
        } catch (\Throwable $e) {
            // Handle unexpected errors
            echo "\n✗ Unexpected Error\n";
            echo "Error: {$e->getMessage()}\n";
            echo "File: {$e->getFile()}:{$e->getLine()}\n\n";
            return ExitCode::ERROR;
        }
    }

    /**
     * Parses command line arguments.
     *
     * Extracts command, positional arguments and options from argv.
     * Supports formats:
     * - --key=value (option with value)
     * - --flag (boolean flag)
     * - positional arguments
     */
    private function parseArguments(): void
    {
        // Skip script name (argv[0])
        $args = array_slice($this->argv, 1);

        // First argument is command (if not an option)
        if (count($args) > 0 && !str_starts_with($args[0], self::OPTION_PREFIX)) {
            $this->command = array_shift($args);
        }

        // Parse remaining arguments
        foreach ($args as $arg) {
            if (preg_match('/^' . preg_quote(self::OPTION_PREFIX) . '([^=]+)=(.+)$/', $arg, $matches)) {
                // Option with value: --key=value
                $this->options[$matches[1]] = $matches[2];
            } elseif (preg_match('/^' . preg_quote(self::OPTION_PREFIX) . '(.+)$/', $arg, $matches)) {
                // Boolean flag: --flag
                $this->options[$matches[1]] = true;
            } else {
                // Positional argument
                $this->args[] = $arg;
            }
        }
    }

    /**
     * Handles unknown command.
     *
     * Displays error message and help information for invalid commands.
     *
     * @return int Exit code (1)
     */
    private function handleUnknownCommand(): int
    {
        echo sprintf('Unknown command: %s', $this->command) . "\n";
        $this->commands[CliCommands::HELP]->execute($this->options, $this->args);
        return ExitCode::ERROR;
    }
}

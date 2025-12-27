<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\HelpCommand;
use Hilos\Core\CLI\Commands\MonitorCommand;
use Hilos\Core\CLI\Commands\StatusCommand;
use Hilos\Core\CLI\Commands\MigrationUpCommand;
use Hilos\Core\CLI\Commands\MigrationDownCommand;
use Hilos\Core\CLI\Commands\MigrationStatusCommand;
use Hilos\Core\CLI\Commands\MigrationRetryCommand;
use Hilos\Core\CLI\Commands\DbSchemaStatusCommand;
use Hilos\Core\CLI\Commands\DbEntityDiffCommand;
use Hilos\Core\CLI\Commands\DbEntityFixCommand;
use Hilos\Core\CLI\Commands\DbObjectFixCommand;
use Hilos\Core\CLI\Commands\DbIdeaFixCommand;
use Hilos\Exception\DatabaseException;

/**
 * CliManager - Main CLI management class
 *
 * Handles CLI command parsing, argument processing and command execution.
 * Provides centralized interface for all CLI operations.
 *
 * Example usage in commands:
 * ```php
 * // In executeStatus() or other command methods:
 * $verbose = $this->getOption('verbose', false);
 * $refreshRate = $this->getOption('refresh-rate', 1);
 *
 * if ($verbose) {
 *     echo "Verbose mode enabled\n";
 * }
 * echo "Refresh rate: {$refreshRate}s\n";
 * ```
 *
 * Command line examples:
 * ```bash
 * composer run cli -- daemon:status --verbose --refresh-rate=5
 * composer run cli -- daemon:monitor --refresh-rate=2 --debug
 * ```
 */
class CliManager
{
    /** @var string Option prefix */
    private const string OPTION_PREFIX = '--';

    /** @var array Command arguments */
    private array $argv;

    /** @var ?string Current command */
    private ?string $command = null;

    /** @var array Parsed command arguments */
    private array $args = [];

    /** @var array Parsed options (--key=value or --flag) */
    private array $options = [];

    /** @var array<string, CommandInterface> Registered commands */
    private array $commands = [];

    /**
     * Constructor
     *
     * @param array $argv Command line arguments (from global $argv)
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        $this->registerCommands();
        $this->parseArguments();
    }

    /**
     * Register available commands
     *
     * Initializes command instances and maps them to command names.
     */
    private function registerCommands(): void
    {
        $this->commands[CliCommands::DAEMON_STATUS] = new StatusCommand();
        $this->commands[CliCommands::DAEMON_MONITOR] = new MonitorCommand();
        $this->commands[CliCommands::MIGRATION_UP] = new MigrationUpCommand();
        $this->commands[CliCommands::MIGRATION_DOWN] = new MigrationDownCommand();
        $this->commands[CliCommands::MIGRATION_STATUS] = new MigrationStatusCommand();
        $this->commands[CliCommands::MIGRATION_RETRY] = new MigrationRetryCommand();
        $this->commands[CliCommands::DB_SCHEMA_STATUS] = new DbSchemaStatusCommand();
        $this->commands[CliCommands::DB_ENTITY_DIFF] = new DbEntityDiffCommand();
        $this->commands[CliCommands::DB_ENTITY_FIX] = new DbEntityFixCommand();
        $this->commands[CliCommands::DB_OBJECT_FIX] = new DbObjectFixCommand();
        $this->commands[CliCommands::DB_IDEA_FIX] = new DbIdeaFixCommand();
        $this->commands[CliCommands::HELP] = new HelpCommand($this->commands);
    }

    /**
     * Run CLI manager
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
     * Parse command line arguments
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
     * Get option value
     *
     * @param string $name Option name
     * @param mixed $default Default value if option not set
     * @return mixed Option value or default
     */
    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Check if option exists
     *
     * @param string $name Option name
     * @return bool True if option is set
     */
    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Get positional arguments
     *
     * @return array Array of positional arguments
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Handle unknown command
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

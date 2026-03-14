<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;

/**
 * HelpCommand - Display help information.
 *
 * Shows available commands and usage information for CLI interface.
 */
class HelpCommand implements CommandInterface
{
    /** @var array<string, CommandInterface> */
    private array $commands;

    /**
     * Creates help command with registered commands map.
     *
     * @param array<string, CommandInterface> $commands All registered commands
     */
    public function __construct(array $commands = [])
    {
        $this->commands = $commands;
    }

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. help)
     */
    public function getName(): string
    {
        return CliCommands::HELP;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Show help information about CLI commands';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: help

Description:
  Display help information about available CLI commands.

Usage:
  php cli.php help [command]
  php cli.php [command] --help

Arguments:
  command    Optional: Show detailed help for specific command

Examples:
  php cli.php help
  php cli.php help daemon:status
  php cli.php migration:up --help
HELP;
    }

    /**
     * Execute help command.
     *
     * Displays available commands and usage information.
     *
     * @param array<string, mixed> $options Command options (--help for specific command)
     * @param list<string> $args Positional args (command name for detailed help)
     * @return int Exit code (0)
     */
    public function execute(array $options, array $args): int
    {
        // Check if help for specific command is requested
        if (!empty($args) && isset($this->commands[$args[0]])) {
            return $this->showCommandHelp($args[0]);
        }

        // Show general help
        echo "Hilos CLI - Management Interface\n\n";
        echo "Usage: php cli.php <command> [options]\n\n";
        echo "Available commands:\n\n";
        
        // Group commands by category
        $categories = [
            'Daemon Management' => [
                CliCommands::DAEMON_STATUS,
                CliCommands::DAEMON_MONITOR,
            ],
            'Database Migrations' => [
                CliCommands::MIGRATION_UP,
                CliCommands::MIGRATION_DOWN,
                CliCommands::MIGRATION_STATUS,
                CliCommands::MIGRATION_RETRY,
            ],
            'Database Seeds' => [
                CliCommands::SEED_APPLY,
            ],
            'Database Schema' => [
                CliCommands::DB_SCHEMA_STATUS,
                CliCommands::DB_ENTITY_DIFF,
                CliCommands::DB_ENTITY_FIX,
                CliCommands::DB_OBJECT_FIX,
            ],
            'Help' => [
                CliCommands::HELP,
            ],
        ];

        foreach ($categories as $category => $commandNames) {
            echo "{$category}:\n";
            foreach ($commandNames as $commandName) {
                if (isset($this->commands[$commandName])) {
                    $command = $this->commands[$commandName];
                    $description = $command->getDescription();
                    printf("  %-20s %s\n", $commandName, $description);
                }
            }
            echo "\n";
        }
        
        echo "For detailed help on a specific command:\n";
        echo "  php cli.php help <command>\n";
        echo "  php cli.php <command> --help\n";
        echo "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Shows detailed help for a specific command.
     *
     * @param string $commandName Command name to show help for
     * @return int Exit code (0 on success)
     */
    private function showCommandHelp(string $commandName): int
    {
        if (!isset($this->commands[$commandName])) {
            echo "Unknown command: {$commandName}\n\n";
            return ExitCode::ERROR;
        }

        $command = $this->commands[$commandName];
        
        echo "\n";
        echo $command->getHelp();
        echo "\n";

        return ExitCode::SUCCESS;
    }
}

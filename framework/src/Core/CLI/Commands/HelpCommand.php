<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;

/**
 * HelpCommand - Display help information
 *
 * Shows available commands and usage information for CLI interface.
 */
class HelpCommand implements CommandInterface
{
    /**
     * Execute help command
     *
     * Displays available commands and usage information.
     *
     * @param array $options Command options (unused)
     * @param array $args Positional arguments (unused)
     * @return int Exit code (0)
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos CLI - Management Interface\n\n";
        echo "Usage: php cli.php <command> [options]\n\n";
        echo "Available commands:\n";
        echo "  " . CliCommands::DAEMON_STATUS . "   Show daemon status\n";
        echo "  " . CliCommands::DAEMON_MONITOR . "  Monitor daemon in real-time\n";
        echo "  " . CliCommands::HELP . "            Show this help message\n";
        echo "\n";
        echo "Options:\n";
        echo "  --option=value  Pass option with value\n";
        echo "  --flag          Pass boolean flag\n";
        echo "\n";

        return ExitCode::SUCCESS;
    }
}


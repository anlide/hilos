<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\CliMonitorManager;

/**
 * MonitorCommand - Real-time daemon monitoring.
 *
 * Starts real-time monitoring of daemon status.
 * Requires TTY terminal for interactive display.
 */
class MonitorCommand implements CommandInterface
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. daemon:monitor)
     */
    public function getName(): string
    {
        return 'daemon:monitor';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Start real-time monitoring of daemon status';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: daemon:monitor

Description:
  Start real-time monitoring of the Hilos daemon status.
  Displays continuously updating metrics in an interactive terminal interface.

Usage:
  php cli.php daemon:monitor

Requirements:
  - TTY terminal (interactive mode)
  - Running Hilos daemon

Examples:
  php cli.php daemon:monitor
  composer run daemon-monitor

Press Ctrl+C to exit monitoring.
HELP;
    }

    /**
     * Execute monitor command.
     *
     * Starts real-time monitoring of daemon status.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $monitor = new CliMonitorManager();
        $monitor->run();
        return ExitCode::SUCCESS;
    }
}

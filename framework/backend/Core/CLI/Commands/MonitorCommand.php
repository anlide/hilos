<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\CliMonitorManager;

/**
 * MonitorCommand - Real-time daemon monitoring
 *
 * Starts real-time monitoring of daemon status.
 * Requires TTY terminal for interactive display.
 */
class MonitorCommand implements CommandInterface
{
    public function getName(): string
    {
        return 'daemon:monitor';
    }

    public function getDescription(): string
    {
        return 'Start real-time monitoring of daemon status';
    }

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
     * Execute monitor command
     *
     * Starts real-time monitoring of daemon status.
     *
     * @param array $options Command options (unused)
     * @param array $args Positional arguments (unused)
     * @return int Exit code (0 = success, 1 = error)
     */
    public function execute(array $options, array $args): int
    {
        $monitor = new CliMonitorManager();
        $monitor->run();
        return ExitCode::SUCCESS;
    }
}

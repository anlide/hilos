<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\CliMonitorManager;
use Throwable;

/**
 * MonitorCommand - Real-time daemon monitoring
 *
 * Starts real-time monitoring of daemon status.
 * Requires TTY terminal for interactive display.
 */
class MonitorCommand implements CommandInterface
{
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
        try {
            $monitor = new CliMonitorManager();
            $monitor->run();
            return ExitCode::SUCCESS;
        } catch (Throwable $e) {
            echo "Monitor failed: " . $e->getMessage() . "\n";
            return ExitCode::ERROR;
        }
    }
}


<?php

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliInterface;
use Hilos\Utils\Constants\CliConstants;

/**
 * MonitorCommand - команда мониторинга daemon в реальном времени
 */
class MonitorCommand
{
    private CliInterface $cli;

    public function __construct()
    {
        $this->cli = new CliInterface();
    }

    /**
     * Execute monitor command
     */
    public function execute(array $args): void
    {
        if (!$this->cli->isDaemonRunning()) {
            echo CliConstants::MSG_DAEMON_NOT_RUNNING . "\n";
            return;
        }

        echo "Monitoring daemon (Press Ctrl+C to exit)...\n";
        echo "==========================================\n\n";

        // Set up signal handler for graceful exit
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }

        $this->monitorLoop();
    }

    /**
     * Main monitoring loop
     */
    private function monitorLoop(): void
    {
        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $status = $this->cli->getDaemonStatus();
            
            if (!$status['running']) {
                echo "Daemon stopped!\n";
                break;
            }

            $this->displayStatus($status);
            sleep(1);
        }
    }

    /**
     * Display current status
     */
    private function displayStatus(array $status): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = $status['pid'];
        $uptime = $status['uptime'] ? $this->formatUptime($status['uptime']) : 'Unknown';
        $memory = $status['memory'] ? $this->formatMemory($status['memory']) : 'Unknown';

        // Clear line and move cursor to beginning
        echo "\r\033[K";
        echo "[$timestamp] PID: $pid | Uptime: $uptime | Memory: $memory";
        flush();
    }

    /**
     * Format uptime in human readable format
     */
    private function formatUptime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Format memory in human readable format
     */
    private function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Handle signal for graceful exit
     */
    public function handleSignal(int $signal): void
    {
        echo "\n\nMonitoring stopped.\n";
        exit(0);
    }
}

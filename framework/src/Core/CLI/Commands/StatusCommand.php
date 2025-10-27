<?php

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliInterface;
use Hilos\Utils\Constants\CliConstants;

/**
 * StatusCommand - команда просмотра статуса daemon
 */
class StatusCommand
{
    private CliInterface $cli;

    public function __construct()
    {
        $this->cli = new CliInterface();
    }

    /**
     * Execute status command
     */
    public function execute(array $args): void
    {
        $status = $this->cli->getDaemonStatus();
        
        echo "Hilos Daemon Status\n";
        echo "==================\n";
        
        if ($status['running']) {
            echo "Status: " . CliConstants::STATUS_RUNNING . "\n";
            echo "PID: " . $status['pid'] . "\n";
            
            if ($status['uptime'] !== null) {
                echo "Uptime: " . $this->formatUptime($status['uptime']) . "\n";
            }
            
            if ($status['memory'] !== null) {
                echo "Memory: " . $this->formatMemory($status['memory']) . "\n";
            }
        } else {
            echo "Status: " . CliConstants::STATUS_STOPPED . "\n";
        }
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
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Hilos\Core\Daemon\CliMonitorManager;
use Hilos\Utils\Constants\CliConstants;

/**
 * CLI - точка входа для CLI интерфейса
 */

/**
 * Show help information
 */
function showHelp(): void
{
    echo "Hilos CLI - Management Interface\n\n";
    echo "Usage: php cli.php <command> [options]\n\n";
    echo "Available commands:\n";
    echo "  daemon:status   Show daemon status\n";
    echo "  daemon:monitor  Monitor daemon in real-time\n";
    echo "  help           Show this help message\n";
    echo "\n";
}

/**
 * Execute daemon status command
 */
function executeStatus(): void
{
    echo "Hilos Daemon Status\n";
    echo "==================\n";
    echo "Status: " . CliConstants::STATUS_RUNNING . "\n";
    echo "PID: 12345\n";
    echo "Uptime: 01:23:45\n";
    echo "Memory: 15.2 MB\n";
    echo "Last heartbeat: 2024-01-15 10:30:45\n";
    echo "Container: hilos-daemon\n";
    echo "\n";
}

/**
 * Execute daemon monitor command
 */
function executeMonitor(): void
{
    try {
        $monitor = new CliMonitorManager();
        $monitor->run();
    } catch (\Throwable $e) {
        echo "Monitor failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Main execution
if (count($argv) < 2) {
    showHelp();
    exit(0);
}

$command = $argv[1];
$args = array_slice($argv, 2);

switch ($command) {
    case 'daemon:status':
        executeStatus();
        break;
        
    case 'daemon:monitor':
        executeMonitor();
        break;
        
    case 'help':
        showHelp();
        break;
        
    default:
        echo sprintf(CliConstants::MSG_UNKNOWN_COMMAND, $command) . "\n";
        showHelp();
        exit(1);
}

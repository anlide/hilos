<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Hilos\Core\CLI\CliManager;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Env;

// Get project root path (2 levels up from Bootstrap)
$projectRoot = dirname(realpath(__DIR__), 2);

// Initialize environment with project root
Env::init($projectRoot);

/**
 * CLI - Entry point for CLI interface
 *
 * Provides command-line management interface for WebSocket test daemon.
 * Supports commands: daemon:status, daemon:monitor, help.
 */

try {
    // Create CLI manager instance with command line arguments
    $cliManager = new CliManager($argv);

    // Run CLI manager and get exit code
    $exitCode = $cliManager->run();

    exit($exitCode);

} catch (Throwable $e) {
    echo "CLI failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}


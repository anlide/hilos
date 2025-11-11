<?php

declare(strict_types=1);

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Env;

require_once __DIR__ . '/../../../vendor/autoload.php';

// Initialize environment
Env::init();

/**
 * CLI - Entry point for CLI interface
 *
 * Provides command-line management interface for Hilos daemon.
 * Supports commands: daemon:status, daemon:monitor, help.
 */

try {
    // Create CLI manager instance with command line arguments
    $cliManager = new CliManager($argv);

    // Run CLI manager and get exit code
    $exitCode = $cliManager->run();

    exit($exitCode);

} catch (Throwable $e) {
    Logger::error("CLI failed: " . $e->getMessage());
    exit(ExitCode::ERROR);
}

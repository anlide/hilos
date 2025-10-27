<?php

declare(strict_types=1);

use Hilos\Core\CLI\CliManager;
use Hilos\Utils\Constants\ExitCode;

require_once __DIR__ . '/../../../vendor/autoload.php';

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
    echo "CLI failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

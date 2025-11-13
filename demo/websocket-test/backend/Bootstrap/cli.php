<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Database\Database;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Database\Migration;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Env;

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

/**
 * CLI - Entry point for CLI interface
 *
 * Provides command-line management interface for WebSocket test daemon.
 * Supports commands: daemon:status, daemon:monitor, help.
 */

try {
    // Initialize migration configuration
    Migration::setMigrationListPath(__DIR__ . '/../Database/Migration');
    Migration::setMigrationName('Schema');
    Migration::setRoutinesPath(__DIR__ . '/../Database/Migration/Routines');

    // Initialize database connection and schema
    Database::initialize();

    // Create CLI manager instance with command line arguments
    $cliManager = new CliManager($argv);

    // Run CLI manager and get exit code
    exit($cliManager->run());

} catch (Throwable $e) {
    Logger::error("CLI failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

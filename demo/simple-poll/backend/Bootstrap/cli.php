<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimplePoll\Database\Database;
use Demo\SimplePoll\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Utils\Logger;

/**
 * CLI - Entry point for CLI interface.
 *
 * Provides command-line management interface for the simple-poll demo daemon.
 * Supports commands: daemon:status, daemon:monitor, db:*, help.
 */

try {
    // Project root (demo/simple-poll): .env lives here, not under Bootstrap/
    $projectRoot = dirname(__DIR__, 2);
    Hilos::initEnv($projectRoot);

    // Test CLI commands load tests/.env over the default project .env.
    if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
        Hilos::loadEnv($projectRoot . '/tests/.env');
    }

    // Initialize migration configuration
    Migration::setMigrationListPath(__DIR__ . '/../Database/Migration');
    Migration::setMigrationName('Schema');
    Migration::setRoutinesPath(__DIR__ . '/../Database/Migration/Routines');

    // Initialize seed configuration
    Seed::setSeedPath(__DIR__ . '/../Database/Migration/Seed');

    // Determine which command is being executed.
    // Some DB bootstrap commands need to work before the Hilos context is initialized.
    $command = $argv[1] ?? '';
    $commandsWithoutHilosInit = ['db:wait', 'db:test:reset'];
    $initHilos = !in_array($command, $commandsWithoutHilosInit);

    // Initialize database connection and schema
    Database::initialize(initHilos: $initHilos);

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

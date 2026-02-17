<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Database\Database;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Database\Migration;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Utils\Env;
use Hilos\Utils\Logger;

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

/**
 * Docker Watchdog - Process manager for Docker containers
 *
 * Monitors and manages daemon.php process with automatic restart
 * on failure. Provides graceful shutdown and error handling.
 */

try {
    // Initialize database connection and schema
    // Enable connection retry for Docker startup (MySQL may not be ready yet)
    Database::initialize(retryConnection: true);

    // Initialize migration configuration
    Migration::setMigrationListPath(__DIR__ . '/../Database/Migration');
    Migration::setMigrationName('Schema');
    Migration::setRoutinesPath(__DIR__ . '/../Database/Migration/Routines');

    // Run migrations once on startup
    Migration::initialize();
    $applied = Migration::migrateUp();
    if ($applied > 0) {
        Logger::info("Applied {$applied} migration(s) on startup");
    }

    // Create Docker manager instance
    $dockerManager = new DockerManager();

    // Start watchdog with daemon script
    $dockerManager->runDockerWatchdog(__DIR__ . '/daemon.php');

} catch (CouldNotStartException $e) {
    Logger::error("Docker Watchdog could not start daemon: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::ERROR);
} catch (FailedToGetStatusException $e) {
    Logger::error("Docker Watchdog failed to get daemon status: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::ERROR);
} catch (FailedToSetNonBlockingException $e) {
    Logger::error("Docker Watchdog failed to set non-blocking mode: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::PERMISSION_DENIED);
} catch (DatabaseException $e) {
    Logger::error("Docker migration failed on startup: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::PERMISSION_DENIED);
} catch (Throwable $e) {
    Logger::error("Docker Watchdog failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);

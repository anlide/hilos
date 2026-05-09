<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Utils\Logger;

// Project root (demo/chat): .env lives here, not under Bootstrap/
$projectRoot = dirname(__DIR__, 2);
Hilos::initEnv($projectRoot);

// Test Docker stack should prefer tests/.env over the default project .env.
$appEnv = Hilos::$env[EnvConstants::APP_ENV];
$testEnvPath = $projectRoot . '/tests/.env';
if ($appEnv === 'test' && file_exists($testEnvPath)) {
    Hilos::loadEnv($testEnvPath);
}

/**
 * Docker Watchdog - Process manager for Docker containers.
 *
 * Monitors and manages daemon.php process with automatic restart
 * on failure. Provides graceful shutdown and error handling.
 */

try {
    // Initialize database connection and schema (without Hilos — migrations must run first)
    // Enable connection retry for Docker startup (MySQL may not be ready yet)
    Database::initialize(initHilos: false, retryConnection: true);

    // Initialize migration configuration
    Migration::setMigrationListPath(__DIR__ . '/../Database/Migration');
    Migration::setMigrationName('Schema');
    Migration::setRoutinesPath(__DIR__ . '/../Database/Migration/Routines');

    // Run migrations once on startup (creates tables before Hilos accesses them)
    Migration::initialize();
    $applied = Migration::migrateUp();
    if ($applied > 0) {
        Logger::info("Applied {$applied} migration(s) on startup");
    }

    // Initialize Hilos now that schema is ready
    Hilos::init();

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

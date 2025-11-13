<?php

declare(strict_types=1);

use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Database\Database;
use Hilos\Exception\InvalidScriptPathException;
use Hilos\Exception\Log\LogRotationException;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Env;

require_once __DIR__ . '/../../../vendor/autoload.php';

// Initialize environment
Env::init();

/**
 * Docker Watchdog - Process manager for Docker containers
 *
 * Monitors and manages daemon.php process with automatic restart
 * on failure. Provides graceful shutdown and error handling.
 */

try {
    // Initialize database connection and schema
    Database::initialize();

    // Create Docker manager instance
    $dockerManager = new DockerManager();

    // Start watchdog with daemon script
    $dockerManager->runDockerWatchdog(__DIR__ . '/daemon.php');

} catch (InvalidScriptPathException $e) {
    Logger::error("Docker Watchdog invalid script path: " . $e->getMessage());
    exit(ExitCode::ERROR);
} catch (LogRotationException $e) {
    Logger::error("Docker Watchdog log rotation failed: " . $e->getMessage());
    exit(ExitCode::ERROR);
} catch (CouldNotStartException $e) {
    Logger::error("Docker Watchdog could not start daemon: " . $e->getMessage());
    exit(ExitCode::ERROR);
} catch (FailedToGetStatusException $e) {
    Logger::error("Docker Watchdog failed to get daemon status: " . $e->getMessage());
    exit(ExitCode::ERROR);
} catch (FailedToSetNonBlockingException $e) {
    Logger::error("Docker Watchdog failed to set non-blocking mode: " . $e->getMessage());
    exit(ExitCode::ERROR);
} catch (Throwable $e) {
    Logger::error("Docker Watchdog failed: " . $e->getMessage());
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);

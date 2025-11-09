<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Env;

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

/**
 * Docker Watchdog - Process manager for Docker containers
 *
 * Monitors and manages daemon.php process with automatic restart
 * on failure. Provides graceful shutdown and error handling.
 */

try {
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
} catch (Throwable $e) {
    Logger::error("Docker Watchdog failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


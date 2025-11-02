<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Hilos\Core\Daemon\DockerManager;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Utils\Constants\ExitCode;
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
    echo "Docker Watchdog could not start daemon: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (FailedToGetStatusException $e) {
    echo "Docker Watchdog failed to get daemon status: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (FailedToSetNonBlockingException $e) {
    echo "Docker Watchdog failed to set non-blocking mode: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (Throwable $e) {
    echo "Docker Watchdog failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


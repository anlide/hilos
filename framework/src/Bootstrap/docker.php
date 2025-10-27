<?php

declare(strict_types=1);

use Hilos\Core\Daemon\DockerManager;
use Hilos\Exception\Process\CouldNotStart;
use Hilos\Exception\Process\FailedToGetStatus;
use Hilos\Exception\Process\FailedToSetNonBlocking;
use Hilos\Utils\Constants\ExitCode;

require_once __DIR__ . '/../../../vendor/autoload.php';

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

} catch (CouldNotStart $e) {
    echo "Docker Watchdog could not start daemon: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (FailedToGetStatus $e) {
    echo "Docker Watchdog failed to get daemon status: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (FailedToSetNonBlocking $e) {
    echo "Docker Watchdog failed to set non-blocking mode: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (Throwable $e) {
    echo "Docker Watchdog failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);

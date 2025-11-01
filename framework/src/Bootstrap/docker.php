<?php

declare(strict_types=1);

use Hilos\Core\Daemon\DockerManager;
use Hilos\Exception\InvalidScriptPathException;
use Hilos\Exception\Log\LogRotationException;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Utils\Constants\ExitCode;
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
    // Create Docker manager instance
    $dockerManager = new DockerManager();

    // Start watchdog with daemon script
    $dockerManager->runDockerWatchdog(__DIR__ . '/daemon.php');

} catch (InvalidScriptPathException $e) {
    echo "Docker Watchdog invalid script path: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
} catch (LogRotationException $e) {
    echo "Docker Watchdog log rotation failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
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

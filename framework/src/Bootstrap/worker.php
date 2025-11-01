<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Hilos\Core\Daemon\WorkerManager;
use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Env;

/**
 * Worker Bootstrap - Entry point for worker processes
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts WorkerManager.
 *
 * Note: This is a framework bootstrap. Projects should create their own
 * worker.php bootstrap that extends WorkerManager.
 */

// Initialize environment
Env::init();

try {
    // Parse command line arguments for worker ID
    $workerId = ArgumentHelper::getWorkerId($argv);
} catch (InvalidWorkerIdException $e) {
    echo "Worker bootstrap failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

try {
    // Create anonymous class extending WorkerManager
    // Projects should override this with their own WorkerManager implementation
    $workerManager = new class($workerId) extends WorkerManager {
        /**
         * Worker tick implementation with heartbeat functionality
         *
         * Logs heartbeat message every 5 seconds for health monitoring.
         * Uses millisecond precision for accurate timing.
         */
        protected function tick(): void
        {
            static $lastHeartbeat = 0.0;
            $heartbeatInterval = 5000.0; // 5 seconds

            $currentTime = microtime(true) * 1000;

            // Send heartbeat every 5 seconds with millisecond precision
            if (($currentTime - $lastHeartbeat) >= $heartbeatInterval) {
                $this->logMessage("Worker #{$this->workerId} heartbeat - " . date('Y-m-d H:i:s'));
                $lastHeartbeat = $currentTime;
            }
        }
    };

    // Start worker main loop
    $workerManager->run();
    
} catch (\Throwable $e) {
    echo "Worker #{$workerId} failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


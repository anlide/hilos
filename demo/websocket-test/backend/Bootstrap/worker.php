<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Core\Worker\ChatWorkerManager;
use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Env;

/**
 * Worker Bootstrap - Entry point for worker processes
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts ChatWorkerManager.
 */

// Get project root path (2 levels up from Bootstrap)
$projectRoot = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';

// Initialize environment with project root
Env::init($projectRoot);

try {
    // Parse command line arguments for worker ID
    $workerId = ArgumentHelper::getWorkerId($argv);
} catch (InvalidWorkerIdException $e) {
    echo "Worker bootstrap failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

try {
    // Create worker manager instance
    $workerManager = new ChatWorkerManager($workerId);

    // Start worker main loop
    $workerManager->run();
    
} catch (\Throwable $e) {
    echo "Worker #{$workerId} failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


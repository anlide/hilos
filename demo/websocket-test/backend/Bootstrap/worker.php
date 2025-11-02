<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Core\Daemon\ChatWorkerManager;
use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\ArgumentHelper;

/**
 * Worker Bootstrap - Entry point for worker processes
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts ChatWorkerManager.
 */

// Get project root path (2 levels up from Bootstrap)
$projectRoot = dirname(realpath(__DIR__), 2);

// Initialize environment with project root
Env::init($projectRoot);

try {
    // Parse command line arguments for worker index
    $workerIndex = ArgumentHelper::getWorkerIndex($argv);
} catch (InvalidWorkerIdException $e) {
    echo "Worker bootstrap failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

try {
    // Create worker manager instance
    $workerManager = new ChatWorkerManager($workerIndex, $argv);

    // Start worker main loop
    $workerManager->run();
    
} catch (\Throwable $e) {
    echo "Worker #{$workerIndex} failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


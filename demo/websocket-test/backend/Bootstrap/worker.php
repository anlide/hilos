<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Core\Daemon\ChatWorkerManager;
use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Logging\Logger\Logger;
use Hilos\Utils\Constants\ErrorConstants;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\ArgumentHelper;

/**
 * Worker Bootstrap - Entry point for worker processes
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts ChatWorkerManager.
 */

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

try {
    // Parse command line arguments for worker index
    $workerIndex = ArgumentHelper::getWorkerIndex($argv);
} catch (InvalidWorkerIdException $e) {
    Logger::error("Worker bootstrap failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::INVALID_ARGUMENT);
}

try {
    // Create worker manager instance
    $workerManager = new ChatWorkerManager($workerIndex, $argv);

    // Start worker main loop
    $workerManager->run();
    
} catch (\Throwable $e) {
    Logger::error("Worker #{$workerIndex} failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);


<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Database\Database;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;

/**
 * Worker Bootstrap - Entry point for worker processes
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts ChatWorkerManager.
 */

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

try {
    // Initialize database connection, schema and Hilos context.
    Database::initialize();

    // Parse command line arguments for worker index
    $workerIndex = ArgumentHelper::getWorkerIndex($argv);

    // Create worker manager instance
    $workerManager = new ChatWorkerManager($workerIndex, $argv);

    // Start worker main loop
    $workerManager->run();

} catch (InvalidWorkerIdException $e) {
    Logger::error("Worker bootstrap failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
    ]);
    exit(ExitCode::INVALID_ARGUMENT);
} catch (\Throwable $e) {
    $string = isset($workerIndex) ? "Worker #{$workerIndex} failed: " : "Worker bootstrap failed: ";
    Logger::error($string . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

exit(ExitCode::SUCCESS);

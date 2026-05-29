<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Hilos;
use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Database\Database;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Core\Daemon\Exception\InvalidWorkerIdException;
use Hilos\Utils\Logger;

/**
 * Worker Bootstrap - Entry point for worker processes.
 *
 * Worker processes are started by daemon with --worker-id parameter.
 * Parses worker ID from command line and starts ChatWorkerManager.
 */

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

try {
    // Project root (demo/chat): .env lives here, not under Bootstrap/
    $projectRoot = dirname(__DIR__, 2);
    Hilos::initEnv($projectRoot);

    // Test Docker stack loads tests/.env over the default project .env.
    if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
        Hilos::loadEnv($projectRoot . '/tests/.env');
    }

    // Initialize database connection, schema and Hilos context.
    Database::initialize();
    Hilos::initAnalytics();

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

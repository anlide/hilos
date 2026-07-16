<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for chat demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; ChatWorkerManager hosts this demo's workers.
 */

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: ChatWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
        Hilos::initAnalytics();
    },
    argv: $argv,
);

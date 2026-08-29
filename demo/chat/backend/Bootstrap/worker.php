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

// How much this process writes is the LOG_WRITE_LEVEL env value, overridden by the
// logs.write_level setting; DEBUG is the lowest step of that one scale.

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

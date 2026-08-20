<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Tasks\Core\Daemon\TasksWorkerManager;
use Demo\Tasks\Database\Database;
use Demo\Tasks\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for tasks demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; TasksWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: TasksWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

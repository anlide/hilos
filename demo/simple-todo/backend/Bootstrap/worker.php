<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimpleTodo\Core\Daemon\TodoWorkerManager;
use Demo\SimpleTodo\Database\Database;
use Demo\SimpleTodo\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for simple-todo demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; TodoWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: TodoWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

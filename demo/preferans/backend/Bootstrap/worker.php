<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Preferans\Core\Daemon\PreferansWorkerManager;
use Demo\Preferans\Database\Database;
use Demo\Preferans\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for preferans demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; PreferansWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: PreferansWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

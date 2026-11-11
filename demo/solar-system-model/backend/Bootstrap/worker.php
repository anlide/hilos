<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SolarSystemModel\Core\Daemon\SolarSystemModelWorkerManager;
use Demo\SolarSystemModel\Database\Database;
use Demo\SolarSystemModel\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for Solar System Model demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; SolarSystemModelWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: SolarSystemModelWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

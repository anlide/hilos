<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Pmd\Core\Daemon\PmdWorkerManager;
use Demo\Pmd\Database\Database;
use Demo\Pmd\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for PMD demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; PmdWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: PmdWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\HilosWebsite\Core\Daemon\HilosWebsiteWorkerManager;
use Demo\HilosWebsite\Database\Database;
use Demo\HilosWebsite\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for hilos-website demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; HilosWebsiteWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: HilosWebsiteWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

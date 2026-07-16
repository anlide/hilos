<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Core\Daemon\ClusterWorkerManager;
use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for cluster demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. On a
 * data-plane node these are the workers that host the leader-placed agent. The invariant
 * startup spine lives in WorkerApplication; ClusterWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: ClusterWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

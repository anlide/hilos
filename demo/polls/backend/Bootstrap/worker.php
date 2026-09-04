<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Polls\Core\Daemon\PollsWorkerManager;
use Demo\Polls\Database\Database;
use Demo\Polls\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for polls demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; PollsWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: PollsWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

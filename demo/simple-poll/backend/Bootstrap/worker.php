<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimplePoll\Core\Daemon\PollWorkerManager;
use Demo\SimplePoll\Database\Database;
use Demo\SimplePoll\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for simple-poll demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; PollWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: PollWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

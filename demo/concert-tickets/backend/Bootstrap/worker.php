<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\ConcertTickets\Core\Daemon\ConcertTicketsWorkerManager;
use Demo\ConcertTickets\Database\Database;
use Demo\ConcertTickets\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for concert-tickets demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; ConcertTicketsWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: ConcertTicketsWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

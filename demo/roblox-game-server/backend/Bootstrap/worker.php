<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\RobloxGameServer\Core\Daemon\RobloxGameServerWorkerManager;
use Demo\RobloxGameServer\Database\Database;
use Demo\RobloxGameServer\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for roblox-game-server demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; RobloxGameServerWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: RobloxGameServerWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\BinanceBtcTracker\Core\Daemon\BinanceBtcTrackerWorkerManager;
use Demo\BinanceBtcTracker\Database\Database;
use Demo\BinanceBtcTracker\Tests\Fixtures\Status\StatusHilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for binance-btc-tracker demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; BinanceBtcTrackerWorkerManager hosts this demo's workers.
 */

// Mounted at the ordinary bootstrap path only by docker-compose.e2e-status.yml.
if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('The Status fault fixture requires APP_ENV=test');
}

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: StatusHilos::class,
    workerClass: BinanceBtcTrackerWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize(initHilos: false);
        StatusHilos::init();
    },
    argv: $argv,
);

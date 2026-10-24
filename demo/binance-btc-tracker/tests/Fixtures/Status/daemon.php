<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\BinanceBtcTracker\Core\Daemon\BinanceBtcTrackerDaemonManager;
use Demo\BinanceBtcTracker\Database\Database;
use Demo\BinanceBtcTracker\Tests\Fixtures\Status\StatusHilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the binance-btc-tracker demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; BinanceBtcTrackerDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

// Mounted at the ordinary bootstrap path only by docker-compose.e2e-status.yml.
if (getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('The Status fault fixture requires APP_ENV=test');
}

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: StatusHilos::class,
    daemonClass: BinanceBtcTrackerDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize(initHilos: false);
        StatusHilos::init();
    },
);

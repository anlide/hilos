<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\BinanceBtcTracker\Core\Daemon\BinanceBtcTrackerDaemonManager;
use Demo\BinanceBtcTracker\Database\Database;
use Demo\BinanceBtcTracker\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the binance-btc-tracker demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; BinanceBtcTrackerDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: BinanceBtcTrackerDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

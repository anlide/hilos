<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\OnlineTesting\Core\Daemon\OnlineTestingDaemonManager;
use Demo\OnlineTesting\Database\Database;
use Demo\OnlineTesting\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the online-testing demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; OnlineTestingDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: OnlineTestingDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SolarSystemModel\Core\Daemon\SolarSystemModelDaemonManager;
use Demo\SolarSystemModel\Database\Database;
use Demo\SolarSystemModel\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the Solar System Model demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; SolarSystemModelDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: SolarSystemModelDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

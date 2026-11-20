<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\HilosWebsite\Core\Daemon\HilosWebsiteDaemonManager;
use Demo\HilosWebsite\Database\Database;
use Demo\HilosWebsite\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the hilos-website demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; HilosWebsiteDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: HilosWebsiteDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

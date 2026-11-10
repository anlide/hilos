<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Preferans\Core\Daemon\PreferansDaemonManager;
use Demo\Preferans\Database\Database;
use Demo\Preferans\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the preferans demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; PreferansDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: PreferansDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

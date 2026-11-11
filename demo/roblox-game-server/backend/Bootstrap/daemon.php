<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\RobloxGameServer\Core\Daemon\RobloxGameServerDaemonManager;
use Demo\RobloxGameServer\Database\Database;
use Demo\RobloxGameServer\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the roblox-game-server demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; RobloxGameServerDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: RobloxGameServerDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

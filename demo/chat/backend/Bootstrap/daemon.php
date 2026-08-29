<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Core\Daemon\ChatDaemonManager;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the chat demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; ChatDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

// How much this process writes is the LOG_WRITE_LEVEL env value, overridden by the
// logs.write_level setting; DEBUG is the lowest step of that one scale.

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: ChatDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
        Hilos::initAnalytics();
    },
);

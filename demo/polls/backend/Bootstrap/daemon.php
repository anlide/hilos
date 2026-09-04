<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Polls\Core\Daemon\PollsDaemonManager;
use Demo\Polls\Database\Database;
use Demo\Polls\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the polls demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; PollsDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: PollsDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

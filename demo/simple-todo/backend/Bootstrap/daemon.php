<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimpleTodo\Core\Daemon\TodoDaemonManager;
use Demo\SimpleTodo\Database\Database;
use Demo\SimpleTodo\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the simple-todo demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; TodoDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: TodoDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

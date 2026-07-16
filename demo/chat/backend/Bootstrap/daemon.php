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

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

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

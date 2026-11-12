<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\FileGalleryManager\Core\Daemon\FileGalleryManagerDaemonManager;
use Demo\FileGalleryManager\Database\Database;
use Demo\FileGalleryManager\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the file-gallery-manager demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; FileGalleryManagerDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: FileGalleryManagerDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);

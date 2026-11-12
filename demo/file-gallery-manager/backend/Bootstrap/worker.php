<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\FileGalleryManager\Core\Daemon\FileGalleryManagerWorkerManager;
use Demo\FileGalleryManager\Database\Database;
use Demo\FileGalleryManager\Hilos;
use Hilos\Core\Daemon\WorkerApplication;

/**
 * Worker - Entry point for file-gallery-manager demo worker processes.
 *
 * Worker processes are started by the daemon with a --worker-id parameter. The invariant
 * startup spine lives in WorkerApplication; FileGalleryManagerWorkerManager hosts this demo's workers.
 */

WorkerApplication::run(
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    workerClass: FileGalleryManagerWorkerManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
    argv: $argv,
);

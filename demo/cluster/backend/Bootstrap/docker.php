<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Core\Daemon\DockerApplication;

/**
 * Docker Watchdog - Process manager (PID 1) for a cluster demo node container.
 *
 * Runs migrations against this node's own schema, then supervises daemon.php with
 * automatic restart. Each node has its own schema, so concurrent first boots never race
 * on the settings-table migration. The invariant startup spine lives in DockerApplication.
 */

DockerApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    databaseInit: static fn () => Database::initialize(initHilos: false, retryConnection: true),
);

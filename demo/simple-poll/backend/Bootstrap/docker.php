<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimplePoll\Database\Database;
use Demo\SimplePoll\Hilos;
use Hilos\Core\Daemon\DockerApplication;

/**
 * Docker Watchdog - Process manager (PID 1) for a simple-poll demo container.
 *
 * Monitors and manages daemon.php with automatic restart on failure. The invariant
 * startup spine — env, database connect with retry, schema migration, Hilos init, and
 * watchdog supervision — lives in DockerApplication.
 */

DockerApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    databaseInit: static fn () => Database::initialize(initHilos: false, retryConnection: true),
);

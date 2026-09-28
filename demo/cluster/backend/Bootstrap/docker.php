<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Core\Daemon\DockerApplication;

/**
 * Docker Watchdog - Process manager (PID 1) for a cluster demo node container.
 *
 * Runs migrations against the shared schema of the stand, then supervises daemon.php
 * with automatic restart. The five nodes boot together on one empty schema (HIL-712)
 * and roll it out under the rollout claim in the database: one applies the migrations,
 * the rest wait for it and find the level already there (HIL-1228). The invariant
 * startup spine lives in DockerApplication.
 */

DockerApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    databaseInit: static fn () => Database::initialize(initHilos: false, retryConnection: true),
);

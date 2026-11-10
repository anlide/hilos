<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Preferans\Database\Database;
use Demo\Preferans\Hilos;
use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\CliManager;

/**
 * CLI - Entry point for the preferans demo CLI.
 *
 * Provides command-line management interface for the preferans demo daemon. Supports
 * commands: daemon:status, daemon:monitor, db:*, help. The invariant startup spine lives
 * in CliApplication.
 */

CliApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    cliManagerClass: CliManager::class,
    argv: $argv,
    databaseInit: static fn () => Database::initialize(),
);

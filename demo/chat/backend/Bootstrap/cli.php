<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\CLI\CliApplication;

/**
 * CLI - Entry point for the chat demo CLI.
 *
 * Provides command-line management: daemon:status, daemon:monitor, db:*, help. The
 * invariant startup spine lives in CliApplication; ChatCliManager adds this demo's
 * commands.
 */

CliApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    cliManagerClass: ChatCliManager::class,
    argv: $argv,
    databaseInit: static fn () => Database::initialize(),
);

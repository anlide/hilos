<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\CliManager;

/**
 * CLI - Entry point for the cluster demo CLI.
 *
 * Provides db:*, daemon:status, cluster:test:inspect (test-only), and help. The
 * multi-node harness drives cluster:test:inspect per node over the command channel.
 *
 * cluster:test:inspect only talks to the local daemon's command socket, so it skips the
 * database connect entirely — that lets the harness inspect a NETWORK-PARTITIONED node
 * from inside its own container (which cannot reach MySQL either), as the split-brain
 * scenario needs to confirm the isolated minority stepped down.
 */

CliApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    cliManagerClass: CliManager::class,
    argv: $argv,
    databaseInit: static fn (bool $initHilos) => Database::initialize(initHilos: $initHilos),
    commandsWithoutDb: ['cluster:test:inspect'],
);

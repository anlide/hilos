<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Utils\Logger;

/**
 * CLI - Entry point for the cluster demo CLI.
 *
 * Provides db:*, daemon:status, cluster:test:inspect (test-only), and help. The
 * multi-node harness drives cluster:test:inspect per node over the command channel.
 */

try {
    // Project root (demo/cluster): .env lives here, not under Bootstrap/
    $projectRoot = dirname(__DIR__, 2);
    Hilos::initEnv($projectRoot);

    // Test CLI commands load tests/.env over the default project .env.
    if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
        Hilos::loadEnv($projectRoot . '/tests/.env');
    }

    // Initialize migration configuration
    Migration::setMigrationListPath(__DIR__ . '/../Database/Migration');
    Migration::setMigrationName('Schema');
    Migration::setRoutinesPath(__DIR__ . '/../Database/Migration/Routines');

    // Initialize seed configuration
    Seed::setSeedPath(__DIR__ . '/../Database/Migration/Seed');

    // Some DB bootstrap commands must work before the Hilos context is initialized.
    $command = $argv[1] ?? '';
    $commandsWithoutHilosInit = ['db:wait', 'db:test:reset'];

    // cluster:test:inspect only talks to the local daemon's command socket — no DB.
    // Skipping the DB connect entirely lets the harness inspect a NETWORK-PARTITIONED
    // node from inside its own container (it cannot reach MySQL either), which the
    // split-brain scenario needs to confirm the isolated minority stepped down.
    $commandsWithoutDb = ['cluster:test:inspect'];

    // Initialize database connection and schema (skipped for DB-free commands)
    if (!in_array($command, $commandsWithoutDb, true)) {
        Database::initialize(initHilos: !in_array($command, $commandsWithoutHilosInit, true));
    }

    // Create CLI manager instance with command line arguments
    $cliManager = new CliManager($argv);

    // Run CLI manager and get exit code
    exit($cliManager->run());

} catch (Throwable $e) {
    Logger::error("CLI failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}

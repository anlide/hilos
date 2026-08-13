<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The CLI process spine: the invariant startup sequence every cli.php shares, lifted out
 * of the four near-identical bootstraps into one framework entrypoint.
 *
 * A cli.php collapses to a single {@see run()} call naming its Hilos facade, its CLI
 * manager class, and its database connect. The spine runs the env prelude, configures the
 * migration/seed paths, constructs the manager, connects the database when the command
 * asks for one, and returns its exit code — all under one try/catch that logs and exits
 * ERROR.
 *
 * One gate shapes the database connect, and the command owns it: the manager is built
 * before any connection, then answers whether the named command needs one. A command
 * marked {@see DatabaseFreeCommand} runs with the database untouched — that is what lets
 * db:wait poll a MySQL that is still down, db:test:reset create a database that does not
 * exist yet, and the cluster demo inspect a network-partitioned node that cannot reach
 * MySQL either. An unregistered name skips the connect too, so a typo answers "unknown
 * command" instead of a connection failure.
 */
final class CliApplication
{
    /**
     * Runs the CLI from its thin entrypoint. Terminates the process; never returns.
     *
     * @param string $bootstrapDir Directory containing the Database/Migration tree
     * @param string $projectRoot Project root that holds .env
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param class-string<CliManager> $cliManagerClass CLI manager to construct and run
     * @param list<string> $argv Command-line arguments; $argv[1] is the command name
     * @param callable(): void $databaseInit Database connect, run only for a command that needs one
     * @return never
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        string $cliManagerClass,
        array $argv,
        callable $databaseInit,
    ): void {
        // $argv[1] is the command; null means none was named, which the manager reads as help.
        $command = $argv[1] ?? null;

        try {
            EntrypointPrelude::initEnvironment($hilosClass, $projectRoot);

            Migration::setMigrationListPath($bootstrapDir . '/../Database/Migration');
            Migration::setMigrationName('Schema');
            Migration::setRoutinesPath($bootstrapDir . '/../Database/Migration/Routines');
            Seed::setSeedPath($bootstrapDir . '/../Database/Migration/Seed');

            $cliManager = new $cliManagerClass($argv);

            if ($cliManager->requiresDatabase($command)) {
                $databaseInit();
            }

            exit($cliManager->run());
        } catch (Throwable $e) {
            Logger::error('CLI failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }
    }
}

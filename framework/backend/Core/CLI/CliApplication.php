<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * The CLI process spine: the invariant startup sequence every cli.php shares, lifted out
 * of the four near-identical bootstraps into one framework entrypoint.
 *
 * A cli.php collapses to a single {@see run()} call naming its Hilos facade, its CLI
 * manager class, and its database connect. The spine runs the env prelude, configures the
 * migration/seed paths, connects the database under the command gating the bootstraps
 * carried, constructs the manager, and returns its exit code — all under one try/catch
 * that logs and exits ERROR.
 *
 * Two gates shape the database connect. Some bootstrap commands must run before the Hilos
 * context exists (they prepare the very schema Hilos reads), so they connect with Hilos
 * init skipped. Other commands talk only to the daemon's command socket and need no
 * database at all, so a caller may name them to skip the connect entirely — the cluster
 * demo uses this to inspect a network-partitioned node that cannot reach MySQL.
 */
final class CliApplication
{
    /** @var list<string> Commands that prepare the schema and so connect with Hilos init skipped. */
    private const array COMMANDS_WITHOUT_HILOS_INIT = ['db:wait', 'db:test:reset'];

    /**
     * Runs the CLI from its thin entrypoint. Terminates the process; never returns.
     *
     * @param string $bootstrapDir Directory containing the Database/Migration tree
     * @param string $projectRoot Project root that holds .env
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param class-string<CliManager> $cliManagerClass CLI manager to construct and run
     * @param list<string> $argv Command-line arguments; $argv[1] is the command name
     * @param callable(bool): void $databaseInit Database connect, receiving whether to init the Hilos context
     * @param list<string> $commandsWithoutDb Commands that skip the database connect entirely
     * @return never
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        string $cliManagerClass,
        array $argv,
        callable $databaseInit,
        array $commandsWithoutDb = [],
    ): void {
        // $argv[1] is the command; some commands gate how (or whether) the database connects.
        $command = $argv[1] ?? '';

        try {
            EntrypointPrelude::run($hilosClass, $projectRoot, static function () use (
                $bootstrapDir,
                $command,
                $databaseInit,
                $commandsWithoutDb,
            ): void {
                Migration::setMigrationListPath($bootstrapDir . '/../Database/Migration');
                Migration::setMigrationName('Schema');
                Migration::setRoutinesPath($bootstrapDir . '/../Database/Migration/Routines');
                Seed::setSeedPath($bootstrapDir . '/../Database/Migration/Seed');

                if (in_array($command, $commandsWithoutDb, true)) {
                    return;
                }

                $databaseInit(!in_array($command, self::COMMANDS_WITHOUT_HILOS_INIT, true));
            });

            $cliManager = new $cliManagerClass($argv);

            exit($cliManager->run());
        } catch (\Throwable $e) {
            Logger::error('CLI failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }
    }
}

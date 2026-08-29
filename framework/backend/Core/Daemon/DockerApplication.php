<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Hilos;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The docker watchdog spine: the invariant startup sequence every docker.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A docker.php collapses to a single {@see run()} call naming its Hilos facade and its
 * database connect. The spine runs the env prelude, connects the database (with retry, as
 * MySQL may still be starting), applies schema migrations before Hilos touches any table,
 * initializes the Hilos context, and supervises daemon.php through {@see DockerManager}.
 * The per-failure exit codes the duplicated bootstraps carried are preserved: a watchdog
 * start/status failure exits ERROR, a non-blocking-mode or migration failure exits
 * PERMISSION_DENIED, any other failure exits ERROR. On a clean return the process exits
 * SUCCESS.
 */
final class DockerApplication
{
    /**
     * Runs the docker watchdog from its thin entrypoint. Terminates the process; never returns.
     *
     * @param string $bootstrapDir Directory containing daemon.php and the Database/Migration tree
     * @param string $projectRoot Project root that holds .env
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param callable(): void $databaseInit Database connect (without Hilos init) run before migrations
     * @return never
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        callable $databaseInit,
    ): void {
        try {
            EntrypointPrelude::run($hilosClass, $projectRoot, static function () use ($bootstrapDir, $hilosClass, $databaseInit): void {
                // Connect the database first; migrations must run before Hilos accesses any table.
                $databaseInit();

                // The schema track is named by the prelude, which every process runs; only the
                // routines are configured here, by the one entrypoint that applies them.
                Migration::setRoutinesPath($bootstrapDir . '/../Database/Migration/Routines');

                // Run migrations once on startup (creates tables before Hilos accesses them).
                Migration::initialize();
                $applied = Migration::migrateUp();
                if ($applied > 0) {
                    Logger::info("Applied {$applied} migration(s) on startup");
                }

                // Initialize Hilos now that the schema is ready.
                $hilosClass::init();
            });

            // Only the error log address: setLogFile() would stop Logger from echoing and
            // leave the container's docker logs empty, which is where a dead node is read first.
            Logger::setErrorLogFile(Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE]);

            // The environment only, and it stays that way: the watchdog receives no frame about
            // a settings edit, so half-obeying the setting would be worse than not obeying it.
            LogWriteLevelApplier::applyFromEnv();

            $dockerManager = new DockerManager();
            $dockerManager->runDockerWatchdog($bootstrapDir . '/daemon.php');
        } catch (CouldNotStartException $e) {
            Logger::error('Docker Watchdog could not start daemon: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
            ]);
            exit(ExitCode::ERROR);
        } catch (FailedToGetStatusException $e) {
            Logger::error('Docker Watchdog failed to get daemon status: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
            ]);
            exit(ExitCode::ERROR);
        } catch (FailedToSetNonBlockingException $e) {
            Logger::error('Docker Watchdog failed to set non-blocking mode: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
            ]);
            exit(ExitCode::PERMISSION_DENIED);
        } catch (DatabaseException $e) {
            Logger::error('Docker migration failed on startup: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
            ]);
            exit(ExitCode::PERMISSION_DENIED);
        } catch (Throwable $e) {
            Logger::error('Docker Watchdog failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }

        exit(ExitCode::SUCCESS);
    }
}

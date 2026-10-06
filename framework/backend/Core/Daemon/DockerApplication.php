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
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Hilos;
use Hilos\Log\DaemonLogAddress;
use Hilos\Log\LogRootOwnershipGuard;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Utils\Exception\LogRootOwnedByAnotherException;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The docker watchdog spine: the invariant startup sequence every docker.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A docker.php collapses to a single {@see run()} call naming its Hilos facade and its
 * database connect. The spine runs the env prelude, connects the database (with retry, as
 * MySQL may still be starting), applies schema migrations before Hilos touches any table,
 * initializes the Hilos context, claims the log directory ({@see LogRootOwnershipGuard}), and
 * supervises daemon.php through {@see DockerManager}. The migrations run under the rollout
 * claim in the database ({@see MigrationClaim}, HIL-1228), so nodes starting together on one
 * database are safe: one rolls the schema out, the rest wait for it. The per-failure exit codes the
 * duplicated bootstraps carried are preserved: a watchdog start/status failure exits ERROR, a
 * non-blocking-mode or migration failure exits PERMISSION_DENIED, any other failure exits
 * ERROR. A log directory another daemon owns exits ERROR too, with the refusal as one line and
 * no trace. On a clean return the process exits SUCCESS.
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
     * @param ?callable(): void $postMigration Project DDL after migrations and before Hilos initialization
     * @return never
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        callable $databaseInit,
        ?callable $postMigration = null,
    ): void {
        try {
            EntrypointPrelude::run($hilosClass, $projectRoot, static function () use (
                $bootstrapDir,
                $hilosClass,
                $databaseInit,
                $postMigration,
            ): void {
                // Connect the database first; migrations must run before Hilos accesses any table.
                $databaseInit();

                // The schema track is named by the prelude, which every process runs; only the
                // routines are configured here, by the one entrypoint that applies them.
                Migration::setRoutinesPath($bootstrapDir . '/../Database/Migration/Routines');

                // Run migrations once on startup (creates tables before Hilos accesses them), under
                // the rollout claim in the database: of the nodes starting together on one database
                // one rolls the schema out and the rest wait for it (HIL-1228).
                Migration::initialize();
                $applied = Migration::migrateUp(holder: MigrationClaimHolder::nodeStart());
                if ($applied > 0) {
                    Logger::info("Applied {$applied} migration(s) on startup");
                }

                if ($postMigration !== null) {
                    $postMigration();
                }

                // Initialize Hilos now that the schema is ready.
                $hilosClass::init();
            });

            // The watchdog is the first to touch the log directory - the startup rotation and the
            // daemon's raw output pair are below - so it claims the directory here, before either
            // of them and before it takes its own error address: a refusal must reach docker logs
            // and leave the directory exactly as it found it. The daemon's own claim comes too late
            // under a watchdog, its stdout/stderr being that raw pair inside the directory
            // (HIL-1130). Asked only when the environment names both the directory and this
            // process: with no address the watchdog touches no directory, and refusing over an
            // unset APP_ENV alone would take from the daemon the whole missing list it names
            // at once (HIL-843).
            if (DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE) !== null
                && isset(Hilos::$env[EnvConstants::APP_ENV])
            ) {
                LogRootOwnershipGuard::claimLogRoot();
            }

            // Only the error log address: setLogFile() would stop Logger from echoing and
            // leave the container's docker logs empty, which is where a dead node is read first.
            // Asked unassertively, because the address may not be set at all: then the watchdog
            // configures nothing and says nothing here, and refusing over that one name belongs to
            // the daemon, which names the whole missing list at once.
            $errorLogFile = DaemonLogAddress::configured(EnvConstants::DAEMON_ERROR_LOG_FILE);
            if ($errorLogFile !== null) {
                Logger::setErrorLogFile($errorLogFile);
            }

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
        } catch (LogRootOwnedByAnotherException $e) {
            // The operator's line, not the author's: the text names both owners and the marker to
            // delete, and a file, a line and a trace would only bury it.
            Logger::error($e->getMessage());
            exit(ExitCode::ERROR);
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

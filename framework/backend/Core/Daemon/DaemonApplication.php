<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Environment\Exception\MissingRequiredEnvironmentException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The daemon process spine: the invariant startup sequence every daemon.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A daemon.php collapses to a single {@see run()} call naming its Hilos facade, its
 * manager class, and its persistence init. The spine runs the env prelude, checks the
 * environment against the project catalog and refuses to start naming every required value
 * that has no answer, points the logger at the daemon log, constructs the manager, hands it
 * a {@see DaemonContext} to compose its servers/routes/modules through
 * {@see DaemonManager::boot()}, and enters the main loop — all under one try/catch that logs
 * and exits ERROR, replacing the four duplicated flat trys. Any failure in env, persistence,
 * composition, or a module means the daemon refuses to start, which is the correct outcome.
 */
final class DaemonApplication
{
    /**
     * Runs a daemon from its thin entrypoint.
     *
     * @param string $bootstrapDir Directory containing daemon.php / worker.php
     * @param string $projectRoot Project root that holds .env and the frontend/ tree
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param class-string<DaemonManager> $daemonClass Daemon manager to construct and run
     * @param callable(): void $persistenceInit Persistence bootstrap (e.g. Database::initialize) run after env is ready
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        string $daemonClass,
        callable $persistenceInit,
    ): void {
        try {
            EntrypointPrelude::run($hilosClass, $projectRoot, $persistenceInit);

            // First of the daemon's own reads, the prelude's persistence init having already
            // connected on the DB_* values. A read-by-touch failure names one variable per
            // launch and says nothing until the code reaches it, so an operator setting up a
            // node learns the list one restart at a time. This runs ahead of setLogFile
            // deliberately - DAEMON_LOG_FILE is itself required and may be one of the missing
            // ones, and a Logger with no file writes to stdout/stderr, which is `docker logs`.
            $missing = Hilos::$env->missingRequired();
            if ($missing !== []) {
                throw MissingRequiredEnvironmentException::forNames($hilosClass, $missing);
            }

            Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);
            Logger::setErrorLogFile(Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE]);

            $manager = new $daemonClass();
            $manager->boot(new DaemonContext($bootstrapDir, $projectRoot));
            $manager->run();
        } catch (Throwable $e) {
            // What reaches this catch is a failure of the startup, and the hard exit is the
            // right answer to it: there is no node yet to announce a departure for, no
            // server to close its clients, no deadline to hold the exit to - and the manager
            // that would do all three may be the very thing that failed to be built. Once
            // the loop is running, a failure no longer comes here: DaemonManager::run()
            // turns it into a requested stop and leaves by the path SIGTERM takes.
            Logger::error('Daemon failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }
    }
}

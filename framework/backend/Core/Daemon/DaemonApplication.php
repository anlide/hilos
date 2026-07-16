<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * The daemon process spine: the invariant startup sequence every daemon.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A daemon.php collapses to a single {@see run()} call naming its Hilos facade, its
 * manager class, and its persistence init. The spine runs the env prelude, points the
 * logger at the daemon log, constructs the manager, hands it a {@see DaemonContext} to
 * compose its servers/routes/modules through {@see DaemonManager::boot()}, and enters the
 * main loop — all under one try/catch that logs and exits ERROR, replacing the four
 * duplicated flat trys. Any failure in env, persistence, composition, or a module means
 * the daemon refuses to start, which is the correct outcome.
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

            Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);

            $manager = new $daemonClass();
            $manager->boot(new DaemonContext($bootstrapDir, $projectRoot));
            $manager->run();
        } catch (\Throwable $e) {
            Logger::error('Daemon failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }
    }
}

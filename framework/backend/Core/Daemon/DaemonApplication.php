<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Backup\Anonymization\AnonymizationStartupGuard;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseGuaranteeStartupGuard;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Database\Schema\MountedCollectionKeyGuard;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\Environment\Exception\MissingRequiredEnvironmentException;
use Hilos\Hilos;
use Hilos\Log\LogRootOwnershipGuard;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\ProtectedMode\SessionStageStartupGuard;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The daemon process spine: the invariant startup sequence every daemon.php shares,
 * lifted out of the four near-identical bootstraps into one framework entrypoint.
 *
 * A daemon.php collapses to a single {@see run()} call naming its Hilos facade, its
 * manager class, and its persistence init. The spine runs the env prelude, checks the
 * environment against the project catalog and refuses to start naming every required value
 * that has no answer, claims the log directory so another daemon cannot share it, points the
 * logger at the daemon log, refuses a framework entity a project extended by halves, refuses a
 * table that does not declare
 * whose set it is part of, refuses a browser connections roster without its session stage, refuses
 * a project that does not state what its database guarantees, lets
 * a node carrying backup refuse a schema it could not anonymize, constructs the manager, hands it
 * a {@see DaemonContext} to
 * compose its servers/routes/modules through {@see DaemonManager::boot()}, and enters the main
 * loop — all under one try/catch that logs and exits ERROR, replacing the four duplicated flat
 * trys. Any failure in env, persistence, composition, or a module means the daemon refuses to
 * start, which is the correct outcome.
 *
 * The process has two codes and no third. The catch owns the one above: a startup that
 * refused exits ERROR. The other comes from the loop below, where the manager reports
 * {@see DaemonDeparture} rather than a number, and this class turns it into one - zero for an
 * ordinary stop, ERROR when there was a failure on the node's way out. Nothing here decides
 * which; the reason carries its own code so that a test can check the mapping.
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
     * @return never
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
            // Every process of a project with analytics collects, the master included until HIL-1156;
            // the framework starts the collector so the project's bootstrap has nothing to forget.
            if ($hilosClass::hasFeature(HilosFeature::ANALYTICS)) {
                $hilosClass::initAnalytics();
            }

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

            // A refusal that the log directory belongs to another daemon cannot be written into
            // that daemon's journal. Logger without a file writes to stdout/stderr — a terminal, or
            // `docker logs` for a daemon run without the watchdog — and that is where this refusal
            // has to land. Under the watchdog the directory was claimed before this process was
            // started, its stdout/stderr being a raw pair inside that directory, so here the claim
            // refreshes the same pair (HIL-1130).
            LogRootOwnershipGuard::claimLogRoot();

            Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]->string());
            Logger::setErrorLogFile(Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE]->string());

            // The environment only: the master is forbidden the database, so it cannot read the
            // setting that overrides this. A worker tells it the real level once one registers,
            // and until then the node's own env is the honest answer.
            LogWriteLevelApplier::applyFromEnv();

            // Before anything composes, and first of the guards: a project's chain under a
            // framework key, inherited by halves, leaves the guards below judging the framework's
            // class where the project's stands - and a column the chain never carried to its
            // Object says nothing until the day somebody opens it. Constants and the mounted map
            // alone, no query.
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            MountedCollectionKeyGuard::assertMountedKeysAgree();

            // Before anything composes: a mounted table that does not say whose set its rows are
            // part of leaves "did all of them arrive" with nobody to answer it. Ahead of the
            // anonymization gate below because it reads constants alone and costs less, and
            // because an unmarked table is the more basic of the two defects.
            SetOwnershipGuard::assertMountedSetsDeclared();

            // Before anything composes: a browser connections roster without its session stage
            // makes the master and worker disagree about protected-mode admission. Ahead of the
            // anonymization gate because it reads the in-memory runtime collection map alone.
            SessionStageStartupGuard::assertRosterCarriesSessions();

            // Before anything composes: every node relies on one logical database that reads back
            // what was written, and a project that does not state both promises is refused here
            // rather than served wrong rows later. The facade's constant alone, ahead of the first
            // guard that asks the live schema.
            DatabaseGuaranteeStartupGuard::assertDeclared($hilosClass);

            // Before anything composes: a node that promises anonymized copies of its database
            // refuses to come up over a schema it could not anonymize. Silent for a project that
            // takes no backup.
            AnonymizationStartupGuard::assertLiveSchemaClassified();

            // A configured change-log database activates a live-column verdict even when
            // this installation offers no backup. Read once, before the manager and loop.
            JournalCoverageGuard::assertMountedTablesPlaced();

            $manager = new $daemonClass();
            $manager->boot(new DaemonContext($bootstrapDir, $projectRoot));
            exit($manager->run()->exitCode());
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

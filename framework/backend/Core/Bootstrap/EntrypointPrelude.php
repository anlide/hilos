<?php

declare(strict_types=1);

namespace Hilos\Core\Bootstrap;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\ProjectTimeZoneStartupGuard;
use Hilos\Environment\ServerTextTimeZoneMode;
use Hilos\Fs\Exception\LfsPointerException;
use Hilos\Fs\FrameworkData;
use Hilos\Fs\FsException;
use Hilos\Hilos;

/**
 * The env-and-persistence prelude shared by every process entrypoint.
 *
 * Runs the invariant startup steps that precede any process-specific work: set the
 * process clock to UTC, initialize the environment accessor from the project root,
 * apply the test-env override when the Docker test stack requests it, name the schema
 * migration track, then — on {@see run()} only — validate the project time zone and
 * server text mode, and run the caller's persistence init. Because the concrete facade
 * determines the env/cluster catalogs (late static binding), the project Hilos subclass
 * is passed in rather than assumed. Reused by the daemon, worker and docker spines; the
 * CLI spine calls {@see initEnvironment()} alone, because it must build its command
 * manager between the env and the connect, and because a wrong zone or text mode must leave
 * the CLI able to repair the env. That path still sets the process to UTC.
 *
 * Before env initialization, refuse a node whose framework data is still in Git LFS
 * pointer form. The CLI skips this startup check so it remains available for repair;
 * reading a data file through FrameworkData still checks that individual file.
 */
final class EntrypointPrelude
{
    /**
     * Sets the process clock to UTC, then validates data, the project zone and the server text mode.
     *
     * UTC is set before the data check, so a refusal still leaves this process on UTC. The project
     * zone and server text mode are judged after .env and the test override, before persistence:
     * an invalid value does not open the database. Neither the container TZ nor the project zone
     * changes the process clock.
     *
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param string $projectRoot Project root that holds .env (and tests/.env under the test stack)
     * @param callable(): void $persistenceInit Persistence bootstrap (e.g. Database::initialize) run after env is ready
     * @throws EnvException When the environment refuses a name the prelude reads, or the test env file is missing
     * @throws EnvInvalidValueException When the project zone or server text mode is invalid
     * @throws LfsPointerException When the framework data contains a Git LFS pointer
     * @throws FsException When the framework data directory or a file cannot be read
     * @throws DatabaseException When framework migration sources are incomplete or invalid
     * @throws IncompleteFeatureActivationException When a declared feature has no definition
     */
    public static function run(string $hilosClass, string $projectRoot, callable $persistenceInit): void
    {
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);
        FrameworkData::assertMaterialized();
        self::initEnvironment($hilosClass, $projectRoot);
        ProjectTimeZoneStartupGuard::assertConfigured();
        ServerTextTimeZoneMode::fromEnv();
        FrameworkMigrationStartupGuard::prepare($hilosClass, $projectRoot);

        $persistenceInit();
    }

    /**
     * Sets the process clock to UTC, then points the migration track at the project's schema directory.
     *
     * The CLI spine calls this without {@see run()}, so the clock is set here as well; a second
     * call from run() changes nothing. This method does not judge the project zone or server text mode: a wrong value
     * must leave the CLI able to repair the env, while PHP on that path is still UTC.
     *
     * The level lives here rather than in the two entrypoints that apply migrations, because
     * knowing which migrations exist is not the same as running them: the restore gate compares
     * an archive's recorded level against the level this code expects, and it answers on the
     * page-serving worker, which applies nothing. Where the level was configured only by the
     * docker and CLI spines, that worker read "this installation lists no migrations" and every
     * archive came back unjudgeable. Applying migrations stays where it was - the routines and
     * seed paths are still set by the entrypoints that use them.
     *
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param string $projectRoot Project root that holds .env (and tests/.env under the test stack)
     * @throws EnvException When the environment refuses APP_ENV, or the test env file is requested and missing
     */
    public static function initEnvironment(string $hilosClass, string $projectRoot): void
    {
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);
        $hilosClass::initEnv($projectRoot);

        // The test Docker stack loads tests/.env over the default project .env. Asked with
        // isset() rather than read outright: APP_ENV is itself a required value, and reading
        // it here would refuse the start over that one name before the daemon's own check
        // gets to name all of them. No APP_ENV means no tests/.env, and the name travels on
        // into that list like any other.
        if (isset(Hilos::$env[EnvConstants::APP_ENV]) && Hilos::$env[EnvConstants::APP_ENV]->string() === 'test') {
            $hilosClass::loadEnv($projectRoot . '/tests/.env');
        }

        Migration::setMigrationListPath($projectRoot . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
    }
}

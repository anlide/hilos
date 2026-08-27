<?php

declare(strict_types=1);

namespace Hilos\Core\Bootstrap;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Migration;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/**
 * The env-and-persistence prelude shared by every process entrypoint.
 *
 * Runs the invariant startup steps that precede any process-specific work: initialize
 * the environment accessor from the project root, apply the test-env override when the
 * Docker test stack requests it, name the schema migration track, then run the caller's
 * persistence init. Because the concrete facade determines the env/cluster catalogs (late
 * static binding), the project Hilos subclass is passed in rather than assumed. Reused by
 * the daemon, worker and docker spines; the CLI spine calls {@see initEnvironment()} alone,
 * because it must build its command manager between the env and the connect.
 */
final class EntrypointPrelude
{
    /**
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param string $projectRoot Project root that holds .env (and tests/.env under the test stack)
     * @param callable(): void $persistenceInit Persistence bootstrap (e.g. Database::initialize) run after env is ready
     * @throws EnvInvalidValueException When the test env file is requested but missing
     */
    public static function run(string $hilosClass, string $projectRoot, callable $persistenceInit): void
    {
        self::initEnvironment($hilosClass, $projectRoot);

        $persistenceInit();
    }

    /**
     * Points the migration track at the project's schema directory alongside the env init.
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
     * @throws EnvInvalidValueException When the test env file is requested but missing
     */
    public static function initEnvironment(string $hilosClass, string $projectRoot): void
    {
        $hilosClass::initEnv($projectRoot);

        // The test Docker stack loads tests/.env over the default project .env. Asked with
        // isset() rather than read outright: APP_ENV is itself a required value, and reading
        // it here would refuse the start over that one name before the daemon's own check
        // gets to name all of them. No APP_ENV means no tests/.env, and the name travels on
        // into that list like any other.
        if (isset(Hilos::$env[EnvConstants::APP_ENV]) && Hilos::$env[EnvConstants::APP_ENV] === 'test') {
            $hilosClass::loadEnv($projectRoot . '/tests/.env');
        }

        Migration::setMigrationListPath($projectRoot . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
    }
}

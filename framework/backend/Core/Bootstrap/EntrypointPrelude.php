<?php

declare(strict_types=1);

namespace Hilos\Core\Bootstrap;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/**
 * The env-and-persistence prelude shared by every process entrypoint.
 *
 * Runs the invariant startup steps that precede any process-specific work: initialize
 * the environment accessor from the project root, apply the test-env override when the
 * Docker test stack requests it, then run the caller's persistence init. Because the
 * concrete facade determines the env/cluster catalogs (late static binding), the project
 * Hilos subclass is passed in rather than assumed. Reused by the daemon spine today and
 * by the worker/cli/docker entrypoints once they adopt it.
 */
final class EntrypointPrelude
{
    /**
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param string $projectRoot Project root that holds .env (and tests/.env under the test stack)
     * @param callable(): void $persistenceInit Persistence bootstrap (e.g. Database::initialize) run after env is ready
     * @throws EnvInvalidValueException When the test env file is requested but missing
     * @throws EnvException When the APP_ENV value cannot be read
     */
    public static function run(string $hilosClass, string $projectRoot, callable $persistenceInit): void
    {
        $hilosClass::initEnv($projectRoot);

        // The test Docker stack loads tests/.env over the default project .env.
        if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
            $hilosClass::loadEnv($projectRoot . '/tests/.env');
        }

        $persistenceInit();
    }
}

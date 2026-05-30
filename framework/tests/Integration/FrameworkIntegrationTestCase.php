<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseConnectionPolicy;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Base class for framework integration tests that use Hilos\Database\Database.
 *
 * Each test method runs with a fresh configure + connect; tearDown closes the connection so static
 * mysqli state in Database does not leak across tests in the same PHP process.
 */
abstract class FrameworkIntegrationTestCase extends TestCase
{
    /**
     * Configures connection index 0 from environment and opens the mysqli link.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__), copyExample: false);
        }

        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        Database::connect(
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            retryOnConnectionError: true,
            maxRetries: DatabaseConnectionPolicy::CONNECT_RETRY_MAX_ATTEMPTS,
            retryDelaySeconds: DatabaseConnectionPolicy::CONNECT_RETRY_DELAY_SECONDS,
        );
    }

    /**
     * Closes connection index 0 when still open.
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseConnectionException;
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
     * @throws DatabaseConnectionException When the server is unreachable or credentials fail.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Database::configure(
            index: 0,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: 'utf8mb4',
        );

        Database::connect(0, retryOnConnectionError: true, maxRetries: 30, retryDelaySeconds: 2);
    }

    /**
     * Closes connection index 0 when still open.
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            Database::close(0);
        }
        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
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
    /** Truth-source id every framework integration case writes under. */
    private const string TEST_AGENT_ID = 'framework-test-agent';

    /**
     * @var list<string> Framework tables a case may write through the service that owns the
     *     step - a code mint, a hold, a session rotation, a durable auth block - rather than
     *     through the library agent that claims them in a running node. The guard asks on
     *     every table since HIL-716, and a test process starts no library, so the harness
     *     claims them for the case exactly as each demo's integration base does.
     */
    private const array CLAIMED_TABLES = [
        HilosDbContext::sessions,
        HilosDbContext::identities,
        HilosDbContext::verifications,
        HilosDbContext::registrationReservations,
        HilosDbContext::passkeyCredentials,
        HilosDbContext::authBlocks,
        HilosDbContext::notifications,
        HilosDbContext::notificationDeliveries,
        HilosDbContext::notificationPreferences,
    ];

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

        foreach (self::CLAIMED_TABLES as $table) {
            TruthSourceRegistry::register($table, true, self::TEST_AGENT_ID);
        }

        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__));
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
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}

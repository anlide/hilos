<?php

declare(strict_types=1);

namespace Demo\Chat\Database;

use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Database as BaseDatabase;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Schema\Schema;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/**
 * Database - Database connection configuration for WebSocket Test Demo.
 *
 * Extends base Database class to provide application-specific configuration.
 * Reads database connection parameters from environment variables.
 */
final class Database extends BaseDatabase
{
    /**
     * Initialize database connections from environment variables.
     *
     * Configures database connection index 0 (primary) from .env file.
     * Additional database connections can be configured here if needed.
     *
     * Environment variables used:
     * - DB_HOST: Database host (default: DatabaseConnectionDefaults::HOST)
     * - DB_PORT: Database port (default: DatabaseConnectionDefaults::PORT)
     * - DB_USERNAME: Database username (default: DatabaseConnectionDefaults::USER)
     * - DB_PASSWORD: Database password (default: empty)
     * - DB_DATABASE: Database name (default: hilos_demo)
     *
     * @param bool $initHilos If true, initialize Hilos with storage.
     *                       Set to false when migrations or DB bootstrap commands must run before Hilos is ready.
     *                       Call Hilos::init() manually after migrations when using false.
     * @param bool $retryConnection If true, retry connection on temporary errors (useful for Docker startup)
     * @throws EnvException When required env variables are missing or invalid
     * @throws DatabaseConnectionException When connect or SET NAMES fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseRuntimeException When SET NAMES query fails
     * @throws DatabaseException When schema initialization fails
     * @throws InvalidTopologyException When Hilos topology constants are inconsistent
     * @throws HilosException When Hilos facade initialization fails
     */
    public static function initialize(bool $initHilos = true, bool $retryConnection = false): void
    {
        // Configure primary database connection (index 0)
        self::configure(
            index: 0,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        // Connect to primary database
        self::connect(0, retryOnConnectionError: $retryConnection);

        self::sql(DatabaseConnectionDefaults::setNamesSql());

        // Initialize database schema structure
        Schema::initialize(0);

        // Initialize Hilos facade with storage (for read-only data access).
        // Hilos depends on Database, so initialize it here.
        // Can be skipped for commands that need to work with broken database context files.
        if ($initHilos) {
            Hilos::init();
        }

        // Additional database connections can be configured here
        // Example for secondary database:
        // self::configure(
        //     index: 1,
        //     host: Hilos::$env[EnvConstants::DB_SECONDARY_HOST],
        //     user: Hilos::$env[EnvConstants::DB_SECONDARY_USERNAME],
        //     password: Hilos::$env[EnvConstants::DB_SECONDARY_PASSWORD],
        //     database: Hilos::$env[EnvConstants::DB_SECONDARY_DATABASE],
        //     port: Hilos::$env->int(EnvConstants::DB_SECONDARY_PORT),
        //     charset: DatabaseConnectionDefaults::CHARSET,
        // );
        // self::connect(1);
        // self::sql(DatabaseConnectionDefaults::setNamesSql());
    }
}

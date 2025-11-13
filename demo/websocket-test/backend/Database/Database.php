<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Database;

use Hilos\Database\Database as BaseDatabase;
use Hilos\Database\Schema\Schema;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Env;

/**
 * Database - Database connection configuration for WebSocket Test Demo
 *
 * Extends base Database class to provide application-specific configuration.
 * Reads database connection parameters from environment variables.
 */
class Database extends BaseDatabase
{
    /**
     * Initialize database connections from environment variables
     *
     * Configures database connection index 0 (primary) from .env file.
     * Additional database connections can be configured here if needed.
     *
     * Environment variables used:
     * - DB_HOST: Database host (default: localhost)
     * - DB_PORT: Database port (default: 3306)
     * - DB_USERNAME: Database username (default: root)
     * - DB_PASSWORD: Database password (default: empty)
     * - DB_DATABASE: Database name (default: hilos_demo)
     *
     * @return void
     * @throws DatabaseException
     * @throws MissingEnvironmentVariableException
     */
    public static function initialize(): void
    {
        // Configure primary database connection (index 0)
        self::configure(
            index: 0,
            host: Env::get('DB_HOST', 'localhost'),
            user: Env::get('DB_USERNAME', 'root'),
            password: Env::get('DB_PASSWORD', ''),
            database: Env::get('DB_DATABASE', 'hilos_demo'),
            port: Env::getInt('DB_PORT', 3306),
            charset: 'utf8mb4'
        );

        // Connect to primary database
        self::connect(0);

        // Set collation (utf8mb4_0900_ai_ci for MySQL 8.0+)
        self::sql("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");

        // Initialize database schema structure
        Schema::initialize(0);

        // Additional database connections can be configured here
        // Example for secondary database:
        // self::configure(
        //     index: 1,
        //     host: Env::get('DB_SECONDARY_HOST', 'localhost'),
        //     user: Env::get('DB_SECONDARY_USERNAME', 'root'),
        //     password: Env::get('DB_SECONDARY_PASSWORD', ''),
        //     database: Env::get('DB_SECONDARY_DATABASE', 'hilos_demo_secondary'),
        //     port: Env::getInt('DB_SECONDARY_PORT', 3306),
        //     charset: 'utf8mb4'
        // );
        // self::connect(1);
        // self::sql("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    }
}

